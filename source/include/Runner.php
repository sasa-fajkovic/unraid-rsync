<?php
/**
 * Runner.php - orchestrates a single job run: state, logging, the SSH transport,
 * preHook -> one rsync per pair -> postHook (ALWAYS), the abort poll, the
 * exit-code -> state mapping, and the persisted last-run summary on /boot.
 *
 * scripts/runner.php is the thin CLI entry that parses --job/--dry-run and calls
 * Runner::run(); ALL the orchestration logic lives here so it is unit-testable
 * with a FAKE rsync and FAKE hooks (no real processes), which is how we assert
 * the ordering preHook -> pairs -> postHook and that postHook fires on the
 * failure/abort path.
 *
 * Injectable seams (set before calling run()):
 *   - Rsync::$runner            the rsync process spawn (fake in tests)
 *   - Runner::$hookRunner       the bash -c hook spawn (fake in tests)
 *   - Runner::$pidProvider      getmypid() seam (fixed pid in tests)
 *
 * SECURITY:
 *   - rsync is built as an argv array via Rsync::buildArgv and run without a
 *     shell.
 *   - pre/post HOOKS are user-authored shell, run via `bash -c "$hook"` as root
 *     BY DESIGN (a documented privilege surface) - this is the ONE intentional
 *     shell use; their output+exit are captured to the run log.
 *   - path guardrails (Job.php) are RE-ENFORCED at runtime before each pair:
 *     a dest of /boot/system/array-or-pool root, or --delete without a specific
 *     sub-dir destination, fails the run before any rsync starts.
 *
 * SUMMARY (read by Phase 6 for the Jobs list, and durable across reboot) is
 * written to /boot at <UR_CONFIG_BASE>/runs/<jobid>.summary.json:
 *   {state, startedAt, finishedAt, exitCode, durationSec, dryRun}
 *
 * NOTIFICATIONS are Phase 7: a clearly-marked hook point (Runner::notifyHook)
 * is left here but does nothing yet.
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Job.php';
require_once __DIR__ . '/Credentials.php';
require_once __DIR__ . '/Ssh.php';
require_once __DIR__ . '/Rsync.php';
require_once __DIR__ . '/RunState.php';
require_once __DIR__ . '/Logger.php';

class Runner
{
    /**
     * Injectable hook runner: fn(string $hookScript, array $env, callable(string):void $onOutput): int
     * Returns the hook's exit code. null => the live runner (bash -c).
     *
     * @var callable|null
     */
    public static $hookRunner = null;

    /**
     * Injectable pid provider: fn(): int. null => getmypid(). Lets tests pin the
     * recorded pid so the state file is deterministic.
     *
     * @var callable|null
     */
    public static $pidProvider = null;

    /** Resolve our own pid (overridable for tests). */
    private static function pid(): int
    {
        if (self::$pidProvider !== null) {
            return (int) (self::$pidProvider)();
        }
        $pid = getmypid();
        return $pid === false ? 0 : (int) $pid;
    }

    /**
     * Run a job end-to-end. Returns a structured result describing the outcome
     * (the CLI maps `state` to an exit code). Does its own logging; throws only
     * for truly unexpected internal failures (the orchestration failures - bad
     * job, sshpass missing, guardrail violation, rsync non-zero - are reported
     * IN the result, not thrown, so postHook still runs and a summary is still
     * written).
     *
     * @param string $jobId
     * @param bool   $dryRun
     * @return array{state:string,exitCode:int,runLog:string,reason?:string}
     */
    public static function run(string $jobId, bool $dryRun = false): array
    {
        // 1. Load config + credentials; resolve the job.
        try {
            $config = Config::load();
        } catch (Throwable $e) {
            return self::hardFail($jobId, 'Could not read configuration: ' . $e->getMessage());
        }

        $job = self::findJob($config, $jobId);
        if ($job === null) {
            return self::hardFail($jobId, "Job not found: $jobId");
        }

        // 2. Concurrency guard.
        if (RunState::isRunning($jobId)) {
            return self::hardFail($jobId, "Job is already running: $jobId", 'already-running');
        }

        // 3. Open the run log + write RunState (running=true). A filesystem
        //    failure here (e.g. the tmpfs runtime dir is not writable) must NOT
        //    escape - it would contradict the contract (no structured result, no
        //    postHook/summary/markStopped). Catch it and hard-fail cleanly.
        $startedAtTs = time();
        $startedAt   = gmdate('Y-m-d\TH:i:s\Z', $startedAtTs);
        try {
            $runLog = Logger::openRun($jobId, $startedAtTs);
            RunState::clearAbort($jobId);
            RunState::write($jobId, [
                'pid'        => self::pid(),
                'running'    => true,
                'dryRun'     => $dryRun,
                'startedAt'  => $startedAt,
                'currentLog' => $runLog,
            ]);
        } catch (Throwable $e) {
            return self::hardFail($jobId, 'Could not initialise run state/log: ' . $e->getMessage());
        }

        Logger::event($runLog, $jobId, sprintf(
            'Run started for job "%s" (%s)%s.',
            (string) ($job['name'] ?? $jobId),
            $jobId,
            $dryRun ? ' [DRY-RUN]' : ''
        ));

        $state    = Rsync::STATE_SUCCESS;
        $exitCode = 0;
        $reason   = '';
        $token    = '';

        try {
            // 4. SSH transport: materialise secrets (LOCAL skips this).
            $sshPieces = null;
            $transport = strtoupper((string) ($job['transport'] ?? 'SSH'));
            if ($transport === 'SSH') {
                $matResult = self::materializeSsh($job, $runLog, $jobId);
                if ($matResult['ok'] !== true) {
                    // Materialisation failure (e.g. sshpass-missing) fails the run.
                    $state    = Rsync::STATE_FAILED;
                    $exitCode = 1;
                    $reason   = (string) $matResult['reason'];
                    Logger::event($runLog, $jobId, 'SSH transport could not be prepared: ' . $matResult['message']);
                } else {
                    $token     = (string) $matResult['token'];
                    $sshPieces = [
                        'dashE'         => (string) $matResult['mat']['dashE'],
                        'sshpassPrefix' => (array) $matResult['mat']['sshpassPrefix'],
                    ];
                }
            }

            // 5. preHook (only if SSH prep didn't already fail).
            $hookEnvBase = self::hookEnv($job, $jobId, $dryRun);
            if ($state !== Rsync::STATE_FAILED) {
                $preHook = (string) ($job['preHook'] ?? '');
                if (trim($preHook) !== '') {
                    Logger::event($runLog, $jobId, 'Running pre-run hook.');
                    $preExit = self::runHook($preHook, $hookEnvBase, $runLog);
                    Logger::event($runLog, $jobId, 'Pre-run hook exited with code ' . $preExit . '.');
                    if ($preExit !== 0) {
                        // A failed preHook fails the run; pairs are skipped, but
                        // postHook STILL runs (the finally-style postHook below).
                        $state    = Rsync::STATE_FAILED;
                        $exitCode = $preExit;
                        $reason   = 'prehook-failed';
                    }
                }
            }

            // 6. Pairs: re-enforce guardrails, check abort, build argv, run rsync.
            if ($state !== Rsync::STATE_FAILED) {
                $global   = (isset($config['global']) && is_array($config['global'])) ? $config['global'] : [];
                $opts     = Rsync::effectiveOptions($job, $global);
                $logLevel = (string) ($job['logLevel'] ?? 'normal');
                $pairs    = (isset($job['pairs']) && is_array($job['pairs'])) ? $job['pairs'] : [];
                // Resolve "user@host" ONCE (not per pair) for SSH operands.
                $userHost = ($transport === 'SSH') ? self::userHost($job) : '';

                if (count($pairs) === 0) {
                    $state    = Rsync::STATE_FAILED;
                    $exitCode = 1;
                    $reason   = 'no-pairs';
                    Logger::event($runLog, $jobId, 'No source -> destination pairs configured; nothing to do.');
                }

                $exitCodes = [];
                $aborted   = false;
                foreach ($pairs as $i => $pair) {
                    $n = (int) $i + 1;

                    // Abort flag is polled BEFORE each pair.
                    if (RunState::abortRequested($jobId)) {
                        $aborted = true;
                        Logger::event($runLog, $jobId, "Abort requested; stopping before pair #$n.");
                        break;
                    }

                    // Resolve src/dest by direction + re-enforce guardrails.
                    $resolved    = self::resolvePair($job, $pair, $transport, $userHost);
                    $guardErrors = self::guardrailErrors($job, $pair, $transport, $opts);
                    if (!empty($guardErrors)) {
                        $state    = Rsync::STATE_FAILED;
                        $exitCode = 1;
                        $reason   = 'guardrail';
                        Logger::event($runLog, $jobId, "Pair #$n rejected by path guardrails: " . implode(' ', $guardErrors));
                        break;
                    }

                    Logger::event($runLog, $jobId, sprintf(
                        'Pair #%d: %s -> %s',
                        $n,
                        $resolved['src'],
                        $resolved['dest']
                    ));

                    $argv = Rsync::buildArgv(
                        $opts,
                        $logLevel,
                        $runLog,
                        $resolved['src'],
                        $resolved['dest'],
                        $sshPieces,
                        $dryRun
                    );

                    $pairExit = Rsync::run($argv, static function (string $chunk) use ($runLog): void {
                        @file_put_contents($runLog, $chunk, FILE_APPEND | LOCK_EX);
                    });
                    $exitCodes[] = $pairExit;
                    Logger::event($runLog, $jobId, "Pair #$n rsync exited with code $pairExit (" . Rsync::exitToState($pairExit) . ').');
                }

                if ($aborted) {
                    $state    = Rsync::STATE_ABORTED;
                    $exitCode = 143; // 128 + SIGTERM
                } elseif ($state !== Rsync::STATE_FAILED) {
                    $worst    = Rsync::worstOutcome($exitCodes);
                    $state    = $worst['state'];
                    $exitCode = $worst['exitCode'];
                }
            }
        } catch (Throwable $e) {
            $state    = Rsync::STATE_FAILED;
            $exitCode = 1;
            $reason   = 'exception';
            Logger::event($runLog, $jobId, 'Run failed with an internal error: ' . $e->getMessage());
        } finally {
            // 7. postHook ALWAYS runs (even on failure/abort), with the outcome
            //    in its environment.
            $postHook = (string) ($job['postHook'] ?? '');
            if (trim($postHook) !== '') {
                $postEnv = self::hookEnv($job, $jobId, $dryRun, $state, $exitCode);
                Logger::event($runLog, $jobId, 'Running post-run hook.');
                $postExit = self::runHook($postHook, $postEnv, $runLog);
                Logger::event($runLog, $jobId, 'Post-run hook exited with code ' . $postExit . '.');
                // A failing postHook does NOT override the run's primary outcome,
                // but we surface it on an otherwise-clean run so it isn't silent.
                if ($postExit !== 0 && $state === Rsync::STATE_SUCCESS) {
                    $state    = Rsync::STATE_WARNING;
                    $reason   = $reason !== '' ? $reason : 'posthook-failed';
                }
            }

            // 8. Cleanup the SSH runtime secrets for THIS run's token.
            if ($token !== '') {
                Ssh::cleanupRuntime($token);
            }

            // 9. Persist the last-run summary to /boot + clear running state.
            $finishedAtTs = time();
            self::writeSummary($jobId, [
                'state'       => $state,
                'startedAt'   => $startedAt,
                'finishedAt'  => gmdate('Y-m-d\TH:i:s\Z', $finishedAtTs),
                'exitCode'    => $exitCode,
                'durationSec' => max(0, $finishedAtTs - $startedAtTs),
                'dryRun'      => $dryRun,
            ]);

            Logger::event($runLog, $jobId, "Run finished: state=$state exitCode=$exitCode.");
            RunState::markStopped($jobId);
            RunState::clearAbort($jobId);

            // Phase 7 hook point - NOT implemented here.
            self::notifyHook($job, $state, $exitCode, $dryRun);
        }

        $result = ['state' => $state, 'exitCode' => $exitCode, 'runLog' => $runLog];
        if ($reason !== '') {
            $result['reason'] = $reason;
        }
        return $result;
    }

    /**
     * PHASE 7 NOTIFICATION HOOK POINT - intentionally a no-op in Phase 4.
     * Phase 7 (Notify.php) will dispatch per the job's notifyMode against the
     * final outcome here. Left as a named seam so the wiring is obvious and the
     * runner's finally block already calls it.
     *
     * @param array<string,mixed> $job
     */
    public static function notifyHook(array $job, string $state, int $exitCode, bool $dryRun): void
    {
        // no-op (Phase 7)
    }

    // --- helpers -------------------------------------------------------------

    /**
     * Find a job by id in a loaded config. Returns null when absent OR when the
     * job is present but DISABLED-by-a-deleted-connection situation is the
     * caller's concern; here we just locate it.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>|null
     */
    public static function findJob(array $config, string $jobId): ?array
    {
        $jobs = (isset($config['jobs']) && is_array($config['jobs'])) ? $config['jobs'] : [];
        foreach ($jobs as $job) {
            if (is_array($job) && (string) ($job['id'] ?? '') === $jobId) {
                return $job;
            }
        }
        return null;
    }

    /**
     * Materialise SSH secrets for a job's connection. Returns a normalised
     * result the run() loop consumes.
     *
     * @param array<string,mixed> $job
     * @return array{ok:bool,reason?:string,message?:string,token?:string,mat?:array<string,mixed>}
     */
    private static function materializeSsh(array $job, string $runLog, string $jobId): array
    {
        $connId = (string) ($job['connectionId'] ?? '');
        if ($connId === '') {
            return ['ok' => false, 'reason' => 'no-connection', 'message' => 'SSH transport selected but no connection is set.'];
        }
        try {
            $creds = Credentials::load();
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'creds-unreadable', 'message' => 'Could not read credentials: ' . $e->getMessage()];
        }
        try {
            $mat = Ssh::materialize($creds, $connId);
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'materialize-failed', 'message' => $e->getMessage()];
        }
        if (empty($mat['ok'])) {
            $msg    = (string) ($mat['error'] ?? 'SSH transport could not be prepared.');
            $reason = (strpos($msg, 'sshpass') !== false) ? 'sshpass-missing' : 'ssh-config';
            return ['ok' => false, 'reason' => $reason, 'message' => $msg];
        }
        return ['ok' => true, 'token' => (string) $mat['token'], 'mat' => $mat];
    }

    /**
     * Resolve a pair's source and destination operands by direction. The stored
     * pair is {local, remote}. For SSH:
     *   PUSH: local -> user@host:remote   (write to remote)
     *   PULL: user@host:remote -> local   (write to local)
     * For LOCAL transport both sides are plain local paths and direction is
     * coerced to PUSH (local -> remote, both on this box).
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $pair
     * @param string              $userHost pre-resolved "user@host" (SSH only)
     * @return array{src:string,dest:string}
     */
    public static function resolvePair(array $job, array $pair, string $transport, string $userHost = ''): array
    {
        $local  = (string) ($pair['local'] ?? '');
        $remote = (string) ($pair['remote'] ?? '');
        $direction = strtoupper((string) ($job['direction'] ?? 'PUSH'));

        if ($transport !== 'SSH') {
            // LOCAL: both are local paths; canonical direction is PUSH.
            return ['src' => $local, 'dest' => $remote];
        }

        // SSH: the remote side is qualified user@host:path.
        $remoteOperand = $userHost . ':' . $remote;

        if ($direction === 'PULL') {
            return ['src' => $remoteOperand, 'dest' => $local];
        }
        return ['src' => $local, 'dest' => $remoteOperand];
    }

    /**
     * Build the "user@host" operand prefix for an SSH pair from the job's
     * connection. Returns '' (so the operand is just ":path") only when the
     * connection can't be resolved - the guardrails/materialisation already fail
     * the run before we get here in that case.
     *
     * @param array<string,mixed> $job
     */
    private static function userHost(array $job): string
    {
        $connId = (string) ($job['connectionId'] ?? '');
        if ($connId === '') {
            return '';
        }
        try {
            $creds = Credentials::load();
        } catch (Throwable $e) {
            return '';
        }
        $conn = Credentials::findConnection($creds, $connId);
        if ($conn === null) {
            return '';
        }
        $conn = Credentials::mergeConnection($conn);
        $user = (string) ($conn['username'] ?? '');
        $host = (string) ($conn['host'] ?? '');
        if ($user === '' || $host === '') {
            return '';
        }
        return $user . '@' . $host;
    }

    /**
     * RE-ENFORCE Job.php's path guardrails for a single pair at runtime (defence
     * in depth: the same checks run on save, but a config could be hand-edited
     * on /boot, so we never trust the stored value). Returns a list of error
     * strings (empty == OK).
     *
     * Mirrors Job::validate's per-pair logic: the `local` field is always a
     * local path; the `remote` field is a local path under LOCAL transport or a
     * non-root remote sub-path under SSH; and when a --delete option is on, the
     * DESTINATION (remote for PUSH, local for PULL) must be a specific sub-dir.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $pair
     * @param array<string,mixed> $opts the EFFECTIVE rsync options for the job
     * @return array<int,string>
     */
    public static function guardrailErrors(array $job, array $pair, string $transport, array $opts): array
    {
        $errors = [];
        $local  = trim((string) ($pair['local'] ?? ''));
        $remote = trim((string) ($pair['remote'] ?? ''));
        $direction = strtoupper((string) ($job['direction'] ?? 'PUSH'));

        // local field: always a local path on this box.
        if ($local === '') {
            $errors[] = 'local path is required.';
        } else {
            foreach (Job::checkLocalPath($local, 'local path') as $e) {
                $errors[] = $e;
            }
        }

        // remote field: local guardrails under LOCAL, otherwise a non-root sub-path.
        if ($remote === '') {
            $errors[] = ($transport === 'LOCAL' ? 'second local' : 'remote') . ' path is required.';
        } elseif ($transport === 'LOCAL') {
            foreach (Job::checkLocalPath($remote, 'second local path') as $e) {
                $errors[] = $e;
            }
        } else {
            foreach (Job::checkRemotePath($remote, 'remote path') as $e) {
                $errors[] = $e;
            }
        }

        // --delete destination must be a specific sub-dir. Determine which side
        // is the destination EXACTLY as resolvePair() does: for LOCAL transport
        // direction is coerced to PUSH (dest = `remote`), so we must not let a
        // hand-edited direction=PULL on a LOCAL job check the wrong side. Only
        // for SSH does PULL flip the destination to the `local` side.
        $deleteOn = !empty($opts['delete']) || !empty($opts['deleteExcluded']);
        if ($deleteOn) {
            $destIsRemote = ($transport !== 'SSH') ? true : ($direction !== 'PULL');
            $destPath = $destIsRemote ? $remote : $local;
            if ($destPath !== '' && !Job::isSpecificSubPath($destPath)) {
                $errors[] = 'a delete option is enabled, so the destination must be a specific sub-directory, not a root.';
            }
        }

        return $errors;
    }

    /**
     * Build the environment array passed to a hook. The post-run hook also gets
     * the outcome (UR_JOB_STATUS, UR_EXIT_CODE); the pre-run hook gets the same
     * keys with empty/0 values so a hook can rely on them existing.
     *
     * @param array<string,mixed> $job
     * @return array<string,string>
     */
    public static function hookEnv(array $job, string $jobId, bool $dryRun, ?string $status = null, ?int $exitCode = null): array
    {
        return [
            'UR_JOB_ID'     => $jobId,
            'UR_JOB_NAME'   => (string) ($job['name'] ?? ''),
            'UR_DRY_RUN'    => $dryRun ? '1' : '0',
            'UR_JOB_STATUS' => $status === null ? '' : $status,
            'UR_EXIT_CODE'  => $exitCode === null ? '' : (string) $exitCode,
        ];
    }

    /**
     * Run a user hook. Delegates to the injectable $hookRunner in tests; the
     * live runner runs `bash -c "$hook"` as root (the documented privilege
     * surface) with the outcome env, streaming output to the run log.
     *
     * @param array<string,string> $env
     */
    private static function runHook(string $hook, array $env, string $runLog): int
    {
        $sink = static function (string $chunk) use ($runLog): void {
            @file_put_contents($runLog, $chunk, FILE_APPEND | LOCK_EX);
        };

        if (self::$hookRunner !== null) {
            return (int) (self::$hookRunner)($hook, $env, $sink);
        }
        return self::defaultHookRun($hook, $env, $sink);
    }

    /**
     * The live hook runner: `bash -c "$hook"` via proc_open (the hook body is a
     * SINGLE argv element passed to bash -c, so the SHELL the user wrote is bash,
     * not us splatting it onto a command line). The job's outcome is exported in
     * the child environment. This is the ONE place the plugin intentionally runs
     * a shell, and only for the user's OWN hook text.
     *
     * @param array<string,string>  $env
     * @param callable(string):void $onOutput
     */
    private static function defaultHookRun(string $hook, array $env, callable $onOutput): int
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        // argv form: bash receives the hook as one argument to -c, so the user's
        // shell is bash and nothing of ours is re-parsed.
        $childEnv = array_merge(self::inheritEnv(), $env);
        $proc = @proc_open(['bash', '-c', $hook], $descriptors, $pipes, null, $childEnv);
        if (!is_resource($proc)) {
            $onOutput("Failed to start hook (bash).\n");
            return 127;
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (is_string($out) && $out !== '') {
            $onOutput($out);
        }
        if (is_string($err) && $err !== '') {
            $onOutput($err);
        }
        return $code < 0 ? 1 : $code;
    }

    /** A minimal inherited environment for hooks (PATH + HOME). */
    private static function inheritEnv(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'TZ'] as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $env[$k] = $v;
            }
        }
        if (!isset($env['PATH'])) {
            $env['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }
        return $env;
    }

    /**
     * Persist the compact last-run summary to /boot (durable across reboot) at
     * <UR_CONFIG_BASE>/runs/<jobid>.summary.json. Atomic (temp + rename).
     *
     * @param array<string,mixed> $summary
     */
    public static function writeSummary(string $jobId, array $summary): void
    {
        $dir = rtrim(UR_CONFIG_BASE, '/') . '/runs';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            // A summary failure must not crash the run; log-best-effort + return.
            return;
        }
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $jobId);
        $clean = ($clean === '' || $clean === null) ? 'unknown' : $clean;
        $path  = $dir . '/' . $clean . '.summary.json';

        $out = [
            'state'       => (string) ($summary['state'] ?? Rsync::STATE_FAILED),
            'startedAt'   => (string) ($summary['startedAt'] ?? ''),
            'finishedAt'  => (string) ($summary['finishedAt'] ?? ''),
            'exitCode'    => (int) ($summary['exitCode'] ?? 1),
            'durationSec' => (int) ($summary['durationSec'] ?? 0),
            'dryRun'      => !empty($summary['dryRun']),
        ];
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $tmp = @tempnam($dir, '.summary.');
        if ($tmp === false) {
            return;
        }
        if (@file_put_contents($tmp, $json . "\n") === false) {
            @unlink($tmp);
            return;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /** Read a job's last-run summary, or null when none exists/unreadable. */
    public static function readSummary(string $jobId): ?array
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $jobId);
        $clean = ($clean === '' || $clean === null) ? 'unknown' : $clean;
        $path  = rtrim(UR_CONFIG_BASE, '/') . '/runs/' . $clean . '.summary.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * A hard, pre-run failure: nothing was started (no state/log to keep), so we
     * just log to the plugin log (best effort) and return a FAILED result. Used
     * for "job not found", "config unreadable", and "already running" (the
     * latter gets its own reason so the handler can 409).
     *
     * @return array{state:string,exitCode:int,runLog:string,reason:string}
     */
    private static function hardFail(string $jobId, string $message, string $reason = 'error'): array
    {
        try {
            Logger::event('', $jobId, 'Run could not start: ' . $message);
        } catch (Throwable $e) {
            // ignore - logging is best-effort on the hard-fail path.
        }
        return [
            'state'    => Rsync::STATE_FAILED,
            'exitCode' => $reason === 'already-running' ? 0 : 1,
            'runLog'   => '',
            'reason'   => $reason,
        ];
    }
}

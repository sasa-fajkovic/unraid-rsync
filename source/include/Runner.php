<?php

declare(strict_types=1);

/**
 * Runner.php - orchestrates a single job run: state, logging, the remote
 * transport (SSH or rsync daemon), preHook -> one rsync per pair -> postHook
 * (ALWAYS), the abort poll, the exit-code -> state mapping, and the persisted
 * last-run summary on /boot.
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
 *   - path guardrails (Job.php) are RE-ENFORCED at runtime for EVERY pair
 *     before the pre-hook (preflight()): a dest of /boot/system/array-or-pool
 *     root, or --delete without a specific sub-dir destination, fails the run
 *     before any rsync starts.
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
require_once __DIR__ . '/ProcIO.php';
require_once __DIR__ . '/RunState.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Notify.php';
require_once __DIR__ . '/History.php';
require_once __DIR__ . '/Util.php';

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
     * Whether the pcntl signal API is actually usable: present AND not blocked by
     * php.ini's disable_functions. function_exists() alone is insufficient - a
     * disabled function is still "defined" but fatals when called.
     */
    private static function pcntlUsable(): bool
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('pcntl_async_signals', $disabled, true)
            && !in_array('pcntl_signal', $disabled, true);
    }

    /**
     * Run a job end-to-end. Returns a structured result describing the outcome
     * (the CLI maps `state` to an exit code). Does its own logging; throws only
     * for truly unexpected internal failures (the orchestration failures - bad
     * job, unusable credentials, guardrail violation, rsync non-zero - are reported
     * IN the result, not thrown, so postHook still runs and a summary is still
     * written).
     *
     * @param string $jobId
     * @param bool   $dryRun
     * @return array{state:string,exitCode:int,runLog:string,reason?:string}
     */
    public static function run(string $jobId, bool $dryRun = false, string $trigger = 'manual'): array
    {
        // How this run was triggered: 'manual' (UI Run/Dry-run) or 'schedule'
        // (cron). Clamped to the closed set so a junk value never propagates into
        // state/summary/history. Orthogonal to $dryRun (dry-vs-real).
        $trigger = ($trigger === 'schedule') ? 'schedule' : 'manual';

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

        // Effective "keep last N executions" retention, derived ONCE from the
        // already-loaded config (no extra disk read) and reused to prune both
        // the tmpfs run logs and the persistent history later in this run.
        $retention = Config::clampRetention($config['global']['retention'] ?? Config::DEFAULT_RETENTION);

        // Persistent log dir: when the user has configured one (an array/pool
        // path under /mnt), point the Logger at it so this run's logs survive a
        // reboot; otherwise leave it null so logs stay in RAM/tmpfs. Derived from
        // the already-loaded config (validated/confined by Config::sanitizeLogDir).
        $logDir = Config::sanitizeLogDir($config['global']['logDir'] ?? '');
        Logger::$logsDirOverride = ($logDir !== '') ? $logDir : null;

        // Secrets dir: when the user has relocated credentials.json to an /mnt
        // array/pool path, point Credentials at it so this run reads keys/passwords
        // from there (with real chmod 600 at rest); else null keeps it on /boot.
        // Derived from the already-loaded config (no extra disk read).
        $secretsDir = Config::sanitizeSecretsDir($config['global']['secretsDir'] ?? '');
        Credentials::$secretsDirOverride = ($secretsDir !== '') ? $secretsDir : null;

        // 2. Concurrency guard. Acquire an ATOMIC per-job lock FIRST so two
        //    near-simultaneous launches can't both pass an isRunning() check and
        //    start duplicate concurrent rsync runs for one job (dangerous with
        //    --delete). isRunning() is still consulted as a secondary, friendlier
        //    signal (and to clear stale state), but the flock is the real guard.
        try {
            $lock = RunState::acquireLock($jobId);
        } catch (Throwable $e) {
            return self::hardFail($jobId, 'Could not acquire run lock: ' . $e->getMessage());
        }
        if ($lock === null) {
            return self::hardFail($jobId, "Job is already running: $jobId", 'already-running');
        }

        // From here the lock is held; every exit path must release it.
        // 3. Open the run log + write RunState (running=true). A filesystem
        //    failure here (e.g. the tmpfs runtime dir is not writable) must NOT
        //    escape - it would contradict the contract (no structured result, no
        //    postHook/summary/markStopped). Catch it and hard-fail cleanly.
        $startedAtTs = time();
        // STORAGE timestamp: UTC, always. Display is system-local (see AGENTS.md
        // "Timezone: store UTC, display system-local") - do NOT switch this to date().
        $startedAt   = gmdate('Y-m-d\TH:i:s\Z', $startedAtTs);
        try {
            $runLog = Logger::openRun($jobId, $startedAtTs);
            // Retention: the user's "keep last N executions" setting bounds BOTH
            // the tmpfs run logs and the persistent history. Apply it to the log
            // pruner here (newest N kept) now that the new run log exists, so
            // tmpfs use stays bounded. Best-effort - a prune failure must never
            // fail the run.
            Logger::$retention = $retention;
            Logger::pruneRuns($jobId);
            RunState::clearAbort($jobId);
            RunState::write($jobId, [
                'pid'        => self::pid(),
                'running'    => true,
                'dryRun'     => $dryRun,
                'trigger'    => $trigger,
                'startedAt'  => $startedAt,
                'currentLog' => $runLog,
            ]);
        } catch (Throwable $e) {
            RunState::releaseLock($lock);
            return self::hardFail($jobId, 'Could not initialise run state/log: ' . $e->getMessage());
        }

        Logger::event($runLog, $jobId, sprintf(
            'Run started for job "%s" (%s)%s [%s].',
            (string) ($job['name'] ?? $jobId),
            $jobId,
            $dryRun ? ' [DRY-RUN]' : '',
            $trigger
        ));
        // Name the zone every stamp below is in. Config::systemTimezone() falls
        // back to UTC when every source misses, which would silently reproduce
        // issue #135 (our stamps UTC, rsync's own lines local) - stating it here,
        // in the same file as the rsync lines to compare against, is what makes
        // that case reportable instead of invisible.
        Logger::event($runLog, $jobId, 'Timestamps in timezone: ' . Config::systemTimezone() . '.');

        // Survive the abort SIGTERM so we can record an ABORTED outcome.
        //
        // WHY: abort SIGTERMs the runner's whole process GROUP (negative pid) to
        // kill the in-flight rsync (and its ssh). That signal also hits THIS
        // runner process - and with the default disposition it would DIE
        // immediately, mid-pair, so the `finally` below never runs: no ABORTED
        // summary, no postHook, and getStatus keeps showing the PREVIOUS run's
        // stale state. By trapping SIGTERM/SIGINT we stay alive: rsync still dies
        // from the same group signal, our blocking read returns, and the per-pair
        // abort check (below) then records state=ABORTED through the normal
        // unwind. The handler also mirrors the request into the abort flag so the
        // between-pairs poll and the post-pair check agree. pcntl is a CLI-only
        // extension; if it is unavailable OR disabled via disable_functions we
        // degrade to the prior behaviour. The handlers + async-signal mode are
        // process-GLOBAL, so we capture the prior async setting and RESTORE the
        // disposition in the finally - otherwise a long-lived worker, or the test
        // process (which calls run() many times), would leak our handlers.
        $signalsInstalled = false;
        $prevAsyncSignals = null;
        if (self::pcntlUsable()) {
            $prevAsyncSignals = pcntl_async_signals(true);
            $onAbortSignal = static function () use ($jobId): void {
                try {
                    RunState::requestAbort($jobId);
                } catch (\Throwable $e) {
                    // Never let a signal handler throw.
                }
            };
            pcntl_signal(defined('SIGTERM') ? SIGTERM : 15, $onAbortSignal);
            pcntl_signal(defined('SIGINT') ? SIGINT : 2, $onAbortSignal);
            $signalsInstalled = true;
        }

        $state    = Rsync::STATE_SUCCESS;
        $exitCode = 0;
        $reason   = '';
        $token    = '';
        // Declared out here so the finally can harvest this run's host key
        // before cleanup; only ever set on the SSH arm.
        $sshMat   = null;

        try {
            // 4. Remote transport: materialise secrets (LOCAL skips this).
            $sshPieces = null;
            // trim() as well as strtoupper(): Job::validate resolves the same
            // two fields the same way, and the two guards must agree on a
            // hand-edited config.json byte for byte.
            $transport = strtoupper(trim((string) ($job['transport'] ?? 'SSH')));
            if ($transport === 'SSH') {
                $matResult = self::materializeSsh($job, $runLog, $jobId);
                if ($matResult['ok'] !== true) {
                    // Materialisation failure fails the run.
                    $state    = Rsync::STATE_FAILED;
                    $exitCode = 1;
                    $reason   = (string) $matResult['reason'];
                    Logger::event($runLog, $jobId, 'SSH transport could not be prepared: ' . $matResult['message']);
                } else {
                    $token     = (string) $matResult['token'];
                    $mat       = $matResult['mat'];
                    $sshMat    = $mat;
                    $sshPieces = [
                        'dashE'  => (string) $mat['dashE'],
                        // PASSWORD auth rides in the child ENVIRONMENT (the
                        // SSH_ASKPASS vars), never in the argv.
                        'sshEnv' => (array) ($mat['sshEnv'] ?? []),
                    ];
                    // F1: arm secret-path redaction BEFORE any captured rsync/ssh
                    // output reaches the log. At `debug` level rsync echoes the
                    // remote-shell command (-e "ssh -i <tmpfs-keypath> ... -p N"),
                    // exposing the per-run tmpfs key/passfile/known_hosts PATHS
                    // into the root-written, browser-visible log. Scrub them (and,
                    // defensively, anything under this run's per-token secret
                    // dirs) on every write until the finally clears it.
                    Logger::setRedaction(
                        [
                            (string) ($mat['keyPath'] ?? ''),
                            (string) ($mat['passFile'] ?? ''),
                            (string) ($mat['knownHosts'] ?? ''),
                        ],
                        Ssh::$runtimeBase,
                        $token
                    );
                }
            } elseif ($transport === 'DAEMON') {
                $matResult = self::materializeDaemonConn($job, $runLog, $jobId);
                if ($matResult['ok'] !== true) {
                    $state    = Rsync::STATE_FAILED;
                    $exitCode = 1;
                    $reason   = (string) $matResult['reason'];
                    Logger::event($runLog, $jobId, 'rsync daemon transport could not be prepared: ' . $matResult['message']);
                } else {
                    $token     = (string) $matResult['token'];
                    $mat       = $matResult['mat'];
                    $sshPieces = [
                        'daemon'       => true,
                        'daemonPort'   => (int) $mat['port'],
                        // '' for an anonymous module (no `auth users` on the
                        // far side): buildArgv then emits no --password-file,
                        // and nothing was written to tmpfs to clean up.
                        'passwordFile' => (string) ($mat['passFile'] ?? ''),
                        // MANDATORY, even though a daemon run carries nothing in
                        // the child environment: the pair loop below passes
                        // $sshPieces['sshEnv'] to Ssh::childEnv(array) UNGUARDED,
                        // so a bag without this key is an undefined-key warning
                        // plus a TypeError that would fail EVERY daemon run with
                        // reason 'exception'. childEnv([]) returns null (inherit).
                        'sshEnv'       => [],
                    ];
                    // Arm redaction BEFORE any captured output exists: rsync
                    // names the password file verbatim in its own errors
                    // (authenticate.c:188/:215), and those reach the log through
                    // its --log-file fd as well as through our sink.
                    // setRedaction ignores empty strings and skips the per-token
                    // dirs when $token === '', so the anonymous case is a no-op.
                    Logger::setRedaction([(string) ($mat['passFile'] ?? '')], Ssh::$runtimeBase, $token);
                }
            }

            $global   = (isset($config['global']) && is_array($config['global'])) ? $config['global'] : [];
            $opts     = Rsync::effectiveOptions($job, $global);
            $pairs    = (isset($job['pairs']) && is_array($job['pairs'])) ? $job['pairs'] : [];
            // Resolve "user@host" ONCE (not per pair) for the remote
            // operands. DAEMON uses the SAME prefix as SSH - the only
            // difference is the '::' separator resolvePair() appends.
            $userHost = in_array($transport, ['SSH', 'DAEMON'], true) ? self::userHost($mat['conn'] ?? []) : '';

            // 5. Defensive rsync presence check. rsync ships in Unraid's base OS
            //    at /usr/bin/rsync, so it should ALWAYS be present; the plugin
            //    does NOT install it. Guard against a broken system: if the
            //    binary is missing, fail the run with a clear logged error
            //    BEFORE the destination checks below need it (postHook still
            //    fires via finally). We do NOT attempt to install anything.
            if ($state !== Rsync::STATE_FAILED && !Rsync::rsyncAvailable()) {
                $state    = Rsync::STATE_FAILED;
                $exitCode = 1;
                $reason   = 'rsync-missing';
                Logger::event($runLog, $jobId, Rsync::rsyncMissingMessage());
            }

            // 5b. Pre-flight, for EVERY pair, before the pre-hook: a pre-hook may
            //     stop containers, and pair 1 must not copy before pair 3 is
            //     rejected.
            if ($state !== Rsync::STATE_FAILED) {
                $preflight = self::preflight($job, $config, $transport, $opts, $sshPieces, $userHost, $runLog, $jobId);
                if ($preflight !== '') {
                    $state    = Rsync::STATE_FAILED;
                    $exitCode = 1;
                    $reason   = $preflight;
                }
            }

            // 5c. preHook (only if nothing above failed).
            $hookEnvBase = self::hookEnv($job, $jobId, $dryRun, null, null, $trigger);
            if ($state !== Rsync::STATE_FAILED) {
                $preHook = (string) ($job['preHook'] ?? '');
                if (trim($preHook) !== '') {
                    $preMin = Config::hookTimeoutMinutes($global, 'preHookTimeout');
                    Logger::event($runLog, $jobId, 'Running pre-run hook.');
                    $preExit = self::runHook(
                        $preHook,
                        $hookEnvBase,
                        $runLog,
                        $preMin * 60,
                        static fn(): bool => RunState::abortRequested($jobId)
                    );
                    if ($preExit === self::HOOK_TIMED_OUT) {
                        Logger::event($runLog, $jobId, "Pre-run hook timed out after $preMin minute(s) and was killed.");
                        $state    = Rsync::STATE_FAILED;
                        $exitCode = 124;
                        $reason   = 'prehook-timeout';
                    } else {
                        Logger::event($runLog, $jobId, 'Pre-run hook exited with code ' . $preExit . '.');
                    }
                    if ($preExit !== 0 && $preExit !== self::HOOK_TIMED_OUT) {
                        // A failed preHook fails the run; pairs are skipped, but
                        // postHook STILL runs (the finally-style postHook below).
                        $state    = Rsync::STATE_FAILED;
                        $exitCode = $preExit;
                        $reason   = 'prehook-failed';
                    }
                }
            }

            // 6. Pairs: check abort, build argv, run rsync.
            if ($state !== Rsync::STATE_FAILED) {
                $logLevel = (string) ($job['logLevel'] ?? 'normal');

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

                    // Resolve src/dest by direction (guardrails ran in pre-flight).
                    $resolved = self::resolvePair($job, $pair, $transport, $userHost);

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

                    // Stream rsync's captured stdout/stderr through Logger::sink,
                    // which REDACTS armed per-run secret paths (F1) and enforces
                    // the per-run-log byte cap (F3) before bytes hit the log.
                    $pairExit = Rsync::run(
                        $argv,
                        Logger::sink($runLog),
                        Ssh::childEnv(is_array($sshPieces) ? $sshPieces['sshEnv'] : [])
                    );
                    // The sink holds back progress redraws (5%/30s throttle) and
                    // any unterminated tail; rsync has exited, so land them.
                    Logger::flushSink($runLog);
                    // rsync ALSO writes the run log directly via --log-file, which
                    // bypasses the sink's cap; trim the file to the cap now that
                    // this pair's rsync has closed --log-file (F3, complete).
                    Logger::enforceRunLogCap($runLog);
                    // Cap FIRST (it bounds the file the redactor is about to
                    // read whole), THEN redact. rsync's own --log-file writes
                    // never pass through Logger::sink, so this is the only pass
                    // that scrubs the per-run secret paths out of them - and it
                    // is safe here precisely because this pair's rsync has
                    // exited and closed that fd.
                    Logger::redactRunLog($runLog);
                    $exitCodes[] = $pairExit;
                    Logger::event($runLog, $jobId, "Pair #$n rsync exited with code $pairExit (" . Rsync::exitToState($pairExit) . ').');

                    // Abort can land DURING a pair (the SIGTERM that killed rsync
                    // also set the abort flag). Re-poll AFTER each rsync so an
                    // interrupted run is recorded as ABORTED rather than as the
                    // killed-rsync exit code (FAILED) - or, worse, leaving a stale
                    // previous-run state when the runner used to die mid-pair.
                    if (RunState::abortRequested($jobId)) {
                        $aborted = true;
                        Logger::event($runLog, $jobId, "Abort requested; stopping after pair #$n.");
                        break;
                    }
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
            // Land whatever the last sink still holds, FIRST. It has to happen
            // before the postHook: runHook() opens a new sink on this same path
            // and Logger::sink() RESETS the per-path state, so a flush placed
            // after the hook would find nothing left to rescue. This is the
            // abort path's safety net - a SIGTERM'd rsync leaves its last
            // redraw unterminated, and the trap lets this finally run.
            Logger::flushSink($runLog);

            // 7. postHook ALWAYS runs (even on failure/abort), with the outcome
            //    in its environment.
            $postHook = (string) ($job['postHook'] ?? '');
            if (trim($postHook) !== '') {
                $postEnv = self::hookEnv($job, $jobId, $dryRun, $state, $exitCode, $trigger);
                $postMin = Config::hookTimeoutMinutes(
                    (isset($config['global']) && is_array($config['global'])) ? $config['global'] : [],
                    'postHookTimeout'
                );
                // The post-hook ALWAYS runs, so an abort that already ended the
                // run must not kill it; only a fresh Abort during it may.
                $abortedBefore = RunState::abortRequested($jobId);
                Logger::event($runLog, $jobId, 'Running post-run hook.');
                $postExit = self::runHook(
                    $postHook,
                    $postEnv,
                    $runLog,
                    $postMin * 60,
                    $abortedBefore ? null : static fn(): bool => RunState::abortRequested($jobId)
                );
                if ($postExit === self::HOOK_TIMED_OUT) {
                    // Keeps the primary outcome, like a failed post-hook.
                    Logger::event($runLog, $jobId, "Post-run hook timed out after $postMin minute(s) and was killed.");
                    $reason = $reason !== '' ? $reason : 'posthook-timeout';
                    if ($state === Rsync::STATE_SUCCESS) {
                        $state = Rsync::STATE_WARNING;
                    }
                } else {
                    Logger::event($runLog, $jobId, 'Post-run hook exited with code ' . $postExit . '.');
                }
                // A failing postHook does NOT override the run's primary outcome,
                // but we surface it on an otherwise-clean run so it isn't silent.
                if ($postExit !== 0 && $postExit !== self::HOOK_TIMED_OUT && $state === Rsync::STATE_SUCCESS) {
                    $state    = Rsync::STATE_WARNING;
                    $reason   = $reason !== '' ? $reason : 'posthook-failed';
                }
            }

            // 7b. Trust on first use: with accept-new and nothing pinned yet, ssh
            //     appended the key it accepted to this run's known_hosts. Pin it
            //     into the connection BEFORE cleanup unlinks that file, so a
            //     CHANGED key fails closed on the next run instead of being
            //     silently trusted again.
            if (is_array($sshMat)) {
                $connId  = (string) ($sshMat['conn']['id'] ?? '');
                $hostKey = Ssh::harvestHostKey($sshMat);
                if (Ssh::pinHostKey($connId, $hostKey)) {
                    // No key material and no tmpfs path in the message.
                    Logger::event($runLog, $jobId, 'Pinned host key for connection ' . $connId . ' on first use.');
                }
            }

            // 8. Cleanup the SSH runtime secrets for THIS run's token, and disarm
            //    the secret-path redaction armed at materialisation (F1).
            if ($token !== '') {
                Ssh::cleanupRuntime($token);
            }
            // And again for the postHook's own sink, before the redact pass so
            // an unterminated last line gets scrubbed like everything else.
            Logger::flushSink($runLog);
            // Final pass, while redaction is still armed: catches anything rsync
            // or the postHook wrote to the run log after the last pair's cap.
            Logger::redactRunLog($runLog);
            Logger::clearRedaction();

            // 9. Persist the last-run summary to /boot + clear running state.
            $finishedAtTs = time();
            // STORAGE timestamp: UTC, always (see the startedAt note above).
            $finishedAt  = gmdate('Y-m-d\TH:i:s\Z', $finishedAtTs);
            $durationSec = max(0, $finishedAtTs - $startedAtTs);
            self::writeSummary($jobId, [
                'state'       => $state,
                'startedAt'   => $startedAt,
                'finishedAt'  => $finishedAt,
                'exitCode'    => $exitCode,
                'durationSec' => $durationSec,
                'dryRun'      => $dryRun,
                'trigger'     => $trigger,
            ]);

            // Append a persistent history record (one small /boot write, same
            // cadence as the summary), then lazily prune to the retention cap.
            // jobName is snapshotted so the record survives a later rename/delete;
            // logRef is the run-log BASENAME only (resolves via getJobLog?run=).
            // Both calls are best-effort and never crash the run.
            History::append($jobId, [
                'startedAt'   => $startedAt,
                'finishedAt'  => $finishedAt,
                'jobName'     => (string) ($job['name'] ?? $jobId),
                'dryRun'      => $dryRun,
                'trigger'     => $trigger,
                'state'       => $state,
                'exitCode'    => $exitCode,
                'durationSec' => $durationSec,
                'logRef'      => Logger::runIdFromPath($runLog),
            ]);
            History::prune($jobId, $retention);

            Logger::event($runLog, $jobId, "Run finished: state=$state exitCode=$exitCode.");
            RunState::markStopped($jobId);
            RunState::clearAbort($jobId);

            // Dispatch the per-job notification (Phase 7). Best-effort: it is
            // gated on the job's notifyMode and NEVER throws (see notifyHook),
            // so a notification failure can't break the run's finally/unwind.
            self::notifyHook($job, $state, $exitCode, $dryRun);

            // Release the per-job run lock last, so a queued launch only proceeds
            // once this run has fully wound down (state cleared, summary written).
            RunState::releaseLock($lock);

            // Restore the signal disposition we changed, so our handlers/async
            // mode never leak past this run (the detached runner exits anyway,
            // but the test process and any future long-lived worker reuse it).
            if ($signalsInstalled) {
                pcntl_signal(defined('SIGTERM') ? SIGTERM : 15, SIG_DFL);
                pcntl_signal(defined('SIGINT') ? SIGINT : 2, SIG_DFL);
                if ($prevAsyncSignals !== null) {
                    pcntl_async_signals((bool) $prevAsyncSignals);
                }
            }
        }

        $result = ['state' => $state, 'exitCode' => $exitCode, 'runLog' => $runLog];
        if ($reason !== '') {
            $result['reason'] = $reason;
        }
        return $result;
    }

    /** notifyMode values (persisted per job in config.json, set in the Jobs form). */
    const NOTIFY_OFF          = 'off';
    const NOTIFY_SUCCESS_ONLY = 'success-only';
    const NOTIFY_FAILURE_ONLY = 'failure-only';
    const NOTIFY_ALWAYS       = 'always';

    /** The event label shown by the webGui notification UI. */
    const NOTIFY_EVENT = 'Unraid Rsync';

    /** Clickable deep-link target (the plugin's Settings page). */
    const NOTIFY_LINK = '/Settings/UnraidRsync';

    /**
     * Dispatch a per-job notification for a TERMINAL run outcome (Phase 7).
     *
     * Called from run()'s `finally` AFTER the /boot summary is written and state
     * is cleared. The signature and call-site are fixed; all dispatch logic lives
     * here. It NEVER throws - any failure is swallowed so a notification problem
     * can't break the run's unwind (a failed notification must never fail the
     * backup).
     *
     * Gating + mapping (no ambiguity):
     *   - dryRun                  => notified just like a real run (subject to the
     *                                same notifyMode gating below), but with a
     *                                "[Dry-run]" marker added to the subject and
     *                                description so it is never mistaken for a real
     *                                backup.
     *   - Classify the state:
     *       isSuccess = state in {SUCCESS, WARNING}
     *       isFailure = state in {FAILED, PARTIAL, TIMEOUT}
     *       ABORTED    = user-initiated (neither success nor failure).
     *   - notifyMode:
     *       off           => never;
     *       success-only  => only when isSuccess;
     *       failure-only  => only when isFailure;
     *       always        => every terminal state including ABORTED.
     *   - importance:
     *       SUCCESS                  => normal
     *       WARNING/PARTIAL/TIMEOUT  => warning
     *       FAILED                   => alert
     *       ABORTED                  => normal
     *
     * @param array<string,mixed> $job
     */
    public static function notifyHook(array $job, string $state, int $exitCode, bool $dryRun): void
    {
        try {
            $mode = self::normalizeNotifyMode((string) ($job['notifyMode'] ?? self::NOTIFY_OFF));
            if (!self::shouldNotify($mode, $state)) {
                return;
            }

            $jobName    = (string) ($job['name'] ?? ($job['id'] ?? 'job'));
            $importance = self::notifyImportance($state);
            // A dry-run notification is clearly marked so it is never mistaken for
            // a real backup.
            $subject    = sprintf('%s: %s %s', self::NOTIFY_EVENT, $jobName, $state)
                        . ($dryRun ? ' [Dry-run]' : '');

            // Duration is read from the just-written /boot summary if available
            // (it is written immediately before this hook in run()'s finally).
            $description = self::notifyDescription($job, $state, $exitCode, $dryRun);

            Notify::send([
                'event'       => self::NOTIFY_EVENT,
                'subject'     => $subject,
                'description' => $description,
                'importance'  => $importance,
                'link'        => self::NOTIFY_LINK,
            ]);
        } catch (Throwable $e) {
            // Best-effort: a notification failure must never break the run.
        }
    }

    /** Coerce a stored notifyMode to a known value; unknown/empty => off. */
    public static function normalizeNotifyMode(string $mode): string
    {
        switch ($mode) {
            case self::NOTIFY_SUCCESS_ONLY:
                return self::NOTIFY_SUCCESS_ONLY;
            case self::NOTIFY_FAILURE_ONLY:
                return self::NOTIFY_FAILURE_ONLY;
            case self::NOTIFY_ALWAYS:
                return self::NOTIFY_ALWAYS;
            case self::NOTIFY_OFF:
            default:
                return self::NOTIFY_OFF;
        }
    }

    /** A run state counts as success when it is SUCCESS or WARNING. */
    public static function isSuccessState(string $state): bool
    {
        return $state === Rsync::STATE_SUCCESS || $state === Rsync::STATE_WARNING;
    }

    /** A run state counts as failure when it is FAILED, PARTIAL or TIMEOUT. */
    public static function isFailureState(string $state): bool
    {
        return $state === Rsync::STATE_FAILED
            || $state === Rsync::STATE_PARTIAL
            || $state === Rsync::STATE_TIMEOUT;
    }

    /**
     * Decide whether a terminal $state should notify under notifyMode $mode.
     * ABORTED is neither success nor failure, so only `always` notifies on it.
     */
    public static function shouldNotify(string $mode, string $state): bool
    {
        switch ($mode) {
            case self::NOTIFY_ALWAYS:
                return true;
            case self::NOTIFY_SUCCESS_ONLY:
                return self::isSuccessState($state);
            case self::NOTIFY_FAILURE_ONLY:
                return self::isFailureState($state);
            case self::NOTIFY_OFF:
            default:
                return false;
        }
    }

    /**
     * Map a terminal run state to a webGui importance level:
     *   SUCCESS => normal; WARNING/PARTIAL/TIMEOUT => warning; FAILED => alert;
     *   ABORTED => normal.
     */
    public static function notifyImportance(string $state): string
    {
        switch ($state) {
            case Rsync::STATE_FAILED:
                return Notify::IMPORTANCE_ALERT;
            case Rsync::STATE_WARNING:
            case Rsync::STATE_PARTIAL:
            case Rsync::STATE_TIMEOUT:
                return Notify::IMPORTANCE_WARNING;
            case Rsync::STATE_SUCCESS:
            case Rsync::STATE_ABORTED:
            default:
                return Notify::IMPORTANCE_NORMAL;
        }
    }

    /**
     * Compose the notification description: job name, state, rsync exit code, and
     * (when readily available from the just-written summary) the run duration.
     * Kept concise - one line.
     *
     * @param array<string,mixed> $job
     */
    public static function notifyDescription(array $job, string $state, int $exitCode, bool $dryRun = false): string
    {
        $jobName = (string) ($job['name'] ?? ($job['id'] ?? 'job'));
        $parts   = [
            sprintf(
                '%sJob "%s" finished with state %s (rsync exit code %d).',
                $dryRun ? '[Dry-run] ' : '',
                $jobName,
                $state,
                $exitCode
            ),
        ];

        $duration = self::notifyDuration($job);
        if ($duration !== null) {
            $parts[] = 'Duration: ' . self::formatDuration($duration) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * Best-effort read of this run's duration (seconds) from the /boot summary
     * that run()'s finally wrote just before calling notifyHook. Returns null
     * when no usable summary is available - duration is purely advisory.
     *
     * @param array<string,mixed> $job
     */
    private static function notifyDuration(array $job): ?int
    {
        $jobId = (string) ($job['id'] ?? '');
        if ($jobId === '') {
            return null;
        }
        $summary = self::readSummary($jobId);
        if ($summary === null || !array_key_exists('durationSec', $summary)) {
            return null;
        }
        return (int) $summary['durationSec'];
    }

    /** Render a duration in seconds as a compact "Hh Mm Ss" / "Mm Ss" / "Ss" string. */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 0) {
            $seconds = 0;
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%dh %dm %ds', $h, $m, $s);
        }
        if ($m > 0) {
            return sprintf('%dm %ds', $m, $s);
        }
        return sprintf('%ds', $s);
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
            $msg = (string) ($mat['error'] ?? 'SSH transport could not be prepared.');
            return ['ok' => false, 'reason' => 'ssh-config', 'message' => $msg];
        }
        // Ssh::materialize never reads host or username, so a connection missing
        // either still returns ok:true - and userHost() then yields '', handing
        // rsync the operand ":/path", which fails opaquely mid-run. Fail fast
        // instead; such a job could never have succeeded. Cleanup FIRST:
        // materialize has already written this run's known_hosts (and possibly a
        // key or pass file).
        $conn = is_array($mat['conn'] ?? null) ? $mat['conn'] : [];
        if ((string) ($conn['host'] ?? '') === '' || (string) ($conn['username'] ?? '') === '') {
            Ssh::cleanupRuntime((string) $mat['token']);
            return [
                'ok'      => false,
                'reason'  => 'connection-incomplete',
                'message' => 'The selected Connection is incomplete: it needs both a host and a username.',
            ];
        }
        return ['ok' => true, 'token' => (string) $mat['token'], 'mat' => $mat];
    }

    /**
     * Materialise the rsync DAEMON pieces for a job's connection: the sibling of
     * materializeSsh, with the same result shape so run()'s two arms stay
     * symmetrical.
     *
     * The transport and completeness checks are made HERE, from the merged
     * connection, rather than left to Ssh::materializeDaemon's own guards, so
     * the run's `reason` names the actual problem instead of collapsing every
     * misconfiguration into 'daemon-config'. materializeDaemon keeps its guards
     * as defence in depth (the handler and the test path call it directly).
     *
     * @param array<string,mixed> $job
     * @return array{ok:bool,reason?:string,message?:string,token?:string,mat?:array<string,mixed>}
     */
    private static function materializeDaemonConn(array $job, string $runLog, string $jobId): array
    {
        $connId = (string) ($job['connectionId'] ?? '');
        if ($connId === '') {
            return ['ok' => false, 'reason' => 'no-connection', 'message' => 'rsync daemon transport selected but no connection is set.'];
        }
        try {
            $creds = Credentials::load();
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'creds-unreadable', 'message' => 'Could not read credentials: ' . $e->getMessage()];
        }

        $conn = Credentials::findConnection($creds, $connId);
        if ($conn === null) {
            return ['ok' => false, 'reason' => 'daemon-config', 'message' => "Connection not found: $connId"];
        }
        $conn = Credentials::mergeConnection($conn);
        if ((string) $conn['transport'] !== 'DAEMON') {
            return [
                'ok'      => false,
                'reason'  => 'daemon-config',
                'message' => 'The selected Connection uses SSH transport, but this job uses rsync daemon transport.',
            ];
        }
        if ((string) $conn['host'] === '' || (string) $conn['username'] === '') {
            return [
                'ok'      => false,
                'reason'  => 'connection-incomplete',
                'message' => 'The selected Connection is incomplete: an rsync daemon job needs both a host and a username.',
            ];
        }

        try {
            $mat = Ssh::materializeDaemon($creds, $connId);
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'materialize-failed', 'message' => $e->getMessage()];
        }
        if (empty($mat['ok'])) {
            return ['ok' => false, 'reason' => 'daemon-config', 'message' => (string) ($mat['error'] ?? 'rsync daemon transport could not be prepared.')];
        }
        return ['ok' => true, 'token' => (string) $mat['token'], 'mat' => $mat];
    }

    /**
     * Resolve a pair's source and destination operands by direction. The stored
     * pair is {local, remote}. For SSH:
     *   PUSH: local -> user@host:remote   (write to remote)
     *   PULL: user@host:remote -> local   (write to local)
     * For DAEMON the shape is identical but the separator is '::' and the right
     * side is a MODULE reference, not a path:
     *   PUSH: local -> user@host::module
     *   PULL: user@host::module -> local
     * For LOCAL transport both sides are plain local paths and direction is
     * coerced to PUSH (local -> remote, both on this box).
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $pair
     * @param string              $transport SSH | LOCAL | DAEMON
     * @param string              $userHost  pre-resolved "user@host" (SSH + DAEMON)
     * @return array{src:string,dest:string}
     */
    public static function resolvePair(array $job, array $pair, string $transport, string $userHost = ''): array
    {
        $local  = (string) ($pair['local'] ?? '');
        $remote = (string) ($pair['remote'] ?? '');
        $direction = strtoupper(trim((string) ($job['direction'] ?? 'PUSH')));

        // DAEMON must come BEFORE the `!== 'SSH'` fallthrough below: after it, a
        // daemon job would run as LOCAL with a bare relative operand.
        if ($transport === 'DAEMON') {
            // One character's difference from the SSH arm: '::' instead of ':'.
            $remoteOperand = $userHost . '::' . $remote;
            if ($direction === 'PULL') {
                return ['src' => $remoteOperand, 'dest' => $local];
            }
            return ['src' => $local, 'dest' => $remoteOperand];
        }

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
     * Build the "user@host" operand prefix for a remote pair from the job's
     * already-materialised connection (mat['conn'] from materializeSsh /
     * materializeDaemonConn) - identical for SSH and DAEMON, which differ only
     * in the separator resolvePair() appends. Returns '' only when the
     * connection can't be resolved - the guardrails/materialisation already
     * fail the run before we get here in that case.
     *
     * @param array<string,mixed> $conn
     */
    private static function userHost(array $conn): string
    {
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
     * Mirrors Job::validate's per-pair logic, and must keep mirroring it: the
     * `local` field is always a local path; the `remote` field is a local path
     * under LOCAL transport, a module reference under DAEMON, and a non-root
     * remote sub-path under SSH; and when a --delete option is on, the
     * DESTINATION (remote for PUSH, local for PULL) must be a specific sub-dir.
     * Save-time and run-time use the SAME checkers so they cannot disagree.
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
        $direction = strtoupper(trim((string) ($job['direction'] ?? 'PUSH')));

        // local field: always a local path on this box.
        if ($local === '') {
            $errors[] = 'local path is required.';
        } else {
            foreach (Job::checkLocalPath($local, 'local path') as $e) {
                $errors[] = $e;
            }
        }

        // remote field: local guardrails under LOCAL, a module reference under
        // DAEMON, otherwise a non-root remote sub-path.
        if ($remote === '') {
            if ($transport === 'LOCAL') {
                $errors[] = 'second local path is required.';
            } elseif ($transport === 'DAEMON') {
                $errors[] = 'daemon module path is required.';
            } else {
                $errors[] = 'remote path is required.';
            }
        } elseif ($transport === 'LOCAL') {
            foreach (Job::checkLocalPath($remote, 'second local path') as $e) {
                $errors[] = $e;
            }
        } elseif ($transport === 'DAEMON') {
            foreach (Job::checkDaemonModule($remote, 'daemon module path') as $e) {
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
        // the two remote transports let PULL flip the destination to the `local`
        // side - and an unknown hand-edited transport keeps LOCAL's answer,
        // which is what resolvePair()'s fallthrough does with it too.
        $deleteOn = !empty($opts['delete']) || !empty($opts['deleteExcluded']);
        if ($deleteOn) {
            $destIsRemote = in_array($transport, ['SSH', 'DAEMON'], true) ? ($direction !== 'PULL') : true;
            $e = Job::deleteDestError($transport, $destIsRemote, $destIsRemote ? $remote : $local);
            if ($e !== '') {
                $errors[] = $e;
            }
        }

        // --rsync-path reaches the remote shell, and config.json is user-writable,
        // so re-check it here the same way both path sides are re-checked above:
        // save-time validation is not the only way a value gets in.
        $remoteRsync = trim((string) ($opts['remoteRsyncPath'] ?? ''));
        if ($remoteRsync !== '' && !Job::isRemoteProgramPath($remoteRsync)) {
            $errors[] = 'the remote rsync path must be a bare absolute path.';
        }

        return $errors;
    }

    /** rsync --timeout for the run-time destination checks. */
    const PROBE_TIMEOUT = 60;

    /** Entries whose presence makes a --delete destination off-limits (see probeDeleteDestinations). */
    const PROTECTED_ENTRIES = ['.unraid-rsync-target', '.ur-snapshot-root', 'snapshots'];

    /**
     * Every check that must pass before the pre-hook, in order: the path
     * guardrails for ALL pairs, the effective options, the target marker, the
     * --delete ancestry rule, then the two destination round-trips. Returns ''
     * when the run may proceed, else the failure reason (already logged).
     *
     * @param array<string,mixed>      $job
     * @param array<string,mixed>      $config
     * @param array<string,mixed>      $opts      EFFECTIVE rsync options
     * @param array<string,mixed>|null $sshPieces transport pieces (null = LOCAL)
     */
    private static function preflight(array $job, array $config, string $transport, array $opts, ?array $sshPieces, string $userHost, string $runLog, string $jobId): string
    {
        $direction = strtoupper(trim((string) ($job['direction'] ?? 'PUSH')));
        $pairs     = (isset($job['pairs']) && is_array($job['pairs'])) ? $job['pairs'] : [];

        $errors = [];
        foreach ($pairs as $i => $pair) {
            foreach (self::guardrailErrors($job, is_array($pair) ? $pair : [], $transport, $opts) as $e) {
                $errors[] = 'Pair #' . ((int) $i + 1) . ': ' . $e;
            }
        }
        $jobs   = (isset($config['jobs']) && is_array($config['jobs'])) ? $config['jobs'] : [];
        $errors = array_merge(
            $errors,
            Job::optionErrors($opts, $transport, $direction),
            Job::markerErrors($job, $transport, $direction),
            Job::ancestryErrors($job, $opts, Job::protectedTargets($jobs, $jobId))
        );
        if ($errors !== []) {
            foreach ($errors as $e) {
                Logger::event($runLog, $jobId, 'Rejected by path guardrails: ' . $e);
            }
            return 'guardrail';
        }

        if (!self::checkTargetMarker($job, $transport, $direction, $opts, $sshPieces, $userHost, $runLog, $jobId)) {
            return 'dest-not-mounted';
        }

        if ($transport === 'SSH' && $direction !== 'PULL' && (!empty($opts['delete']) || !empty($opts['deleteExcluded']))) {
            return self::probeDeleteDestinations($pairs, $opts, $sshPieces, $userHost, $runLog, $jobId);
        }
        return '';
    }

    /**
     * Optional target-marker check: fetch the job's marker file from the
     * DESTINATION side over the job's own transport into this run's tmpfs and,
     * when an expected content is set, compare it. A missing file, an rsync
     * error or a mismatch means the destination drive is not the one the job
     * was set up for (typically: not mounted), so nothing may be written.
     *
     * @param array<string,mixed>      $job
     * @param array<string,mixed>      $opts
     * @param array<string,mixed>|null $sshPieces
     */
    private static function checkTargetMarker(array $job, string $transport, string $direction, array $opts, ?array $sshPieces, string $userHost, string $runLog, string $jobId): bool
    {
        $marker = trim((string) ($job['targetMarker'] ?? ''));
        if ($marker === '') {
            return true;
        }
        $remote = in_array($transport, ['SSH', 'DAEMON'], true) && $direction !== 'PULL';
        $src    = $remote ? $userHost . ($transport === 'DAEMON' ? '::' : ':') . $marker : $marker;
        $file   = Ssh::ensureMarkersDir() . '/' . Util::safeFileId($jobId . '-' . self::pid() . '-' . bin2hex(random_bytes(6)));
        $argv   = Rsync::auxArgv(
            // A marker is a few bytes; anything bigger is not one (and is skipped).
            ['--max-size=4K', '--timeout=' . self::PROBE_TIMEOUT],
            $opts,
            $remote ? $sshPieces : null,
            [$src, $file]
        );

        $out = '';
        try {
            $exit = Rsync::run(
                $argv,
                static function (string $chunk) use (&$out): void {
                    $out = substr($out . $chunk, 0, 4096);
                },
                Ssh::childEnv(($remote && is_array($sshPieces)) ? (array) ($sshPieces['sshEnv'] ?? []) : [])
            );
            $body = (is_file($file) && !is_link($file)) ? @file_get_contents($file, false, null, 0, 4096) : false;
        } finally {
            @unlink($file);
        }

        if ($exit !== 0 || $body === false) {
            Logger::event($runLog, $jobId, "Target marker $marker could not be read (rsync exit $exit), so the "
                . 'destination is treated as not mounted.' . ($out !== '' ? ' ' . Util::firstLine($out) : ''));
            return false;
        }
        $expected = trim((string) ($job['targetMarkerContent'] ?? ''));
        if ($expected !== '' && trim($body) !== $expected) {
            Logger::event($runLog, $jobId, "Target marker $marker does not hold the expected content, so the "
                . 'destination is treated as not mounted.');
            return false;
        }
        Logger::event($runLog, $jobId, "Target marker $marker found.");
        return true;
    }

    /**
     * Before an SSH --delete mirror, list each destination directory (one level,
     * same -e and --rsync-path as the transfer) and refuse it when it holds a
     * PROTECTED_ENTRIES name: it is then a drive root or a snapshot store, which
     * a mirror would empty. A destination that does not exist yet is fine -
     * rsync creates it. Returns '' or the failure reason.
     *
     * @param array<int,mixed>         $pairs
     * @param array<string,mixed>      $opts
     * @param array<string,mixed>|null $sshPieces
     */
    private static function probeDeleteDestinations(array $pairs, array $opts, ?array $sshPieces, string $userHost, string $runLog, string $jobId): string
    {
        foreach ($pairs as $i => $pair) {
            $dest = trim((string) (is_array($pair) ? ($pair['remote'] ?? '') : ''));
            $n    = (int) $i + 1;
            $argv = Rsync::auxArgv(
                ['--list-only', '--dirs', '--timeout=' . self::PROBE_TIMEOUT],
                $opts,
                $sshPieces,
                [$userHost . ':' . rtrim($dest, '/') . '/']
            );

            $found = '';
            $buf   = '';
            $head  = '';
            $onOutput = static function (string $chunk) use (&$found, &$buf, &$head): void {
                $head = substr($head . $chunk, 0, 4096);
                $buf .= $chunk;
                while (($nl = strpos($buf, "\n")) !== false) {
                    $name = self::listingName(substr($buf, 0, $nl));
                    $buf  = substr($buf, $nl + 1);
                    if ($found === '' && in_array($name, self::PROTECTED_ENTRIES, true)) {
                        $found = $name;
                    }
                }
            };
            $exit = Rsync::run($argv, $onOutput, Ssh::childEnv(is_array($sshPieces) ? (array) ($sshPieces['sshEnv'] ?? []) : []));
            $onOutput("\n");

            if ($found !== '') {
                Logger::event($runLog, $jobId, "Pair #$n: the --delete destination $dest contains '$found', so it is a "
                    . 'drive root or a snapshot store that a mirror would wipe. Point the job at a sub-directory.');
                return 'dest-is-protected';
            }
            // rsync exits 23 with "change_dir ... No such file or directory" for a
            // destination that is not there yet.
            if ($exit === 23 && stripos($head, 'No such file or directory') !== false) {
                continue;
            }
            if ($exit !== 0) {
                Logger::event($runLog, $jobId, "Pair #$n: could not list the --delete destination $dest (rsync exit $exit)."
                    . ($head !== '' ? ' ' . Util::firstLine($head) : ''));
                return 'dest-probe-failed';
            }
        }
        return '';
    }

    /**
     * The entry name from one `rsync --list-only` line
     * ("drwxr-xr-x          4,096 2026/10/20 12:00:00 name"), '' for anything else.
     */
    public static function listingName(string $line): string
    {
        if (!preg_match('#^([-dlcbps])\S{9}\S*\s+[\d,.]+\s+\d{4}/\d\d/\d\d\s+\d\d:\d\d:\d\d\s(.+)$#', rtrim($line, "\r"), $m)) {
            return '';
        }
        $name = $m[2];
        if ($m[1] === 'l' && ($arrow = strpos($name, ' -> ')) !== false) {
            $name = substr($name, 0, $arrow);
        }
        return $name;
    }

    /**
     * Build the environment array passed to a hook. The post-run hook also gets
     * the outcome (UR_JOB_STATUS, UR_EXIT_CODE); the pre-run hook gets the same
     * keys with empty/0 values so a hook can rely on them existing. UR_TRIGGER
     * is 'manual' or 'schedule' so a hook can branch on how the run was started.
     *
     * @param array<string,mixed> $job
     * @return array<string,string>
     */
    public static function hookEnv(array $job, string $jobId, bool $dryRun, ?string $status = null, ?int $exitCode = null, string $trigger = 'manual'): array
    {
        return [
            'UR_JOB_ID'     => $jobId,
            'UR_JOB_NAME'   => (string) ($job['name'] ?? ''),
            'UR_DRY_RUN'    => $dryRun ? '1' : '0',
            'UR_TRIGGER'    => ($trigger === 'schedule') ? 'schedule' : 'manual',
            'UR_JOB_STATUS' => $status === null ? '' : $status,
            'UR_EXIT_CODE'  => $exitCode === null ? '' : (string) $exitCode,
        ];
    }

    /**
     * Run a user hook. Delegates to the injectable $hookRunner in tests; the
     * live runner runs `bash -c "$hook"` as root (the documented privilege
     * surface) with the outcome env, streaming output to the run log.
     *
     * @param array<string,string>   $env
     * @param int                    $timeoutSec 0 = no limit
     * @param (callable():bool)|null $stopWhen   true = kill the hook (Abort)
     * @return int the exit code, or HOOK_TIMED_OUT
     */
    private static function runHook(string $hook, array $env, string $runLog, int $timeoutSec = 0, ?callable $stopWhen = null): int
    {
        // Hook stdout/stderr (captured + browser-visible) flows through the same
        // redacting, size-capped sink as rsync output (F1 + F3).
        $sink = Logger::sink($runLog);

        try {
            if (self::$hookRunner !== null) {
                return (int) (self::$hookRunner)($hook, $env, $sink, $timeoutSec);
            }
            return self::runHookProcess($hook, $env, $sink, $timeoutSec, $stopWhen);
        } finally {
            // A hook whose last write had no trailing newline would otherwise
            // stay in the sink buffer until the next sink on this path reset it.
            Logger::flushSink($runLog);
        }
    }

    /** runHook() result for a hook killed at its deadline (never a real exit code). */
    const HOOK_TIMED_OUT = -1;

    /** Seconds between SIGTERM and SIGKILL for a hook past its deadline. Overridable for tests. */
    public static int $hookKillGraceSec = 10;

    /**
     * The live hook runner: `bash -c "$hook"` via proc_open (the hook body is a
     * SINGLE argv element passed to bash -c, so the SHELL the user wrote is bash,
     * not us splatting it onto a command line). The job's outcome is exported in
     * the child environment. This is the ONE place the plugin intentionally runs
     * a shell, and only for the user's OWN hook text.
     *
     * The hook gets its own session (setsid) so a deadline or an Abort can
     * signal everything it started - a hung `docker stop` is a grandchild - and
     * not just bash: SIGTERM to the process group, SIGKILL $hookKillGraceSec
     * later. Without setsid only bash itself can be signalled.
     *
     * @param array<string,string>   $env
     * @param callable(string):void  $onOutput
     * @param int                    $timeoutSec 0 = no limit
     * @param (callable():bool)|null $stopWhen   true = kill now (exit 143)
     * @return int the exit code, or HOOK_TIMED_OUT
     */
    public static function runHookProcess(string $hook, array $env, callable $onOutput, int $timeoutSec = 0, ?callable $stopWhen = null): int
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        // argv form: bash receives the hook as one argument to -c, so the user's
        // shell is bash and nothing of ours is re-parsed.
        $argv = ['bash', '-c', $hook];
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $setsid) {
            if (is_executable($setsid)) {
                $argv = array_merge([$setsid, '-w'], $argv);
                break;
            }
        }
        $childEnv = array_merge(self::inheritEnv(), $env);
        $proc = @proc_open($argv, $descriptors, $pipes, null, $childEnv);
        if (!is_resource($proc)) {
            $onOutput("Failed to start hook (bash).\n");
            return 127;
        }

        // Drain stdout AND stderr concurrently with a non-blocking select loop.
        // Reading one pipe fully before the other can DEADLOCK: a hook that fills
        // the stderr pipe buffer while we are still blocked on stdout would hang
        // forever (and vice versa). Shared with Rsync/KeyTools. [ROB-01]
        $deadline = $timeoutSec > 0 ? microtime(true) + $timeoutSec : null;
        ProcIO::drainPipes(
            [1 => $pipes[1], 2 => $pipes[2]],
            static function (int $fd, string $chunk) use ($onOutput): void {
                $onOutput($chunk);
            },
            $deadline,
            $stopWhen
        );

        // EOF on both pipes is not proof bash has exited, so the deadline and
        // the stop request are polled until it has.
        $kill = '';
        while (true) {
            $status = @proc_get_status($proc);
            if (empty($status['running'])) {
                break;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                $kill = 'timeout';
            } elseif ($stopWhen !== null && $stopWhen()) {
                $kill = 'stop';
            }
            if ($kill !== '') {
                self::killHook($proc, (int) $status['pid']);
                break;
            }
            usleep(100000);
        }

        // Capture signal status before proc_close (see Rsync::defaultRun).
        $status = @proc_get_status($proc);
        $code   = proc_close($proc);
        if ($kill === 'timeout') {
            return self::HOOK_TIMED_OUT;
        }
        if ($kill === 'stop') {
            return 143;
        }
        if (is_array($status)) {
            if (!empty($status['signaled']) && isset($status['termsig'])) {
                return 128 + (int) $status['termsig'];
            }
            if (array_key_exists('exitcode', $status) && (int) $status['exitcode'] >= 0) {
                return (int) $status['exitcode'];
            }
        }
        return $code < 0 ? 1 : $code;
    }

    /**
     * SIGTERM the hook's process group, then SIGKILL it after the grace period.
     * The group is the hook's own (setsid), so this can never reach the runner;
     * when there is no such group (no setsid) only bash itself is signalled.
     *
     * @param resource $proc
     */
    private static function killHook($proc, int $pid): void
    {
        $signal = static function (int $sig) use ($proc, $pid): void {
            if ($pid > 0 && function_exists('posix_kill') && @posix_kill(-$pid, $sig)) {
                return;
            }
            @proc_terminate($proc, $sig);
        };
        $signal(15);
        $until = microtime(true) + self::$hookKillGraceSec;
        while (microtime(true) < $until) {
            $status = @proc_get_status($proc);
            if (empty($status['running'])) {
                break;
            }
            usleep(100000);
        }
        // Unconditionally: bash may be gone while a child that ignores SIGTERM
        // still holds the group.
        $signal(9);
    }

    /**
     * A minimal inherited environment for hooks (PATH + HOME).
     *
     * @return array<string,string>
     */
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
        $clean = Util::safeFileId($jobId);
        $path  = $dir . '/' . $clean . '.summary.json';

        $trigger = (($summary['trigger'] ?? '') === 'schedule') ? 'schedule' : 'manual';
        $out = [
            'state'       => (string) ($summary['state'] ?? Rsync::STATE_FAILED),
            'startedAt'   => (string) ($summary['startedAt'] ?? ''),
            'finishedAt'  => (string) ($summary['finishedAt'] ?? ''),
            'exitCode'    => (int) ($summary['exitCode'] ?? 1),
            'durationSec' => (int) ($summary['durationSec'] ?? 0),
            'dryRun'      => !empty($summary['dryRun']),
            'trigger'     => $trigger,
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

    /**
     * Read a job's last-run summary, or null when none exists/unreadable.
     *
     * @return array<string,mixed>|null
     */
    public static function readSummary(string $jobId): ?array
    {
        $clean = Util::safeFileId($jobId);
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

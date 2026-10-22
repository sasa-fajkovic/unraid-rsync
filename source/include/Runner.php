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
 *   - Runner::$scanDir          scandir() of a snapshot job's sources (tests)
 *   - Runner::$localLister      listing of a delete job's local source/destination (tests)
 *   - Snapshot::$remoteRunner / Snapshot::$nowProvider  the snapshot helper and clock
 *
 * SECURITY:
 *   - rsync is built as an argv array via Rsync::buildArgv and run without a
 *     shell.
 *   - pre/post HOOKS are user-authored shell, run via `bash -c "$hook"` as root
 *     BY DESIGN (a documented privilege surface) - the one intentional shell
 *     use in this class; their output+exit are captured to the run log.
 *   - path guardrails (Job.php) are RE-ENFORCED at runtime for EVERY pair
 *     before the pre-hook (preflight()): a dest of /boot/system/array-or-pool
 *     root, or --delete without a specific sub-dir destination, fails the run
 *     before any rsync starts.
 *
 * SUMMARY (read by getStatus for the Jobs list, and durable across reboot) is
 * written to /boot at <UR_CONFIG_BASE>/runs/<jobid>.summary.json:
 *   {state, startedAt, finishedAt, exitCode, durationSec, dryRun, trigger,
 *    reason?, lastReal?}
 * The top level is the LAST EXECUTION of any kind; lastReal is the last
 * non-dry run (see writeSummary / lastReal).
 *
 * NOTIFICATIONS: Runner::notifyHook, with importance chosen by cause; hard
 * failures (hardFail) and runners that died (reportCrashed) notify too.
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
require_once __DIR__ . '/Snapshot.php';
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

    /**
     * Injectable scandir for a snapshot job's local sources: fn(string $path): array|false.
     * null => scandir().
     *
     * @var callable|null
     */
    public static $scanDir = null;

    /**
     * Injectable listing of a delete job's local source and destination:
     * fn(string $path): array|false|null - the entries, false when the path
     * exists but cannot be listed, null when it does not exist. null => scandir().
     *
     * @var (callable(string):(array<int,string>|false|null))|null
     */
    public static $localLister = null;

    /**
     * How often, in seconds, a running pair trims its run log back to the cap
     * (Logger::enforceRunLogCap) and checks the hard ceiling.
     */
    public static float $logCapCheckSec = 2.0;

    /**
     * Hard ceiling, as a multiple of the run-log cap: a pair is stopped with
     * reason log-cap-exceeded only when a trim FAILED and the log is over it.
     * A log that grew again after a successful trim is never stopped.
     */
    const LOG_CEILING_FACTOR = 4;

    /**
     * Injectable mid-pair trim: fn(string $runLog): bool, true when the trim
     * failed. null => Logger::enforceRunLogCap.
     *
     * @var (callable(string):bool)|null
     */
    public static $logTrimmer = null;

    /**
     * Injectable teardown fault: fn(string $step): void, called before each
     * teardown step of run()'s finally; tests throw from it. null => none.
     *
     * @var (callable(string):void)|null
     */
    public static $teardownFault = null;

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
     * $gen is the launch generation the handler recorded (RunState::beginLaunch)
     * before starting this runner; a scheduled run gets a fresh one. An Abort
     * flag for another generation is stale and cleared at start.
     *
     * @param string $jobId
     * @param bool   $dryRun
     * @return array{state:string,exitCode:int,runLog:string,reason?:string}
     */
    public static function run(string $jobId, bool $dryRun = false, string $trigger = 'manual', string $gen = ''): array
    {
        $gen = preg_match('/^[0-9a-f]{1,64}$/D', $gen) === 1 ? $gen : RunState::newGen();
        // How this run was triggered: 'manual' (UI Run/Dry-run) or 'schedule'
        // (cron). Clamped to the closed set so a junk value never propagates into
        // state/summary/history. Orthogonal to $dryRun (dry-vs-real).
        $trigger = ($trigger === 'schedule') ? 'schedule' : 'manual';

        // 1. Load config + credentials; resolve the job.
        try {
            $config = Config::load();
        } catch (Throwable $e) {
            return self::hardFail($jobId, 'Could not read configuration: ' . $e->getMessage(), 'config-unreadable', $trigger, $dryRun);
        }

        $job = self::findJob($config, $jobId);
        if ($job === null) {
            return self::hardFail($jobId, "Job not found: $jobId", 'job-not-found', $trigger, $dryRun);
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

        // Cron::buildContent's own rule, re-checked: a cron file that failed to
        // update still carries the line of a job since disabled or made manual.
        if ($trigger === 'schedule' && (empty($job['enabled']) || !empty($job['manualOnly']))) {
            try {
                Logger::event('', $jobId, 'Scheduled run ignored: the job is '
                    . (empty($job['enabled']) ? 'disabled' : 'manual-only') . ', so a stale cron line started it.');
            } catch (Throwable $e) {
                // best-effort, like hardFail's log line.
            }
            return ['state' => Rsync::STATE_FAILED, 'exitCode' => 1, 'runLog' => '', 'reason' => 'not-scheduled'];
        }

        // 2. Concurrency guard. Acquire an ATOMIC per-job lock FIRST so two
        //    near-simultaneous launches can't both pass an isRunning() check and
        //    start duplicate concurrent rsync runs for one job (dangerous with
        //    --delete). isRunning() is still consulted as a secondary, friendlier
        //    signal (and to clear stale state), but the flock is the real guard.
        try {
            $lock = RunState::acquireLock($jobId);
        } catch (Throwable $e) {
            return self::hardFail($jobId, 'Could not acquire run lock: ' . $e->getMessage(), 'lock-error', $trigger, $dryRun, $job, $retention);
        }
        if ($lock === null) {
            return self::hardFail($jobId, "Job is already running: $jobId", 'already-running', $trigger, $dryRun, $job, $retention);
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
        $snap        = Config::normalizeSnapshot($job['snapshot'] ?? null);
        $stamp       = '';
        try {
            // Taken under the per-job lock (§3), before the in-flight record so a
            // crash report can name the .inprogress it left.
            if ($snap !== null) {
                $stamp = Snapshot::stamp(Snapshot::now());
            }
            // We hold the lock, so a state still claiming running=true is a
            // runner that died.
            $stale = RunState::takeStale($jobId);
            if ($stale !== null) {
                self::reportCrashed($stale);
            }
            $runLog = Logger::openRun($jobId, $startedAtTs);
            // Retention: the user's "keep last N executions" setting bounds BOTH
            // the tmpfs run logs and the persistent history. Apply it to the log
            // pruner here (newest N kept) now that the new run log exists, so
            // tmpfs use stays bounded. Best-effort - a prune failure must never
            // fail the run.
            Logger::$retention = $retention;
            Logger::pruneRuns($jobId, null, $runLog);
            $runState = [
                'pid'        => self::pid(),
                'running'    => true,
                'dryRun'     => $dryRun,
                'trigger'    => $trigger,
                'startedAt'  => $startedAt,
                'currentLog' => $runLog,
                'gen'        => $gen,
            ];
            if ($stamp !== '') {
                $runState['snapshot'] = $stamp;
            }
            RunState::write($jobId, $runState);
            // After the write, so an Abort that read the previous generation is
            // dropped here and one for this launch is kept.
            RunState::clearStaleAbort($jobId, $gen);
            RunState::writeInflight($jobId, $runState);
        } catch (Throwable $e) {
            RunState::releaseLock($lock);
            return self::hardFail($jobId, 'Could not initialise run state/log: ' . $e->getMessage(), 'state-init-failed', $trigger, $dryRun, $job, $retention);
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
        if ($stamp !== '') {
            Logger::event($runLog, $jobId, "Snapshot mode: this run's snapshot is $stamp (UTC).");
        }

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
            // A 'signal' flag stops the run like an Abort, but only a user's
            // flag (written before the handler signals) records it as ABORTED.
            $onAbortSignal = static function () use ($jobId, $gen): void {
                try {
                    RunState::requestAbort($jobId, $gen, 'signal');
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
        $exitCodes = [];
        $token    = '';
        // Declared out here so the finally can harvest this run's host key
        // before cleanup; only ever set on the SSH arm.
        $sshMat   = null;
        // Snapshot mode's per-run context (snapshotContext()); null for a mirror.
        $sn       = null;

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
                        Ssh::base(),
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
                    Logger::setRedaction([(string) ($mat['passFile'] ?? '')], Ssh::base(), $token);
                }
            }

            if ($token !== '') {
                // A SIGKILLed runner never reaches cleanupRuntime(); reportCrashed
                // sweeps this run's tmpfs secrets by the token recorded here.
                try {
                    RunState::write($jobId, $runState + ['token' => $token]);
                } catch (Throwable $e) {
                }
            }

            $global   = (isset($config['global']) && is_array($config['global'])) ? $config['global'] : [];
            $opts     = Rsync::effectiveOptions($job, $global);
            $pairs    = (isset($job['pairs']) && is_array($job['pairs'])) ? $job['pairs'] : [];
            // Resolve "user@host" ONCE (not per pair) for the remote
            // operands. DAEMON uses the SAME prefix as SSH - the only
            // difference is the '::' separator resolvePair() appends.
            $userHost = in_array($transport, ['SSH', 'DAEMON'], true) ? self::userHost($mat['conn'] ?? []) : '';
            if ($snap !== null && is_array($sshMat)) {
                $sn = self::snapshotContext($job, $snap, $stamp, $jobId, $sshMat, $userHost);
            }

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
                if ($preflight === '' && $sn !== null) {
                    $preflight = self::abortedOr(self::snapshotPreflight($sn, $job, $opts, $sshPieces, $runLog, $jobId), $jobId);
                }
                if ($preflight === 'aborted') {
                    $state    = Rsync::STATE_ABORTED;
                    $exitCode = 143;
                } elseif ($preflight !== '') {
                    $state    = Rsync::STATE_FAILED;
                    $exitCode = 1;
                    $reason   = $preflight;
                }
            }

            // 5b'. Snapshot cleanup, before the pre-hook so it stays out of the
            //      service-downtime window. Never fails the run.
            if ($state === Rsync::STATE_SUCCESS && $sn !== null && !$dryRun) {
                self::snapshotCleanup($sn, $reason, $runLog, $jobId);
            }
            // An Abort before the pre-hook (including one clicked before this
            // runner started) stops the run there, before anything transfers.
            if ($state === Rsync::STATE_SUCCESS && RunState::abortRequested($jobId)) {
                Logger::event($runLog, $jobId, 'Abort requested; stopping before the pre-run hook.');
                $state    = Rsync::STATE_ABORTED;
                $exitCode = 143;
            }

            // 5c. preHook (only if nothing above failed or was aborted).
            $hookEnvBase = self::hookEnv(
                $job,
                $jobId,
                $dryRun,
                null,
                null,
                $trigger,
                $snap !== null ? ['UR_SNAPSHOT' => $stamp, 'UR_SNAPSHOT_STATUS' => ''] : []
            );
            if ($state !== Rsync::STATE_FAILED && $state !== Rsync::STATE_ABORTED) {
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
                    } else {
                        Logger::event($runLog, $jobId, 'Pre-run hook exited with code ' . $preExit . '.');
                    }
                    if (RunState::abortRequested($jobId)) {
                        // The stop request killed the hook; not a hook failure.
                        Logger::event($runLog, $jobId, 'Abort requested; stopping after the pre-run hook.');
                        $state    = Rsync::STATE_ABORTED;
                        $exitCode = 143;
                    } elseif ($preExit === self::HOOK_TIMED_OUT) {
                        $state    = Rsync::STATE_FAILED;
                        $exitCode = 124;
                        $reason   = 'prehook-timeout';
                    } elseif ($preExit !== 0) {
                        // A failed preHook fails the run; pairs are skipped, but
                        // postHook STILL runs (the finally-style postHook below).
                        $state    = Rsync::STATE_FAILED;
                        $exitCode = $preExit;
                        $reason   = 'prehook-failed';
                    }
                }
            }

            // 5c'. This box's side of every pair, after the pre-hook (it may mount
            //      the storage) and before anything is written.
            if ($state === Rsync::STATE_SUCCESS) {
                $localCheck = self::localChecks($job, $transport, $opts, $dryRun, $runLog, $jobId);
                if ($localCheck !== '') {
                    $state    = Rsync::STATE_FAILED;
                    $exitCode = 1;
                    $reason   = $localCheck;
                }
            }

            // 5d. Snapshot: create <stamp>.inprogress (real runs only).
            if ($state === Rsync::STATE_SUCCESS && $sn !== null && !$dryRun) {
                if (self::snapshotCall($sn, 'mkstamp', [$stamp], $runLog, $jobId, Snapshot::HELPER_TIMEOUT_SEC) === null) {
                    if (self::abortedOr('snapshot-mkstamp-failed', $jobId) === 'aborted') {
                        $state    = Rsync::STATE_ABORTED;
                        $exitCode = 143;
                    } else {
                        $state    = Rsync::STATE_FAILED;
                        $exitCode = 1;
                        $reason   = 'snapshot-mkstamp-failed';
                    }
                } else {
                    $sn['mkstamped'] = true;
                    Logger::event($runLog, $jobId, "Created $stamp.inprogress.");
                }
            }

            // 6. Pairs: check abort, build argv, run rsync.
            if ($state !== Rsync::STATE_FAILED && $state !== Rsync::STATE_ABORTED) {
                $logLevel = (string) ($job['logLevel'] ?? 'normal');
                $pairResults = [];
                if ($sn !== null) {
                    // --info=stats2 feeds the completion metadata, so it may not
                    // depend on the level (§11.2): lift quiet like a dry run is.
                    if ($logLevel === 'quiet') {
                        $logLevel = 'summary';
                        Logger::event($runLog, $jobId, 'Snapshot mode runs the quiet log level as summary.');
                    }
                    foreach (Job::snapshotOptionWarnings($opts) as $w) {
                        Logger::event($runLog, $jobId, $w);
                    }
                }

                if (count($pairs) === 0) {
                    $state    = Rsync::STATE_FAILED;
                    $exitCode = 1;
                    $reason   = 'no-pairs';
                    Logger::event($runLog, $jobId, 'No source -> destination pairs configured; nothing to do.');
                }

                $aborted     = false;
                $cappedPairs = [];
                $ceiling     = self::LOG_CEILING_FACTOR * Logger::maxRunLogBytes();
                foreach ($pairs as $i => $pair) {
                    $n = (int) $i + 1;

                    // Abort flag is polled BEFORE each pair.
                    if (RunState::abortRequested($jobId)) {
                        $aborted = true;
                        Logger::event($runLog, $jobId, "Abort requested; stopping before pair #$n.");
                        break;
                    }

                    // Resolve src/dest by direction (guardrails ran in pre-flight).
                    $extraTokens = [];
                    if ($sn !== null) {
                        $sub         = trim((string) ($pair['sub'] ?? ''));
                        $resolved    = ['src' => trim((string) ($pair['local'] ?? '')), 'dest' => "$userHost:{$sn['root']}/$stamp.inprogress/$sub/"];
                        $extraTokens = self::snapshotLinkDests($sn, $i, $sub);
                    } else {
                        $resolved = self::resolvePair($job, $pair, $transport, $userHost);
                    }

                    Logger::event($runLog, $jobId, sprintf(
                        'Pair #%d: %s -> %s',
                        $n,
                        $resolved['src'],
                        $resolved['dest']
                    ));

                    $argv = Rsync::buildArgv(
                        $sn !== null ? Snapshot::runOptions($opts) : $opts,
                        $logLevel,
                        $runLog,
                        $resolved['src'],
                        $resolved['dest'],
                        $sshPieces,
                        $dryRun,
                        $extraTokens
                    );

                    // Stream rsync's captured stdout/stderr through Logger::sink,
                    // which REDACTS armed per-run secret paths (F1) and enforces
                    // the per-run-log byte cap (F3) before bytes hit the log.
                    // Snapshot mode also keeps the tail for the stats it records.
                    $sink     = Logger::sink($runLog);
                    $captured = '';
                    $partial  = '';
                    $missing  = [];
                    $onOutput = $sn === null ? $sink : static function (string $chunk) use ($sink, &$captured, &$partial, &$missing): void {
                        $sink($chunk);
                        $captured = substr($captured . $chunk, -self::SNAPSHOT_CAPTURE_BYTES);
                        // rsync names a missing --link-dest once, early; it must
                        // not scroll out of the tail kept for the statistics.
                        $lines   = explode("\n", $partial . $chunk);
                        $partial = substr((string) array_pop($lines), -self::SNAPSHOT_CAPTURE_BYTES);
                        $missing = array_merge($missing, Snapshot::linkDestMissing(implode("\n", $lines)));
                    };
                    // rsync's --log-file bypasses the sink's cap, so trim the
                    // file while the pair runs, not only after it.
                    $capHit   = false;
                    $lastTrim = microtime(true);
                    $stopWhen = static function () use ($runLog, $ceiling, &$capHit, &$lastTrim): bool {
                        if (microtime(true) - $lastTrim < self::$logCapCheckSec) {
                            return false;
                        }
                        $lastTrim = microtime(true);
                        if (self::$logTrimmer !== null) {
                            $failed = (bool) (self::$logTrimmer)($runLog);
                        } else {
                            Logger::enforceRunLogCap($runLog, $failed);
                        }
                        if (!$failed) {
                            return false;
                        }
                        clearstatcache(true, $runLog);
                        return $capHit = ((int) @filesize($runLog) > $ceiling);
                    };
                    $pairExit = Rsync::run(
                        $argv,
                        $onOutput,
                        Ssh::childEnv(is_array($sshPieces) ? $sshPieces['sshEnv'] : []),
                        null,
                        $stopWhen
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
                    if ($capHit) {
                        $cappedPairs[count($exitCodes) - 1] = true;
                        self::setReason($reason, 'log-cap-exceeded');
                        Logger::event($runLog, $jobId, sprintf(
                            'Pair #%d stopped: its run log stayed over the %d MiB hard ceiling after trimming.',
                            $n,
                            intdiv($ceiling, 1024 * 1024)
                        ));
                    }
                    Logger::event($runLog, $jobId, "Pair #$n rsync exited with code $pairExit (" . Rsync::exitToState($pairExit) . ').');
                    if ($sn !== null) {
                        $missing       = array_merge($missing, Snapshot::linkDestMissing($partial));
                        $pairResults[] = self::snapshotPairResult($sn, $i, $sub, $pairExit, $captured, $missing, $runLog, $jobId);
                    }

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
                    // A pair stopped at the log ceiling exits 143, which would
                    // read as ABORTED; it is a WARNING (reason log-cap-exceeded).
                    $worst    = Rsync::worstOutcome(array_values(array_diff_key($exitCodes, $cappedPairs)));
                    $state    = $worst['state'];
                    $exitCode = $worst['exitCode'];
                    if ($cappedPairs !== [] && $state === Rsync::STATE_SUCCESS) {
                        $state = Rsync::STATE_WARNING;
                    }
                }

                // 6b. Snapshot completion gate (§4.1), then commit.
                if ($sn !== null && $sn['mkstamped']) {
                    $gate = !$aborted && $state !== Rsync::STATE_FAILED && count($exitCodes) === count($pairs)
                        && array_diff($exitCodes, [0, 24]) === [];
                    if (!$gate) {
                        Logger::event($runLog, $jobId, "Snapshot $stamp was not committed; $stamp.inprogress is kept for the next run.");
                        if (!$aborted && $state !== Rsync::STATE_ABORTED) {
                            self::setReason($reason, 'snapshot-not-committed');
                        }
                    } else {
                        $commit = self::snapshotCommit($sn, $pairResults, $runLog, $jobId);
                        if ($commit === 'aborted') {
                            $state    = Rsync::STATE_ABORTED;
                            $exitCode = 143;
                        } elseif ($commit !== '') {
                            $state    = Rsync::STATE_FAILED;
                            $exitCode = 1;
                            self::setReason($reason, $commit);
                        } elseif ($sn['suspect']) {
                            $state = Rsync::STATE_WARNING;
                            self::setReason($reason, 'snapshot-suspect');
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            $state    = Rsync::STATE_FAILED;
            $exitCode = 1;
            $reason   = 'exception';
            Logger::event($runLog, $jobId, 'Run failed with an internal error: ' . $e->getMessage());
        } finally {
            // Every teardown step runs on its own: a step that throws is logged
            // once and the rest still run (cleanup, summary, History, state,
            // lock). Only a summary, History or run-state failure changes the
            // outcome (teardownFailed).
            $broken = [];
            $step = static function (string $name, callable $fn) use ($jobId, $runLog, &$broken): void {
                try {
                    if (self::$teardownFault !== null) {
                        (self::$teardownFault)($name);
                    }
                    $fn();
                } catch (Throwable $e) {
                    $broken[] = $name;
                    self::reportTeardownFailure($jobId, $runLog, $name, $e);
                }
            };

            // Land whatever the last sink still holds, FIRST. It has to happen
            // before the postHook: runHook() opens a new sink on this same path
            // and Logger::sink() RESETS the per-path state, so a flush placed
            // after the hook would find nothing left to rescue. This is the
            // abort path's safety net - a SIGTERM'd rsync leaves its last
            // redraw unterminated, and the trap lets this finally run.
            $step('flush', static fn() => Logger::flushSink($runLog));
            $state = self::snapshotWarnState($state, $reason);

            // A stop nobody clicked Abort for (a shutdown's SIGTERM, a killed
            // rsync exiting 20) is a failure, not an abort: it must alert.
            if ($state === Rsync::STATE_ABORTED && !RunState::abortedByUser($jobId, $gen)) {
                $state  = Rsync::STATE_FAILED;
                $reason = 'terminated';
                Logger::event($runLog, $jobId, 'The run was stopped by a signal, not by Abort; recording it as FAILED.');
            }

            // 7. postHook ALWAYS runs (even on failure/abort), with the outcome
            //    in its environment.
            $postHook = (string) ($job['postHook'] ?? '');
            if (trim($postHook) !== '') {
                $step('post-hook', static function () use ($job, $jobId, $dryRun, $trigger, $snap, $sn, $stamp, $config, $postHook, $runLog, &$state, &$exitCode, &$reason): void {
                    $postEnv = self::hookEnv($job, $jobId, $dryRun, $state, $exitCode, $trigger, $snap === null ? [] : [
                        'UR_SNAPSHOT'        => !empty($sn['mkstamped']) ? $stamp : '',
                        'UR_SNAPSHOT_STATUS' => $dryRun ? 'dry-run'
                            : (!empty($sn['committed']) ? 'committed' : (!empty($sn['mkstamped']) ? 'not-committed' : 'none')),
                    ]);
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
                    // but we surface it on an otherwise-clean run so it isn't silent,
                    // and record it as the reason wherever none is set yet: an exit-24
                    // run or a manual abort notify as normal unless a reason says
                    // otherwise.
                    if ($postExit !== 0 && $postExit !== self::HOOK_TIMED_OUT && $state !== Rsync::STATE_FAILED) {
                        if ($state === Rsync::STATE_SUCCESS) {
                            $state = Rsync::STATE_WARNING;
                        }
                        $reason = $reason !== '' ? $reason : 'posthook-failed';
                    }
                });
                // A post-hook that could not be run counts as a failed one.
                if (in_array('post-hook', $broken, true) && $state !== Rsync::STATE_FAILED) {
                    if ($state === Rsync::STATE_SUCCESS) {
                        $state = Rsync::STATE_WARNING;
                    }
                    $reason = $reason !== '' ? $reason : 'posthook-failed';
                }
            }

            // 7a. Snapshot prune (§6): after the post-hook, so it holds no
            //     services, and before cleanup, which removes the ssh pieces it
            //     needs.
            if ($sn !== null) {
                $step('snapshot prune', static function () use (&$sn, $dryRun, &$reason, $runLog, $jobId): void {
                    self::snapshotPrune($sn, $dryRun, $reason, $runLog, $jobId);
                });
                $state = self::snapshotWarnState($state, $reason);
            }

            // 7b. Trust on first use: with accept-new and nothing pinned yet, ssh
            //     appended the key it accepted to this run's known_hosts. Pin it
            //     into the connection BEFORE cleanup unlinks that file, so a
            //     CHANGED key fails closed on the next run instead of being
            //     silently trusted again.
            if (is_array($sshMat)) {
                $step('host-key pin', static function () use ($sshMat, $runLog, $jobId): void {
                    $connId  = (string) ($sshMat['conn']['id'] ?? '');
                    $hostKey = Ssh::harvestHostKey($sshMat);
                    // The endpoint the key came from, not the one stored now: the
                    // connection may have been edited while this run was going.
                    if (Ssh::pinHostKey($connId, $hostKey, (string) ($sshMat['conn']['host'] ?? ''), (int) ($sshMat['conn']['port'] ?? 0))) {
                        // No key material and no tmpfs path in the message.
                        Logger::event($runLog, $jobId, 'Pinned host key for connection ' . $connId . ' on first use.');
                    }
                });
            }

            // 8. Cleanup the SSH runtime secrets for THIS run's token, and disarm
            //    the secret-path redaction armed at materialisation (F1).
            if ($token !== '') {
                $step('secret cleanup', static fn() => Ssh::cleanupRuntime($token));
            }
            // And again for the postHook's own sink, before the redact pass so
            // an unterminated last line gets scrubbed like everything else.
            // Final pass, while redaction is still armed: catches anything rsync
            // or the postHook wrote to the run log after the last pair's cap.
            $step('log redaction', static function () use ($runLog): void {
                Logger::flushSink($runLog);
                Logger::redactRunLog($runLog);
            });
            Logger::clearRedaction();

            // 9. Persist the last-run summary to /boot + clear running state.
            $finishedAtTs = time();
            // STORAGE timestamp: UTC, always (see the startedAt note above).
            $finishedAt  = gmdate('Y-m-d\TH:i:s\Z', $finishedAtTs);
            $durationSec = max(0, $finishedAtTs - $startedAtTs);
            // Snapshot jobs only, so a mirror's summary and History keep their shape.
            $snapFields  = $snap === null ? [] : [
                'snapshot'  => !empty($sn['mkstamped']) ? $stamp : '',
                'committed' => !empty($sn['committed']),
            ];
            $summaryRecord = [
                'startedAt'   => $startedAt,
                'finishedAt'  => $finishedAt,
                'exitCode'    => $exitCode,
                'durationSec' => $durationSec,
                'dryRun'      => $dryRun,
                'trigger'     => $trigger,
            ] + $snapFields;
            // null = the step threw (already logged), false = it reported failure.
            $summaryOk  = null;
            $summarySaid = [$state, $reason];
            $step('summary', static function () use ($jobId, $state, $reason, $summaryRecord, &$summaryOk): void {
                $summaryOk = self::writeSummary($jobId, ['state' => $state, 'reason' => $reason] + $summaryRecord);
            });
            if ($summaryOk !== true) {
                self::teardownFailed($state, $reason);
            }
            if ($snap !== null) {
                $snapFields += ['suspect' => !empty($sn['suspect']), 'pruned' => (int) ($sn['pruned'] ?? 0)];
                if (isset($sn['growthKB'])) {
                    $snapFields['growthKB'] = $sn['growthKB'];
                }
            }

            // Append a persistent history record (one small /boot write, same
            // cadence as the summary), then lazily prune to the retention cap.
            // jobName is snapshotted so the record survives a later rename/delete;
            // logRef is the run-log BASENAME only (resolves via getJobLog?run=).
            $historyOk   = null;
            $historySaid = [$state, $reason];
            $step('history', static function () use ($jobId, $job, $startedAt, $finishedAt, $dryRun, $trigger, $state, $exitCode, $durationSec, $runLog, $reason, $snapFields, &$historyOk): void {
                $historyOk = History::append($jobId, [
                    'startedAt'   => $startedAt,
                    'finishedAt'  => $finishedAt,
                    'jobName'     => (string) ($job['name'] ?? $jobId),
                    'dryRun'      => $dryRun,
                    'trigger'     => $trigger,
                    'state'       => $state,
                    'exitCode'    => $exitCode,
                    'durationSec' => $durationSec,
                    'logRef'      => Logger::runIdFromPath($runLog),
                    'reason'      => $reason,
                    'lastError'   => $exitCode !== 0 ? Logger::lastRsyncError($runLog) : '',
                ] + $snapFields);
            });
            if ($historyOk !== true) {
                self::teardownFailed($state, $reason);
            }
            self::reportPersistFailure($jobId, array_keys(array_filter([
                'last-run summary' => $summaryOk === false,
                'History record'   => $historyOk === false,
            ])));
            $step('in-flight record', static function () use ($jobId, $retention): void {
                RunState::clearInflight($jobId);
                History::prune($jobId, $retention);
            });

            Logger::event($runLog, $jobId, "Run finished: state=$state exitCode=$exitCode.");
            $step('run state', static fn() => RunState::markStopped($jobId));
            if (in_array('run state', $broken, true)) {
                self::teardownFailed($state, $reason);
            }
            // A step after the summary or History write changed the outcome:
            // bring what was already written into line (the lock is still ours).
            if ($summaryOk === true && $summarySaid !== [$state, $reason]) {
                $step('summary update', static function () use ($jobId, $state, $reason, $summaryRecord): void {
                    if (!self::writeSummary($jobId, ['state' => $state, 'reason' => $reason] + $summaryRecord)) {
                        throw new RuntimeException('the summary could not be rewritten as ' . $state);
                    }
                });
            }
            if ($historyOk === true && $historySaid !== [$state, $reason]) {
                $step('History update', static function () use ($jobId, $startedAt, $runLog, $state, $reason): void {
                    if (!History::amend($jobId, $startedAt, Logger::runIdFromPath($runLog), ['state' => $state, 'reason' => $reason])) {
                        throw new RuntimeException('the History record could not be rewritten as ' . $state);
                    }
                });
            }
            $step('abort flag', static fn() => RunState::clearAbort($jobId));

            // Dispatch the per-job notification. Best-effort: it is
            // gated on the job's notifyMode and NEVER throws (see notifyHook),
            // so a notification failure can't break the run's finally/unwind.
            $notify = [
                'state'       => $state,
                'exitCode'    => $exitCode,
                'exitCodes'   => $exitCodes,
                'reason'      => $reason,
                'trigger'     => $trigger,
                'dryRun'      => $dryRun,
                'durationSec' => $durationSec,
            ];
            if ($snap !== null && !$dryRun && $state !== Rsync::STATE_ABORTED && empty($sn['committed'])) {
                $step('snapshot history', static function () use ($jobId, $job, &$notify): void {
                    $runs = self::notCommittedRuns($jobId);
                    if ($runs > 1) {
                        $notify['message'] = sprintf('Job "%s" has not committed a snapshot for %d runs.', (string) ($job['name'] ?? $jobId), $runs);
                    }
                });
            }
            self::notifyHook($job, $notify);

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
     * Dispatch the notification for one run outcome. Never throws: a failed
     * notification must never fail the backup.
     *
     * $run carries the CAUSE, not just the state, because importance is chosen
     * by cause (see notifyImportance): state, exitCode, and optionally exitCodes
     * (one per pair that ran), reason, trigger, dryRun, durationSec (null =
     * unknown), and subject/message to replace the default "<job> <STATE>"
     * subject and "finished." lead (hard failures, crashed runs).
     *
     * notifyMode: off = never; always = every outcome; failure-only = every
     * non-normal importance; success-only = normal importance except ABORTED and SKIPPED.
     * A dry run is gated the same way and marked "[Dry-run]".
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $run
     */
    public static function notifyHook(array $job, array $run): void
    {
        try {
            $mode = self::normalizeNotifyMode((string) ($job['notifyMode'] ?? self::NOTIFY_OFF));
            if (!self::shouldNotify($mode, $run)) {
                return;
            }

            $jobName = (string) ($job['name'] ?? ($job['id'] ?? 'job'));
            $subject = sprintf('%s: %s', self::NOTIFY_EVENT, (string) ($run['subject'] ?? ($jobName . ' ' . $run['state'])))
                     . (!empty($run['dryRun']) ? ' [Dry-run]' : '');

            Notify::send([
                'event'       => self::NOTIFY_EVENT,
                'subject'     => $subject,
                'description' => self::notifyDescription($job, $run),
                'importance'  => self::notifyImportance($run),
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

    /**
     * Decide whether a run outcome notifies under notifyMode $mode.
     *
     * @param array<string,mixed> $run
     */
    public static function shouldNotify(string $mode, array $run): bool
    {
        $normal = self::notifyImportance($run) === Notify::IMPORTANCE_NORMAL;
        switch ($mode) {
            case self::NOTIFY_ALWAYS:
                return true;
            case self::NOTIFY_SUCCESS_ONLY:
                return $normal && !in_array($run['state'] ?? '', [Rsync::STATE_ABORTED, Rsync::STATE_SKIPPED], true);
            case self::NOTIFY_FAILURE_ONLY:
                return !$normal;
            case self::NOTIFY_OFF:
            default:
                return false;
        }
    }

    /**
     * Importance by cause. Admins route these levels to agents, so they mean
     * alert = act now, warning = look today, normal = FYI:
     *   FAILED (incl. pre-hook failure/timeout, hard failures, crashes) => alert
     *   SKIPPED (a scheduled run found the previous one still running) => normal
     *   PARTIAL, TIMEOUT                                                => warning
     *   a post-hook failure/timeout on any non-FAILED run               => warning
     *   ABORTED: scheduled => warning, manual => normal
     *   WARNING: only exit 24 (files vanished mid-transfer) => normal; exit 25
     *            (max-delete cap) or a post-hook failure/timeout => warning
     *   SUCCESS => normal
     * Snapshot reasons (§4.4) come first: SNAPSHOT_ALERT_REASONS => alert,
     * SNAPSHOT_WARNING_REASONS on any non-FAILED run => warning.
     *
     * @param array<string,mixed> $run
     */
    public static function notifyImportance(array $run): string
    {
        $state  = (string) ($run['state'] ?? '');
        $reason = (string) ($run['reason'] ?? '');
        if (in_array($reason, self::SNAPSHOT_ALERT_REASONS, true)) {
            return Notify::IMPORTANCE_ALERT;
        }
        if ($state !== Rsync::STATE_FAILED && in_array($reason, self::SNAPSHOT_WARNING_REASONS, true)) {
            return Notify::IMPORTANCE_WARNING;
        }
        if ($state === Rsync::STATE_SKIPPED) {
            return Notify::IMPORTANCE_NORMAL;
        }
        if ($state !== Rsync::STATE_FAILED && in_array($reason, ['posthook-failed', 'posthook-timeout'], true)) {
            return Notify::IMPORTANCE_WARNING;
        }
        switch ($state) {
            case Rsync::STATE_FAILED:
                return Notify::IMPORTANCE_ALERT;
            case Rsync::STATE_PARTIAL:
            case Rsync::STATE_TIMEOUT:
                return Notify::IMPORTANCE_WARNING;
            case Rsync::STATE_ABORTED:
                return ($run['trigger'] ?? '') === 'schedule' ? Notify::IMPORTANCE_WARNING : Notify::IMPORTANCE_NORMAL;
            case Rsync::STATE_WARNING:
                $codes = array_map('intval', (array) ($run['exitCodes'] ?? [$run['exitCode'] ?? 0]));
                $onlyVanished = $reason === '' && in_array(24, $codes, true) && !in_array(25, $codes, true);
                return $onlyVanished ? Notify::IMPORTANCE_NORMAL : Notify::IMPORTANCE_WARNING;
            default:
                return Notify::IMPORTANCE_NORMAL;
        }
    }

    /**
     * The notification body: the lead sentence, then state, reason, exit code
     * with its meaning, duration, trigger and the dry-run flag. One line.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $run
     */
    public static function notifyDescription(array $job, array $run): string
    {
        $jobName  = (string) ($job['name'] ?? ($job['id'] ?? 'job'));
        $reason   = (string) ($run['reason'] ?? '');
        $dryRun   = !empty($run['dryRun']);
        $duration = $run['durationSec'] ?? null;

        $parts = [
            ($dryRun ? '[Dry-run] ' : '') . (string) ($run['message'] ?? sprintf('Job "%s" finished.', $jobName)),
            'State: ' . (string) ($run['state'] ?? '') . '.',
        ];
        if ($reason !== '') {
            $parts[] = 'Reason: ' . $reason . '.';
        }
        if (($run['state'] ?? '') !== Rsync::STATE_SKIPPED) {
            $parts[] = 'Exit code: ' . self::exitCodeText($run) . '.';
        }
        $parts[] = 'Duration: ' . ($duration === null ? 'unknown' : self::formatDuration((int) $duration)) . '.';
        $parts[] = 'Trigger: ' . ((($run['trigger'] ?? '') === 'schedule') ? 'schedule' : 'manual') . '.';
        $parts[] = 'Dry-run: ' . ($dryRun ? 'yes' : 'no') . '.';

        return implode(' ', $parts);
    }

    /**
     * "25 = <meaning>", or one entry per pair when the pairs disagree. The
     * meaning is only attached when rsync produced the code: a run that failed
     * before any pair carries a placeholder 1 (or the pre-hook's own code),
     * which rsync's table would mislabel.
     *
     * @param array<string,mixed> $run
     */
    private static function exitCodeText(array $run): string
    {
        $exitCode = (int) ($run['exitCode'] ?? 0);
        $codes    = array_map('intval', (array) ($run['exitCodes'] ?? []));
        if (strncmp((string) ($run['reason'] ?? ''), 'prehook-', 8) === 0) {
            return $exitCode . ' (pre-run hook)';
        }
        if ($codes === []) {
            return (string) $exitCode;
        }
        $explain = static fn(int $c): string => isset(Rsync::EXIT_MEANINGS[$c]) ? $c . ' = ' . Rsync::EXIT_MEANINGS[$c] : (string) $c;
        // 143 is the abort placeholder; any other code no pair returned (an
        // internal error after a pair ran) is ours, not rsync's.
        $head = (in_array($exitCode, $codes, true) || $exitCode === 143) ? $explain($exitCode) : (string) $exitCode;
        if (count(array_unique($codes)) === 1 && $codes[0] === $exitCode) {
            return $head;
        }
        $perPair = [];
        foreach ($codes as $i => $c) {
            $perPair[] = 'pair #' . ($i + 1) . ' ' . $explain($c);
        }
        return $head . ' (' . implode('; ', $perPair) . ')';
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
        $snapshotOn = Config::normalizeSnapshot($job['snapshot'] ?? null) !== null;

        // local field: always a local path on this box.
        if ($local === '') {
            $errors[] = 'local path is required.';
        } else {
            foreach (Job::pairLocalErrors($job, $transport, $local, 'local path') as $e) {
                $errors[] = $e;
            }
        }

        // remote field: local guardrails under LOCAL, a module reference under
        // DAEMON, otherwise a non-root remote sub-path.
        if ($snapshotOn) {
            // Snapshot mode writes to <root>/<stamp>/<sub>; Job::snapshotErrors()
            // checks `remote` and `sub`, as Job::validate does.
        } elseif ($remote === '') {
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
        $snapshotOn = Config::normalizeSnapshot($job['snapshot'] ?? null) !== null;
        $direction = strtoupper(trim((string) ($job['direction'] ?? 'PUSH')));
        $pairs     = (isset($job['pairs']) && is_array($job['pairs'])) ? $job['pairs'] : [];

        $errors = [];
        foreach ($pairs as $i => $pair) {
            foreach (self::guardrailErrors($job, is_array($pair) ? $pair : [], $transport, $opts) as $e) {
                $errors[] = 'Pair #' . ((int) $i + 1) . ': ' . $e;
            }
        }
        $jobs   = (isset($config['jobs']) && is_array($config['jobs'])) ? $config['jobs'] : [];
        try {
            $creds = Credentials::load();
        } catch (Throwable $e) {
            $creds = null;
        }
        $errors = array_merge(
            $errors,
            Job::pairOverlapErrors($job, $opts),
            Job::optionErrors($opts, $transport, $direction),
            Job::markerErrors($job, $transport, $direction),
            Job::ancestryErrors($job, $opts, Job::protectedTargets($jobs, $jobId, $creds), $creds),
            Job::snapshotRootErrors($job, $jobs, $creds)
        );
        // A rejected option is snapshot-preflight (§2.6); every other snapshot
        // rule (transport, root, marker, retention, subs) is a guardrail.
        $rejected = $snapshotOn ? Job::snapshotOptionErrors($opts) : [];
        if ($snapshotOn) {
            $errors = array_merge($errors, array_values(array_diff(Job::snapshotErrors($job, $opts, $transport, $direction), $rejected)));
        }
        if ($errors !== [] || $rejected !== []) {
            foreach ($errors as $e) {
                Logger::event($runLog, $jobId, 'Rejected by path guardrails: ' . $e);
            }
            foreach ($rejected as $e) {
                Logger::event($runLog, $jobId, $e);
            }
            return $errors !== [] ? 'guardrail' : 'snapshot-preflight';
        }

        if (!self::checkTargetMarker($job, $transport, $direction, $opts, $sshPieces, $userHost, $runLog, $jobId)) {
            return 'dest-not-mounted';
        }

        // Snapshot mode forces delete off, whatever $opts says. A local
        // destination is probed by localChecks(), after the pre-hook.
        if (!$snapshotOn && in_array($transport, ['SSH', 'DAEMON'], true) && $direction !== 'PULL'
            && (!empty($opts['delete']) || !empty($opts['deleteExcluded']))) {
            return self::probeDeleteDestinations($pairs, $opts, $sshPieces, $userHost, $transport === 'DAEMON', $runLog, $jobId);
        }
        return '';
    }

    /**
     * This box's side of every pair, run after the pre-hook: each local source
     * and destination must be on a mounted drive, not the RAM root (where
     * --mkpath would create a destination, and an empty mountpoint is a valid
     * source). With a delete option on, a source must not be an empty directory
     * and a destination must not hold a PROTECTED_ENTRIES name. A dry run only
     * reports an empty source: it deletes nothing, and showing what a real run
     * would do is its job. Returns '' or the failure reason (already logged).
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts EFFECTIVE rsync options
     */
    private static function localChecks(array $job, string $transport, array $opts, bool $dryRun, string $runLog, string $jobId): string
    {
        clearstatcache();
        $remoteHost = in_array($transport, ['SSH', 'DAEMON'], true);
        $pull       = $remoteHost && strtoupper(trim((string) ($job['direction'] ?? 'PUSH'))) === 'PULL';
        $deleteOn   = Config::normalizeSnapshot($job['snapshot'] ?? null) === null
            && (!empty($opts['delete']) || !empty($opts['deleteExcluded']));
        $list       = self::$localLister
            ?? static fn(string $p): array|false|null => (file_exists($p) || is_link($p)) ? @scandir($p) : null;
        $pairs      = (isset($job['pairs']) && is_array($job['pairs'])) ? $job['pairs'] : [];

        foreach ($pairs as $i => $pair) {
            $n     = (int) $i + 1;
            $local = trim((string) (is_array($pair) ? ($pair['local'] ?? '') : ''));
            $src   = $pull ? '' : $local;
            $dest  = $remoteHost ? ($pull ? $local : '') : trim((string) (is_array($pair) ? ($pair['remote'] ?? '') : ''));

            foreach (['source' => $src, 'destination' => $dest] as $role => $path) {
                if ($path !== '' && Util::isOnRootFs($path)) {
                    Logger::event($runLog, $jobId, "Pair #$n: the $role $path is on the RAM root filesystem, not on a "
                        . 'mounted drive, so the run is refused.');
                    return $role === 'source' ? 'source-not-mounted' : 'dest-not-mounted';
                }
            }
            if (!$deleteOn) {
                continue;
            }

            $entries = $src !== '' ? $list($src) : null;
            if (is_array($entries) && array_diff($entries, ['.', '..']) === []) {
                if (!$dryRun) {
                    Logger::event($runLog, $jobId, "Pair #$n: the source $src is empty and a delete option is on, so this "
                        . 'run would empty the destination. Refused; check that the source is mounted and populated.');
                    return 'source-empty';
                }
                Logger::event($runLog, $jobId, "Pair #$n: the source $src is empty; a real run would be refused (source-empty).");
            }

            // Missing is fine (rsync creates it); present but unlistable is not.
            $entries = $dest !== '' ? $list($dest) : null;
            if ($entries === false) {
                Logger::event($runLog, $jobId, "Pair #$n: the --delete destination $dest exists but could not be "
                    . 'listed, so it cannot be checked.');
                return 'dest-probe-failed';
            }
            $found = array_values(array_intersect(self::PROTECTED_ENTRIES, $entries ?? []));
            if ($found !== []) {
                return self::refuseProtected($n, $dest, $found[0], $runLog, $jobId);
            }
        }
        return '';
    }

    private static function refuseProtected(int $n, string $dest, string $found, string $runLog, string $jobId): string
    {
        Logger::event($runLog, $jobId, "Pair #$n: the --delete destination $dest contains '$found', so it is a "
            . 'drive root or a snapshot store that a mirror would wipe. Point the job at a sub-directory.');
        return 'dest-is-protected';
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
                Ssh::childEnv(($remote && is_array($sshPieces)) ? (array) ($sshPieces['sshEnv'] ?? []) : []),
                // A daemon has no ServerAlive; --timeout does not cover the connect.
                ($remote && $transport === 'DAEMON') ? 2 * self::PROBE_TIMEOUT : null
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
     * Before a remote --delete mirror, list each destination directory (one
     * level, over the transfer's own transport: -e and --rsync-path for SSH,
     * --port and --password-file for a daemon) and refuse it when it holds a
     * PROTECTED_ENTRIES name: it is then a drive root or a snapshot store, which
     * a mirror would empty. A destination that does not exist yet is fine -
     * rsync creates it. Any other failure refuses the run. Returns '' or the
     * failure reason.
     *
     * @param array<int,mixed>         $pairs
     * @param array<string,mixed>      $opts
     * @param array<string,mixed>|null $sshPieces
     */
    private static function probeDeleteDestinations(array $pairs, array $opts, ?array $sshPieces, string $userHost, bool $daemon, string $runLog, string $jobId): string
    {
        foreach ($pairs as $i => $pair) {
            $dest  = trim((string) (is_array($pair) ? ($pair['remote'] ?? '') : ''));
            $n     = (int) $i + 1;
            $flags = ['--list-only', '--dirs', '--timeout=' . self::PROBE_TIMEOUT];
            if ($daemon) {
                $flags[] = '--contimeout=' . self::PROBE_TIMEOUT;
            }
            $argv = Rsync::auxArgv(
                $flags,
                $opts,
                $sshPieces,
                [$userHost . ($daemon ? '::' : ':') . rtrim($dest, '/') . '/']
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
            $exit = Rsync::run(
                $argv,
                $onOutput,
                Ssh::childEnv(is_array($sshPieces) ? (array) ($sshPieces['sshEnv'] ?? []) : []),
                $daemon ? 2 * self::PROBE_TIMEOUT : null
            );
            $onOutput("\n");

            if ($found !== '') {
                return self::refuseProtected($n, $dest, $found, $runLog, $jobId);
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

    // --- snapshot mode (docs/design/snapshot-mode.md §4, §6) -----------------

    /** Reasons that always alert (§4.4). */
    const SNAPSHOT_ALERT_REASONS = [
        'snapshot-preflight', 'snapshot-clock', 'snapshot-mkstamp-failed', 'snapshot-not-committed',
        'snapshot-commit-failed', 'snapshot-suspect', 'prune-sanity',
    ];

    /** Reasons that warn on any non-FAILED run (§4.4). */
    const SNAPSHOT_WARNING_REASONS = ['snapshot-held', 'prune-backlog', 'prune-failed'];

    /** Tail of each pair's rsync output kept for parseStats / linkDestMissing. */
    const SNAPSHOT_CAPTURE_BYTES = 65536;

    /**
     * $reason stays first-set-wins, except that an alert-class snapshot reason
     * replaces a warning-class one.
     */
    private static function setReason(string &$reason, string $new): void
    {
        $warning = array_merge(self::SNAPSHOT_WARNING_REASONS, ['posthook-failed', 'posthook-timeout', 'log-cap-exceeded']);
        if ($reason === '' || (in_array($new, self::SNAPSHOT_ALERT_REASONS, true) && in_array($reason, $warning, true))) {
            $reason = $new;
        }
    }

    /**
     * Abort SIGTERMs the runner's process group, ssh included, so a helper
     * call it interrupts fails: that is the abort, not $failure.
     */
    private static function abortedOr(string $failure, string $jobId): string
    {
        return ($failure !== '' && RunState::abortRequested($jobId)) ? 'aborted' : $failure;
    }

    /** A clean run that set a non-failing snapshot reason ends as WARNING. */
    private static function snapshotWarnState(string $state, string $reason): string
    {
        $warn = array_merge(self::SNAPSHOT_WARNING_REASONS, ['snapshot-suspect', 'prune-sanity']);
        return ($state === Rsync::STATE_SUCCESS && in_array($reason, $warn, true)) ? Rsync::STATE_WARNING : $state;
    }

    /**
     * The per-run snapshot context: the job's settings, the helper's common
     * arguments and the transport, plus what preflight, commit and prune learn.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $snap Config::normalizeSnapshot() block
     * @param array<string,mixed> $mat  Ssh::materialize() result
     * @return array<string,mixed>
     */
    private static function snapshotContext(array $job, array $snap, string $stamp, string $jobId, array $mat, string $userHost): array
    {
        $root = (string) $snap['root'];
        return [
            'snap'      => $snap,
            'stamp'     => $stamp,
            'root'      => $root,
            'args'      => [$root, trim((string) ($job['targetMarker'] ?? '')), trim((string) ($job['targetMarkerContent'] ?? '')), $jobId],
            'mat'       => $mat,
            'userHost'  => $userHost,
            'useSudo'   => ($mat['conn']['useSudo'] ?? null) === true,
            'listing'   => null,
            'prev'      => '',
            'prevMeta'  => null,
            'basis'     => [],
            'stale'     => '',
            'cleanup'   => [],
            'dfBefore'  => null,
            'mkstamped' => false,
            'committed' => false,
            'suspect'   => false,
            'pruned'    => 0,
            'growthKB'  => null,
        ];
    }

    /**
     * One helper call on the receiver. Its stderr goes to the run log; a
     * failure is logged with the exit code's meaning and the first stderr line.
     * Returns the parsed reply, or null on any failure. Never throws.
     *
     * @param array<string,mixed>    $sn
     * @param array<int,string>      $extra    op arguments after the common four
     * @param (callable():bool)|null $stopWhen
     * @return array<string,mixed>|null
     */
    private static function snapshotCall(array $sn, string $op, array $extra, string $runLog, string $jobId, int $timeoutSec, ?callable $stopWhen = null): ?array
    {
        try {
            $args = $op === 'probe' ? [] : array_merge($sn['args'], $extra);
            $r    = Snapshot::runRemote($sn['mat'], $sn['userHost'], $op, $args, $sn['useSudo'], $timeoutSec, $stopWhen);
        } catch (Throwable $e) {
            Logger::event($runLog, $jobId, "Snapshot helper $op could not be started: " . $e->getMessage());
            return null;
        }
        if ($r['stderr'] !== '') {
            $sink = Logger::sink($runLog);
            $sink($r['stderr']);
            Logger::flushSink($runLog);
        }
        if ($r['exit'] !== 0) {
            $why = $r['exit'] === Snapshot::REMOTE_TIMED_OUT ? 'timed out after ' . $timeoutSec . ' s'
                : 'exit ' . $r['exit'] . (isset(Snapshot::HELPER_EXITS[$r['exit']]) ? ': ' . Snapshot::HELPER_EXITS[$r['exit']] : ': transport failure');
            $line = Util::firstLine($r['stderr']);
            Logger::event($runLog, $jobId, "Snapshot helper $op failed ($why)." . ($line !== '' ? " $line" : ''));
            return null;
        }
        $parsed = Snapshot::parseHelper($r['stdout'], $op);
        if (empty($parsed['ok'])) {
            Logger::event($runLog, $jobId, (string) ($parsed['error'] ?? "Helper $op output rejected."));
            return null;
        }
        return $parsed;
    }

    /**
     * §4 step 5: the receiver-side checks before the pre-hook. Fills $sn with
     * the listing, the basis per pair and the cleanup list. Returns '' or the
     * failure reason (already logged).
     *
     * @param array<string,mixed>      $sn
     * @param array<string,mixed>      $job
     * @param array<string,mixed>      $opts EFFECTIVE rsync options
     * @param array<string,mixed>|null $sshPieces
     */
    private static function snapshotPreflight(array &$sn, array $job, array $opts, ?array $sshPieces, string $runLog, string $jobId): string
    {
        try {
            Snapshot::helperScript();
        } catch (Throwable $e) {
            Logger::event($runLog, $jobId, $e->getMessage());
            return 'snapshot-preflight';
        }

        $t     = Snapshot::HELPER_TIMEOUT_SEC;
        $probe = self::snapshotCall($sn, 'probe', [], $runLog, $jobId, $t);
        if ($probe === null) {
            return 'snapshot-preflight';
        }
        Logger::event($runLog, $jobId, 'Receiver: clock synchronised by NTP: ' . $probe['ntp'] . '; ionice: ' . ($probe['ionice'] ? 'yes' : 'no') . '.');
        $skew = Snapshot::checkSkew((int) $probe['now'], Snapshot::now()->getTimestamp());
        if ($skew !== null) {
            Logger::event($runLog, $jobId, $skew);
            return 'snapshot-clock';
        }
        if (self::snapshotCall($sn, 'check', [], $runLog, $jobId, $t) === null) {
            return 'snapshot-preflight';
        }
        $list = self::snapshotCall($sn, 'list', [], $runLog, $jobId, $t);
        if ($list === null) {
            return 'snapshot-preflight';
        }
        $free = Snapshot::freeSpaceError($list['df']);
        if ($free !== null) {
            Logger::event($runLog, $jobId, $free);
            return 'snapshot-preflight';
        }
        $names = array_merge(array_column($list['entries'], 'name'), $list['stampish']);
        foreach (Snapshot::futureNames($names, Snapshot::now()) as $name) {
            Logger::event($runLog, $jobId, "'$name' in the snapshot root is dated more than a day in the future.");
        }
        $mono = Snapshot::checkMonotonic($sn['stamp'], $names);
        if ($mono !== null) {
            Logger::event($runLog, $jobId, $mono);
            return 'snapshot-clock';
        }

        // The transfer runs as the SSH login (plus --rsync-path), not as the
        // helper's root: a non-root login without a root wrapper cannot read
        // the 0700 root and would fail mid-run instead.
        $head = '';
        $exit = Rsync::run(
            Rsync::auxArgv(['--list-only', '--dirs', '--timeout=' . self::PROBE_TIMEOUT], $opts, $sshPieces, [$sn['userHost'] . ':' . $sn['root'] . '/']),
            static function (string $chunk) use (&$head): void {
                $head = substr($head . $chunk, 0, 4096);
            },
            Ssh::childEnv(is_array($sshPieces) ? (array) ($sshPieces['sshEnv'] ?? []) : [])
        );
        if ($exit !== 0) {
            Logger::event($runLog, $jobId, "The transfer's SSH login cannot read the snapshot root {$sn['root']} (rsync exit $exit); "
                . 'a non-root login needs a --rsync-path that runs rsync as root.' . ($head !== '' ? ' ' . Util::firstLine($head) : ''));
            return 'snapshot-preflight';
        }

        $complete = [];
        $inprogress = [];
        $deleting = [];
        foreach ($list['entries'] as $e) {
            if ($e['type'] === 'd') {
                $kind = Snapshot::classify($e['name']);
                if ($kind === 'complete') {
                    $complete[] = $e['name'];
                } elseif ($kind === 'inprogress') {
                    $inprogress[] = $e['name'];
                } elseif ($kind === 'deleting') {
                    $deleting[] = $e['name'];
                }
            }
        }
        sort($complete, SORT_STRING);
        sort($inprogress, SORT_STRING);
        sort($deleting, SORT_STRING);
        $prev = (string) end($complete);
        $subs = $prev !== '' ? $list['subs'] : [];

        $errors = [];
        $pairs  = (isset($job['pairs']) && is_array($job['pairs'])) ? $job['pairs'] : [];
        foreach ($pairs as $i => $pair) {
            $n     = (int) $i + 1;
            $local = trim((string) ($pair['local'] ?? ''));
            $sub   = trim((string) ($pair['sub'] ?? ''));
            // Before the pre-hook, so a source the pre-hook fills cannot be
            // emptied by the post-hook without this run noticing.
            $entries = self::$scanDir !== null ? (self::$scanDir)($local) : @scandir($local);
            if (!is_array($entries)) {
                $errors[] = "Pair #$n: the source $local does not exist or is not a directory.";
            } elseif (array_diff($entries, ['.', '..']) === []) {
                $errors[] = "Pair #$n: the source $local is empty; refusing to take an empty snapshot of it.";
            }
            $sn['basis'][$i] = in_array($sub, $subs, true);
            $seed = ($pair['seed'] ?? null) === true;
            if ($sn['basis'][$i] && $seed) {
                Logger::event($runLog, $jobId, "Pair #$n ($sub): $prev/$sub exists, so its seed flag has no effect and can be cleared.");
            } elseif (!$sn['basis'][$i] && $seed) {
                Logger::event($runLog, $jobId, "Pair #$n ($sub): new pair: full copy expected.");
            } elseif (!$sn['basis'][$i]) {
                $errors[] = "Pair #$n ($sub): there is no previous copy to link against ("
                    . ($prev === '' ? 'no complete snapshot yet' : "$prev has no $sub")
                    . '); set its seed flag to allow a full copy.';
            }
        }
        if ($errors !== []) {
            foreach ($errors as $e) {
                Logger::event($runLog, $jobId, $e);
            }
            return 'snapshot-preflight';
        }

        $meta            = $list['meta'];
        $sn['listing']   = $list;
        $sn['prev']      = $prev;
        $sn['prevMeta']  = (is_array($meta) && $meta['stamp'] === $prev && is_array($meta['data'])) ? $meta['data'] : null;
        $sn['dfBefore']  = $list['df'];
        // The lock is held and the sentinel ties the root to this job, so
        // every .inprogress is stale. The newest is kept as a link-dest only
        // if it started after the last commit: an older one would never be
        // superseded, so it would be kept, and pin old versions, forever.
        $newest          = (string) end($inprogress);
        $sn['stale']     = ($newest !== '' && strcmp((string) Snapshot::stampPrefix($newest), $prev) > 0) ? $newest : '';
        $sn['cleanup']   = array_merge($deleting, $sn['stale'] !== '' ? array_slice($inprogress, 0, -1) : $inprogress);
        Logger::event($runLog, $jobId, 'Snapshot root checked: previous snapshot ' . ($prev !== '' ? $prev : 'none')
            . ($sn['stale'] !== '' ? ", unfinished {$sn['stale']} reused as a link-dest" : '') . '.');
        return '';
    }

    /**
     * §4 step 6: remove leftover .deleting and every stale .inprogress but
     * the one kept as a link-dest. A failure never fails the run.
     *
     * @param array<string,mixed> $sn
     */
    private static function snapshotCleanup(array &$sn, string &$reason, string $runLog, string $jobId): void
    {
        if (!empty($sn['listing']['hold'])) {
            Logger::event($runLog, $jobId, "Deletions are on hold ({$sn['root']}/.ur-hold exists), so cleanup and prune are skipped "
                . 'until the admin removes it.');
            self::setReason($reason, 'snapshot-held');
            return;
        }
        if ($sn['cleanup'] !== []) {
            self::snapshotDelete($sn, $sn['cleanup'], 'Cleanup', $reason, $runLog, $jobId);
            // The space cleanup freed (even by a deletion that then failed)
            // would hide the transfer's growth.
            $check          = self::snapshotCall($sn, 'check', [], $runLog, $jobId, Snapshot::HELPER_TIMEOUT_SEC);
            $sn['dfBefore'] = $check['df'] ?? null;
            if ($check === null) {
                Logger::event($runLog, $jobId, 'Growth will not be measured: the free space could not be read again after cleanup.');
            }
        }
    }

    /**
     * Helper `delete` for each name in order, polling Abort between and during
     * deletions. The first failure stops the batch with prune-failed; a
     * deletion stopped by Abort does not (its .deleting is removed next run).
     * Returns how many were deleted.
     *
     * @param array<string,mixed> $sn
     * @param array<int,string>   $names
     */
    private static function snapshotDelete(array $sn, array $names, string $what, string &$reason, string $runLog, string $jobId): int
    {
        $stop = static fn(): bool => RunState::abortRequested($jobId);
        $done = 0;
        foreach ($names as $name) {
            if ($stop()) {
                Logger::event($runLog, $jobId, "Abort requested; $what stopped.");
                break;
            }
            Logger::event($runLog, $jobId, "$what: deleting $name.");
            if (self::snapshotCall($sn, 'delete', [$name], $runLog, $jobId, 0, $stop) === null) {
                if ($stop()) {
                    Logger::event($runLog, $jobId, "Abort requested; $what stopped.");
                } else {
                    self::setReason($reason, 'prune-failed');
                }
                break;
            }
            $done++;
        }
        return $done;
    }

    /**
     * --info=stats2 and the pair's link-dests: the previous snapshot's copy,
     * then the kept unfinished run's.
     *
     * @param array<string,mixed> $sn
     * @return array<int,string>
     */
    private static function snapshotLinkDests(array $sn, int|string $i, string $sub): array
    {
        $tokens = ['--info=stats2'];
        if (!empty($sn['basis'][$i])) {
            $tokens[] = "--link-dest={$sn['root']}/{$sn['prev']}/$sub";
        }
        if ($sn['stale'] !== '') {
            $tokens[] = "--link-dest={$sn['root']}/{$sn['stale']}/$sub";
        }
        return $tokens;
    }

    /**
     * @param array<string,mixed> $sn
     * @param array<int,string>   $missing paths rsync reported as a missing --link-dest
     * @return array{sub:string,exit:int,files:?int,bytes:?int,basisMissing:bool}
     */
    private static function snapshotPairResult(array $sn, int|string $i, string $sub, int $exit, string $captured, array $missing, string $runLog, string $jobId): array
    {
        $stats = Snapshot::parseStats($captured);
        $basis = "{$sn['root']}/{$sn['prev']}/$sub";
        $result = [
            'sub'          => $sub,
            'exit'         => $exit,
            'files'        => $stats['regFiles'],
            'bytes'        => $stats['totalSize'],
            'basisMissing' => !empty($sn['basis'][$i]) && in_array($basis, $missing, true),
        ];
        Logger::event($runLog, $jobId, sprintf(
            'Pair #%d (%s): %s regular files, %s bytes.',
            (int) $i + 1,
            $sub,
            $result['files'] ?? 'unknown',
            $result['bytes'] ?? 'unknown'
        ));
        return $result;
    }

    /**
     * Consecutive real runs, newest first in History, that did not commit a
     * snapshot. Dry runs and aborts are skipped; a commit, or a record with no
     * snapshot fields (a mirror run, a crash, a hard failure), ends the count.
     */
    public static function notCommittedRuns(string $jobId): int
    {
        $n = 0;
        for ($offset = 0; ; $offset += 100) {
            $page = History::list($jobId, $offset, 100);
            foreach ($page['runs'] as $r) {
                if (!empty($r['dryRun']) || ($r['state'] ?? '') === Rsync::STATE_ABORTED) {
                    continue;
                }
                if (!array_key_exists('snapshot', $r) || !empty($r['committed'])) {
                    return $n;
                }
                $n++;
            }
            if ($offset + 100 >= $page['total']) {
                return $n;
            }
        }
    }

    /**
     * Growth of this job's earlier committed runs, newest first (History).
     *
     * @return array<int,int>
     */
    private static function pastGrowthKB(string $jobId): array
    {
        $out = [];
        foreach (History::list($jobId, 0, 100)['runs'] as $r) {
            if (!empty($r['committed']) && isset($r['growthKB'])) {
                $out[] = (int) $r['growthKB'];
            }
        }
        return $out;
    }

    /**
     * §4 step 10 once the gate passed: re-check the root, the anomaly check
     * (hold + pin when suspect), then commit with the metadata. Returns '' or
     * the failure reason.
     *
     * @param array<string,mixed>            $sn
     * @param array<int,array<string,mixed>> $pairResults
     */
    private static function snapshotCommit(array &$sn, array $pairResults, string $runLog, string $jobId): string
    {
        $stamp = $sn['stamp'];
        $check = self::snapshotCall($sn, 'check', [], $runLog, $jobId, Snapshot::HELPER_TIMEOUT_SEC);
        if ($check === null) {
            Logger::event($runLog, $jobId, "Snapshot $stamp was not committed; $stamp.inprogress is kept for the next run.");
            return self::abortedOr('snapshot-commit-failed', $jobId);
        }

        $growth = is_array($sn['dfBefore']) ? $check['df']['used'] - $sn['dfBefore']['used'] : null;
        $a = Snapshot::anomalies($pairResults, $sn['prevMeta'], $growth, self::pastGrowthKB($jobId));
        foreach ($a['skipped'] as $s) {
            Logger::event($runLog, $jobId, "Anomaly check: $s.");
        }
        if ($a['suspect'] !== []) {
            $sn['suspect'] = true;
            foreach ($a['suspect'] as $s) {
                Logger::event($runLog, $jobId, "SUSPECT: $s.");
            }
            Logger::event($runLog, $jobId, 'This run is suspect: deletions are put on hold, the previous snapshot is pinned, then it is committed.');
            // Unprotected, the suspect copy would become the next run's baseline
            // and a later prune could remove the good snapshots.
            if (self::snapshotCall($sn, 'hold', [$stamp], $runLog, $jobId, Snapshot::HELPER_TIMEOUT_SEC) === null
                || ($sn['prev'] !== '' && self::snapshotCall($sn, 'pin', [$sn['prev']], $runLog, $jobId, Snapshot::HELPER_TIMEOUT_SEC) === null)) {
                Logger::event($runLog, $jobId, "Snapshot $stamp was not committed because it could not be protected; $stamp.inprogress is kept for the next run.");
                return self::abortedOr('snapshot-commit-failed', $jobId);
            }
        }
        // Commit is not stoppable, so an Abort that arrived after the last pair
        // is honoured here, before anything is renamed.
        if (RunState::abortRequested($jobId)) {
            Logger::event($runLog, $jobId, "Abort requested; snapshot $stamp was not committed.");
            return 'aborted';
        }

        $subs = [];
        foreach ($pairResults as $p) {
            $subs[$p['sub']] = ['exit' => $p['exit'], 'files' => $p['files'], 'bytes' => $p['bytes']];
        }
        $meta = json_encode([
            'stamp'   => $stamp,
            'jobId'   => $sn['args'][3],
            'suspect' => $sn['suspect'],
            'df'      => ['before' => $sn['dfBefore'], 'after' => $check['df']],
            'subs'    => $subs,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($meta)
            || self::snapshotCall($sn, 'commit', [$stamp, base64_encode($meta)], $runLog, $jobId, Snapshot::COMMIT_TIMEOUT_SEC) === null) {
            Logger::event($runLog, $jobId, "Snapshot $stamp was not committed; $stamp.inprogress is kept for the next run.");
            return self::abortedOr('snapshot-commit-failed', $jobId);
        }
        $sn['committed'] = true;
        $sn['growthKB']  = $growth;
        Logger::event($runLog, $jobId, "Committed snapshot $stamp." . ($growth !== null ? " The filesystem grew by $growth KB." : ''));
        return '';
    }

    /**
     * §6. A real run prunes only after a commit, unaborted and unsuspected;
     * a dry run logs the plan for the current listing. Never throws.
     *
     * @param array<string,mixed> $sn
     */
    private static function snapshotPrune(array &$sn, bool $dryRun, string &$reason, string $runLog, string $jobId): void
    {
        try {
            $now = Snapshot::now();
            if ($dryRun) {
                if (is_array($sn['listing']) && $sn['prev'] !== '') {
                    Logger::event($runLog, $jobId, 'Dry run: the prune plan for the current listing (nothing is deleted):');
                    self::logPrunePlan(Snapshot::planPrune($sn['listing'], $sn['snap'], $now, $sn['prev']), $runLog, $jobId);
                }
                return;
            }
            if (!$sn['committed']) {
                return;
            }
            if (RunState::abortRequested($jobId)) {
                Logger::event($runLog, $jobId, 'Abort requested; prune skipped.');
                return;
            }
            if ($sn['suspect']) {
                Logger::event($runLog, $jobId, 'Prune skipped: this run is suspect.');
                return;
            }
            $list = self::snapshotCall($sn, 'list', [], $runLog, $jobId, Snapshot::HELPER_TIMEOUT_SEC);
            if ($list === null && self::abortedOr('prune-sanity', $jobId) === 'aborted') {
                Logger::event($runLog, $jobId, 'Abort requested; prune skipped.');
                return;
            }
            if ($list === null) {
                Logger::event($runLog, $jobId, 'Prune aborted: the snapshot root could not be listed after the commit.');
                self::setReason($reason, 'prune-sanity');
                return;
            }
            $plan = Snapshot::planPrune($list, $sn['snap'], $now, $sn['stamp']);
            self::logPrunePlan($plan, $runLog, $jobId);
            if ($plan['abort'] !== null) {
                self::setReason($reason, 'prune-sanity');
                return;
            }
            if ($plan['held']) {
                Logger::event($runLog, $jobId, "Prune skipped: deletions are on hold ({$sn['root']}/.ur-hold exists).");
                self::setReason($reason, 'snapshot-held');
                return;
            }
            if ($sn['snap']['pruneDryRun']) {
                Logger::event($runLog, $jobId, 'Prune dry run is on (pruneDryRun), so nothing was deleted.');
                return;
            }
            $sn['pruned'] = self::snapshotDelete($sn, $plan['delete'], 'Prune', $reason, $runLog, $jobId);
            if ($sn['pruned'] < count($plan['delete'])) {
                return;
            }
            if ($plan['backlog'] > 0) {
                Logger::event($runLog, $jobId, "{$plan['backlog']} deletion(s) carried over to later runs (maxPrunePerRun is {$sn['snap']['maxPrunePerRun']}).");
                self::setReason($reason, 'prune-backlog');
            }
        } catch (Throwable $e) {
            Logger::event($runLog, $jobId, 'Prune failed with an internal error: ' . $e->getMessage());
            self::setReason($reason, 'prune-failed');
        }
    }

    /** @param array<string,mixed> $plan Snapshot::planPrune() result */
    private static function logPrunePlan(array $plan, string $runLog, string $jobId): void
    {
        foreach ($plan['keep'] as $name => $why) {
            Logger::event($runLog, $jobId, "Prune plan: KEEP $name (" . implode(', ', array_unique($why)) . ').');
        }
        foreach ($plan['delete'] as $name) {
            Logger::event($runLog, $jobId, "Prune plan: DELETE $name.");
        }
        foreach ($plan['future'] as $name) {
            Logger::event($runLog, $jobId, "Prune plan: $name is dated in the future; neither kept nor deleted.");
        }
        if ($plan['backlog'] > 0) {
            Logger::event($runLog, $jobId, "Prune plan: {$plan['backlog']} more deletion(s) wait for later runs.");
        }
        if ($plan['abort'] !== null) {
            Logger::event($runLog, $jobId, 'Prune aborted, nothing is deleted: ' . $plan['abort']);
        }
    }

    /**
     * Build the environment array passed to a hook. The post-run hook also gets
     * the outcome (UR_JOB_STATUS, UR_EXIT_CODE); the pre-run hook gets the same
     * keys with empty/0 values so a hook can rely on them existing. UR_TRIGGER
     * is 'manual' or 'schedule' so a hook can branch on how the run was started.
     * $extra carries a snapshot job's UR_SNAPSHOT / UR_SNAPSHOT_STATUS.
     *
     * @param array<string,mixed>  $job
     * @param array<string,string> $extra
     * @return array<string,string>
     */
    public static function hookEnv(array $job, string $jobId, bool $dryRun, ?string $status = null, ?int $exitCode = null, string $trigger = 'manual', array $extra = []): array
    {
        return [
            'UR_JOB_ID'     => $jobId,
            'UR_JOB_NAME'   => (string) ($job['name'] ?? ''),
            'UR_DRY_RUN'    => $dryRun ? '1' : '0',
            'UR_TRIGGER'    => ($trigger === 'schedule') ? 'schedule' : 'manual',
            'UR_JOB_STATUS' => $status === null ? '' : $status,
            'UR_EXIT_CODE'  => $exitCode === null ? '' : (string) $exitCode,
        ] + $extra;
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
        // the stop request are polled until it has. The status that first
        // shows the exit is the only one that reports a signal; a later
        // proc_get_status() call no longer does.
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

        $code = proc_close($proc);
        if ($kill === 'timeout') {
            return self::HOOK_TIMED_OUT;
        }
        if ($kill === 'stop') {
            return 143;
        }
        if ($status['signaled']) {
            return 128 + $status['termsig'];
        }
        if ($status['exitcode'] >= 0) {
            return $status['exitcode'];
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
     * <UR_CONFIG_BASE>/runs/<jobid>.summary.json via Util::writeAtomic, under
     * the sibling <jobid>.summary.lock (it is a load-modify-save). Never throws:
     * a summary failure must not crash the run.
     *
     * The top level is the last execution of any kind (its shape is what older
     * readers know); `lastReal` is the last non-dry run's {state, startedAt,
     * finishedAt, exitCode, reason}. A dry run leaves lastReal as it was.
     * $keepNewer (crash and hard-fail records) never replaces a top level or a
     * lastReal that started later than $summary: a live or later run wins.
     *
     * @param array<string,mixed> $summary
     * @return bool false when the summary could not be written
     */
    public static function writeSummary(string $jobId, array $summary, bool $keepNewer = false): bool
    {
        $path = self::summaryPath($jobId);
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $trigger = (($summary['trigger'] ?? '') === 'schedule') ? 'schedule' : 'manual';
        $out = [
            'state'       => (string) ($summary['state'] ?? Rsync::STATE_FAILED),
            'startedAt'   => (string) ($summary['startedAt'] ?? ''),
            'finishedAt'  => (string) ($summary['finishedAt'] ?? ''),
            'exitCode'    => array_key_exists('exitCode', $summary) && $summary['exitCode'] === null ? null : (int) ($summary['exitCode'] ?? 1),
            'durationSec' => (int) ($summary['durationSec'] ?? 0),
            'dryRun'      => !empty($summary['dryRun']),
            'trigger'     => $trigger,
        ];
        if ((string) ($summary['reason'] ?? '') !== '') {
            $out['reason'] = (string) $summary['reason'];
        }
        if (array_key_exists('snapshot', $summary)) {
            $out['snapshot']  = (string) $summary['snapshot'];
            $out['committed'] = !empty($summary['committed']);
        }
        try {
            return Util::withLock(self::summaryPath($jobId, 'lock'), static function () use ($jobId, $path, $out, $keepNewer): bool {
                $prev     = self::readSummary($jobId);
                $prevReal = self::lastRealFor($jobId, $prev, false);
                $newer    = static fn(?array $old): bool => $keepNewer && $old !== null
                    && strcmp((string) ($old['startedAt'] ?? ''), $out['startedAt']) > 0;
                $real = ($out['dryRun'] || $newer($prevReal)) ? $prevReal : self::lastReal($out);
                if ($prev !== null && $newer($prev)) {
                    if ($real === $prevReal) {
                        return true;
                    }
                    unset($prev['lastReal']);
                    $out = $prev;
                }
                // null too: "no real run yet" must not read as an old summary.
                $out['lastReal'] = $real;
                $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    return false;
                }
                Util::writeAtomic($path, $json . "\n", 0644);
                return true;
            });
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The last non-dry run of a summary: {state, startedAt, finishedAt,
     * exitCode, reason}, or null when there has been none. A summary written
     * before lastReal existed is its own last real run unless it was a dry run.
     *
     * @param array<string,mixed>|null $summary
     * @return array{state:string,startedAt:string,finishedAt:string,exitCode:int|null,reason:string}|null
     */
    public static function lastReal(?array $summary): ?array
    {
        if (!is_array($summary)) {
            return null;
        }
        if (array_key_exists('lastReal', $summary)) {
            $summary = is_array($summary['lastReal']) ? $summary['lastReal'] : null;
        } elseif (!empty($summary['dryRun'])) {
            $summary = null;
        }
        if ($summary === null) {
            return null;
        }
        return [
            'state'      => (string) ($summary['state'] ?? ''),
            'startedAt'  => (string) ($summary['startedAt'] ?? ''),
            'finishedAt' => (string) ($summary['finishedAt'] ?? ''),
            'exitCode'   => isset($summary['exitCode']) ? (int) $summary['exitCode'] : null,
            'reason'     => (string) ($summary['reason'] ?? ''),
        ];
    }

    /** <UR_CONFIG_BASE>/runs/<jobid>.summary.json, or its sibling .summary.lock. */
    private static function summaryPath(string $jobId, string $ext = 'json'): string
    {
        return rtrim(UR_CONFIG_BASE, '/') . '/runs/' . Util::safeFileId($jobId) . '.summary.' . $ext;
    }

    /**
     * lastReal(), except for a summary written before lastReal existed whose
     * last execution was a dry run: the real outcome it overwrote survives only
     * in History, so the newest real record there (every retained one is
     * searched) stands in. The answer, null included, is then written into
     * summary.json ($writeThrough), so History is read once per job, not on
     * every poll; never over a summary that changed since $summary was read.
     *
     * @param array<string,mixed>|null $summary
     * @return array{state:string,startedAt:string,finishedAt:string,exitCode:int|null,reason:string}|null
     */
    public static function lastRealFor(string $jobId, ?array $summary, bool $writeThrough = true): ?array
    {
        if (!is_array($summary) || array_key_exists('lastReal', $summary) || empty($summary['dryRun'])) {
            return self::lastReal($summary);
        }
        $real = null;
        foreach (History::records($jobId) as $r) {
            if (empty($r['dryRun']) && ($r['state'] ?? '') !== Rsync::STATE_SKIPPED) {
                $real = self::lastReal($r);
                break;
            }
        }
        $json = json_encode($summary + ['lastReal' => $real], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($writeThrough && $json !== false) {
            try {
                Util::withLock(self::summaryPath($jobId, 'lock'), static function () use ($jobId, $summary, $json): void {
                    if (self::readSummary($jobId) === $summary) {
                        Util::writeAtomic(self::summaryPath($jobId), $json . "\n", 0644);
                    }
                });
            } catch (Throwable $e) {
                // Still answered; the next read resolves it again.
            }
        }
        return $real;
    }

    /**
     * Log ONE plugin.log line naming what of a run's outcome could not be
     * persisted ($what empty = nothing failed). Never throws: the log dir may be
     * the thing that is broken, so error_log is the last resort.
     *
     * @param array<int,string> $what
     */
    private static function reportPersistFailure(string $jobId, array $what): void
    {
        if ($what === []) {
            return;
        }
        self::pluginLine('', $jobId, 'Could not write the ' . implode(' and ', $what) . ' to '
            . rtrim(UR_CONFIG_BASE, '/') . "/runs; this run's outcome may be missing from the UI.");
    }

    /** One line naming a teardown step of run() that threw; the others still ran. */
    private static function reportTeardownFailure(string $jobId, string $runLog, string $step, Throwable $e): void
    {
        self::pluginLine($runLog, $jobId, Logger::scrubSecretPaths(
            'Run teardown step "' . $step . '" failed: ' . rtrim($e->getMessage(), '.') . '. The remaining steps still ran.'
        ));
    }

    /** Logger::event, falling back to error_log when plugin.log is unusable. Never throws. */
    private static function pluginLine(string $runLog, string $jobId, string $msg): void
    {
        try {
            if (Logger::event($runLog, $jobId, $msg)) {
                return;
            }
        } catch (Throwable $e) {
            // fall through to error_log
        }
        error_log('unraid.rsync [' . $jobId . '] ' . $msg);
    }

    /**
     * The outcome could not be recorded (summary, History or run state): a
     * clean run becomes FAILED with reason teardown-failed; any other state
     * already reports a problem and keeps it.
     */
    private static function teardownFailed(string &$state, string &$reason): void
    {
        if ($state === Rsync::STATE_SUCCESS) {
            $state  = Rsync::STATE_FAILED;
            $reason = 'teardown-failed';
        } elseif ($reason === '') {
            $reason = 'teardown-failed';
        }
    }

    /**
     * Read a job's last-run summary, or null when none exists/unreadable.
     *
     * @return array<string,mixed>|null
     */
    public static function readSummary(string $jobId): ?array
    {
        $path = self::summaryPath($jobId);
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
     * A hard, pre-run failure: nothing was started (no state/log to keep). Logs
     * to the plugin log, records a scheduled run in History and the summary (so
     * the UI shows the run that never happened), notifies, and returns a FAILED
     * result.
     * 'already-running' is SKIPPED instead, not a failure: it keeps its own
     * reason so the handler can 409; started by hand it does not notify (the UI
     * already said so), by the schedule it notifies at normal importance. A job
     * that could not be read has no notifyMode to honour, so it always notifies
     * - that is the case most worth hearing about.
     *
     * @param array<string,mixed>|null $job null = config/job unreadable
     * @return array{state:string,exitCode:int,runLog:string,reason:string}
     */
    private static function hardFail(
        string $jobId,
        string $message,
        string $reason,
        string $trigger,
        bool $dryRun,
        ?array $job = null,
        int $retention = Config::DEFAULT_RETENTION
    ): array {
        $skipped  = $reason === 'already-running';
        $state    = $skipped ? Rsync::STATE_SKIPPED : Rsync::STATE_FAILED;
        $exitCode = $skipped ? 0 : 1;
        $name     = (string) ($job['name'] ?? $jobId);
        try {
            Logger::event('', $jobId, 'Run could not start: ' . $message);
        } catch (Throwable $e) {
            // ignore - logging is best-effort on the hard-fail path.
        }

        if ($trigger === 'schedule') {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            // SKIPPED keeps the last real outcome on display: History only.
            $summaryOk = $skipped || self::writeSummary($jobId, [
                'state'       => $state,
                'startedAt'   => $now,
                'finishedAt'  => $now,
                'exitCode'    => null,
                'durationSec' => 0,
                'dryRun'      => $dryRun,
                'trigger'     => $trigger,
                'reason'      => $reason,
            ], true);
            $ok  = History::append($jobId, [
                'startedAt'   => $now,
                'finishedAt'  => $now,
                'jobName'     => $name,
                'dryRun'      => $dryRun,
                'trigger'     => $trigger,
                'state'       => $state,
                'exitCode'    => $exitCode,
                'durationSec' => 0,
                'logRef'      => '',
                'reason'      => $reason,
            ]);
            self::reportPersistFailure($jobId, array_keys(array_filter([
                'last-run summary' => !$summaryOk,
                'History record'   => !$ok,
            ])));
            History::prune($jobId, $retention);
        }

        if (!($skipped && $trigger === 'manual')) {
            self::notifyHook($job ?? ['id' => $jobId, 'name' => $jobId, 'notifyMode' => self::NOTIFY_ALWAYS], [
                'state'       => $state,
                'exitCode'    => $exitCode,
                'reason'      => $reason,
                'trigger'     => $trigger,
                'dryRun'      => $dryRun,
                'durationSec' => 0,
                'subject'     => $name . ': run did not start',
                'message'     => $skipped
                    ? sprintf('Job "%s": previous run still running, scheduled run skipped.', $name)
                    : sprintf('Job "%s": run did not start. %s', $name, $message),
            ]);
        }

        return [
            'state'    => $state,
            'exitCode' => $exitCode,
            'runLog'   => '',
            'reason'   => $reason,
        ];
    }

    /**
     * A runner died without finishing (RunState found running=true with no
     * live runner behind it). Its finally never ran: no summary, no history,
     * no post-hook, no notification. Record it (History FAILED/crashed, the
     * summary CRASHED unless a later run already wrote one) and alert it here;
     * RunState guarantees this is called once per stale state. Never throws.
     *
     * @param array<string,mixed> $state the dead run's RunState record
     */
    public static function reportCrashed(array $state): void
    {
        try {
            Ssh::sweepRuntime((string) ($state['token'] ?? ''));
            $jobId  = (string) ($state['jobId'] ?? '');
            $config = null;
            $job    = null;
            try {
                $config = Config::load();
                $job    = self::findJob($config, $jobId);
            } catch (Throwable $e) {
                // Unreadable config: still record and alert, by id.
            }
            // The runner died after recording its own outcome (between History
            // and markStopped): it finished, there is nothing to report.
            $startedAt = (string) ($state['startedAt'] ?? '');
            $newest    = History::list($jobId, 0, 1)['runs'][0] ?? null;
            if ($startedAt !== '' && $newest !== null && $newest['startedAt'] === $startedAt) {
                return;
            }
            $name    = (string) ($job['name'] ?? $jobId);
            $trigger = (($state['trigger'] ?? '') === 'schedule') ? 'schedule' : 'manual';
            $dryRun  = !empty($state['dryRun']);
            $started = strtotime($startedAt);
            // tmpfs logs do not survive the reboot that may have killed the run.
            $log     = (string) ($state['currentLog'] ?? '');
            $message = sprintf(
                'Job "%s": previous run died without finishing (pid %d, started %s). '
                . 'The post-hook did not run, services stopped by the pre-hook may still be down.',
                $name,
                (int) ($state['pid'] ?? 0),
                $started === false ? 'unknown' : date('Y-m-d H:i:sP', $started)
            );
            $stamp = is_string($state['snapshot'] ?? null) ? $state['snapshot'] : '';
            if (!$dryRun && Snapshot::isStamp($stamp)) {
                // The run may have died before mkstamp or after the commit.
                $message .= " Snapshot $stamp may be unfinished: if $stamp.inprogress was left on the receiver, it will be reused as a link-dest by the next run.";
            }
            try {
                Logger::event('', $jobId, $message);
            } catch (Throwable $e) {
                // An unwritable log must not swallow the record and the alert.
            }

            $finishedAt = gmdate('Y-m-d\TH:i:s\Z');
            $summaryOk  = self::writeSummary($jobId, [
                'state'       => Rsync::STATE_CRASHED,
                'startedAt'   => $startedAt,
                'finishedAt'  => $finishedAt,
                'exitCode'    => null,
                'durationSec' => 0,
                'dryRun'      => $dryRun,
                'trigger'     => $trigger,
                'reason'      => 'crashed',
            ], true);
            $ok = History::append($jobId, [
                'startedAt'   => $startedAt,
                'finishedAt'  => $finishedAt,
                'jobName'     => $name,
                'dryRun'      => $dryRun,
                'trigger'     => $trigger,
                'state'       => Rsync::STATE_FAILED,
                'exitCode'    => 1,
                'durationSec' => 0,
                'logRef'      => is_file($log) ? Logger::runIdFromPath($log) : '',
                'reason'      => 'crashed',
            ]);
            self::reportPersistFailure($jobId, array_keys(array_filter([
                'last-run summary' => !$summaryOk,
                'History record'   => !$ok,
            ])));
            History::prune($jobId, Config::clampRetention($config['global']['retention'] ?? Config::DEFAULT_RETENTION));

            self::notifyHook($job ?? ['id' => $jobId, 'name' => $jobId, 'notifyMode' => self::NOTIFY_ALWAYS], [
                'state'       => Rsync::STATE_FAILED,
                'exitCode'    => 1,
                'reason'      => 'crashed',
                'trigger'     => $trigger,
                'dryRun'      => $dryRun,
                'durationSec' => null,
                'subject'     => $name . ': previous run died without finishing',
                'message'     => $message,
            ]);
        } catch (Throwable $e) {
            // Best-effort: reached from status polls, never let it break one.
        }
    }
}

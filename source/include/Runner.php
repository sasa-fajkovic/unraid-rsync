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
require_once __DIR__ . '/Notify.php';
require_once __DIR__ . '/History.php';

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
     * job, sshpass missing, guardrail violation, rsync non-zero - are reported
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
                    $mat       = $matResult['mat'];
                    $sshPieces = [
                        'dashE'         => (string) $mat['dashE'],
                        'sshpassPrefix' => (array) $mat['sshpassPrefix'],
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
            }

            // 5. preHook (only if SSH prep didn't already fail).
            $hookEnvBase = self::hookEnv($job, $jobId, $dryRun, null, null, $trigger);
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

            // 5b. Defensive rsync presence check. rsync ships in Unraid's base OS
            //     at /usr/bin/rsync, so it should ALWAYS be present; the plugin
            //     does NOT install it. Guard against a broken system: if the
            //     binary is missing, fail the run with a clear logged error
            //     BEFORE any pair runs (postHook still fires via finally). We do
            //     NOT attempt to install anything.
            if ($state !== Rsync::STATE_FAILED && !Rsync::rsyncAvailable()) {
                $state    = Rsync::STATE_FAILED;
                $exitCode = 1;
                $reason   = 'rsync-missing';
                Logger::event($runLog, $jobId, Rsync::rsyncMissingMessage());
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

                    // Stream rsync's captured stdout/stderr through Logger::sink,
                    // which REDACTS armed per-run secret paths (F1) and enforces
                    // the per-run-log byte cap (F3) before bytes hit the log.
                    $pairExit = Rsync::run($argv, Logger::sink($runLog));
                    // rsync ALSO writes the run log directly via --log-file, which
                    // bypasses the sink's cap; trim the file to the cap now that
                    // this pair's rsync has closed --log-file (F3, complete).
                    Logger::enforceRunLogCap($runLog);
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
            // 7. postHook ALWAYS runs (even on failure/abort), with the outcome
            //    in its environment.
            $postHook = (string) ($job['postHook'] ?? '');
            if (trim($postHook) !== '') {
                $postEnv = self::hookEnv($job, $jobId, $dryRun, $state, $exitCode, $trigger);
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

            // 8. Cleanup the SSH runtime secrets for THIS run's token, and disarm
            //    the secret-path redaction armed at materialisation (F1).
            if ($token !== '') {
                Ssh::cleanupRuntime($token);
            }
            Logger::clearRedaction();

            // 9. Persist the last-run summary to /boot + clear running state.
            $finishedAtTs = time();
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
     * @param string              $transport SSH | LOCAL
     * @param string              $userHost  pre-resolved "user@host" (SSH only)
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
     * @param array<string,string> $env
     */
    private static function runHook(string $hook, array $env, string $runLog): int
    {
        // Hook stdout/stderr (captured + browser-visible) flows through the same
        // redacting, size-capped sink as rsync output (F1 + F3).
        $sink = Logger::sink($runLog);

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

        // Drain stdout AND stderr concurrently with a non-blocking select loop.
        // Reading one pipe fully before the other can DEADLOCK: a hook that fills
        // the stderr pipe buffer while we are still blocked on stdout would hang
        // forever (and vice versa).
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while (!empty($open)) {
            $read = array_values($open);
            $w = null;
            $x = null;
            $n = @stream_select($read, $w, $x, 1);
            if ($n === false) {
                break;
            }
            foreach ($open as $fd => $stream) {
                if (!in_array($stream, $read, true)) {
                    continue;
                }
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$fd]);
                    }
                    continue;
                }
                $onOutput($chunk);
            }
        }

        // Close any pipe stream_select left open (e.g. on a select error that
        // broke the loop before EOF), mirroring KeyTools::runArgv /
        // Rsync::defaultRun. proc_close + the group SIGTERM still reap the child,
        // so this is fd hygiene, not a deadlock fix. [ROB-01]
        foreach ($open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // Capture signal status before proc_close (see Rsync::defaultRun).
        $status = @proc_get_status($proc);
        $code   = proc_close($proc);
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

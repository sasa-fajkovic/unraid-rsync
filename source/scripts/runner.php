<?php

declare(strict_types=1);

/**
 * runner.php - the CLI entry point that actually runs a job.
 *
 * Invoked detached by handler.php's runJob / dryRunJob actions, and by the
 * per-job cron line (with --trigger=schedule):
 *
 *   php /usr/local/emhttp/plugins/unraid.rsync/scripts/runner.php --job=<id> [--dry-run] [--trigger=<manual|schedule>]
 *
 * It is a THIN shell: it parses --job / --dry-run, then delegates ALL the
 * orchestration (state, logging, SSH transport, preHook -> pairs -> postHook,
 * abort poll, exit-code -> state, /boot summary) to Runner::run() in
 * include/Runner.php, which is unit-tested with fake rsync + fake hooks.
 *
 * The process exit code mirrors the run's final state so a caller (cron, the
 * detached launcher) can tell success from failure:
 *   SUCCESS -> 0, WARNING/PARTIAL -> 0 (run completed), SKIPPED -> 0 (the
 *   previous run is still going), TIMEOUT/FAILED -> 1,
 *   ABORTED -> 143. (The authoritative outcome is the /boot summary; this exit
 *   code is just a convenience for shell callers.)
 *
 * NB: the cmdline MUST contain "runner.php" and "--job=<id>" because
 * RunState::isRunning() verifies a recorded pid's /proc cmdline against exactly
 * those tokens (PID-reuse safety). Do not rename this file or change the
 * --job=<id> flag spelling without updating RunState::cmdlineMatchesJob().
 */

require_once __DIR__ . '/../include/Runner.php';

/**
 * Parse the CLI args into the associative array
 * ['job' => <id>, 'dryRun' => bool, 'trigger' => <manual|schedule>]. Accepts
 * --job=<id> and --job <id>, --dry-run, and --trigger=<manual|schedule>. Unknown args are
 * ignored. `trigger` defaults to 'manual' and is clamped to the closed set
 * (anything unrecognised -> 'manual') so a junk value can never propagate.
 *
 * @param array<int,string> $argv
 * @return array{job:string,dryRun:bool,trigger:string}
 */
function ur_runner_parse_args(array $argv): array
{
    $job     = '';
    $dryRun  = false;
    $trigger = 'manual';
    $n       = count($argv);
    for ($i = 1; $i < $n; $i++) {
        $arg = $argv[$i];
        if ($arg === '--dry-run') {
            $dryRun = true;
        } elseif (strncmp($arg, '--job=', 6) === 0) {
            $job = substr($arg, 6);
        } elseif ($arg === '--job' && $i + 1 < $n) {
            $job = $argv[++$i];
        } elseif (strncmp($arg, '--trigger=', 10) === 0) {
            $trigger = substr($arg, 10);
        }
    }
    if ($trigger !== 'schedule') {
        $trigger = 'manual';
    }
    return ['job' => trim($job), 'dryRun' => $dryRun, 'trigger' => $trigger];
}

/**
 * Map a final run state to a process exit code (convenience for shell callers).
 */
function ur_runner_exit_code(string $state): int
{
    switch ($state) {
        case Rsync::STATE_SUCCESS:
        case Rsync::STATE_WARNING:
        case Rsync::STATE_PARTIAL:
        case Rsync::STATE_SKIPPED:
            return 0;
        case Rsync::STATE_ABORTED:
            return 143;
        default: // TIMEOUT, FAILED
            return 1;
    }
}

/**
 * Make this process a session (and process-group) leader unless it already is.
 * Abort SIGTERMs the runner's process GROUP to reach rsync and ssh; the UI
 * launcher starts us under setsid, but crond does not, so a scheduled run's
 * group is crond's job shell and the signal could miss our children. Returns
 * whether setsid was called. $posix is a test seam: ['getsid' => fn(): int,
 * 'setsid' => fn(): int, 'pid' => int]; null = the real posix functions, and a
 * no-op when ext-posix is missing.
 *
 * @param array{getsid:callable,setsid:callable,pid:int}|null $posix
 */
function ur_runner_ensure_session_leader(?array $posix = null): bool
{
    if ($posix === null) {
        if (!function_exists('posix_setsid') || !function_exists('posix_getsid')) {
            return false;
        }
        $posix = [
            'getsid' => static fn() => posix_getsid(0),
            'setsid' => static fn() => posix_setsid(),
            'pid'    => (int) getmypid(),
        ];
    }
    if (($posix['getsid'])() === $posix['pid']) {
        return false;
    }
    ($posix['setsid'])();
    return true;
}

// Only run when invoked as a script (not when required by a test harness).
if (PHP_SAPI === 'cli' && !defined('UR_RUNNER_TESTING')) {
    ur_runner_ensure_session_leader();
    $args = ur_runner_parse_args($argv ?? []);
    if ($args['job'] === '') {
        fwrite(STDERR, "usage: runner.php --job=<id> [--dry-run] [--trigger=manual|schedule]\n");
        exit(2);
    }
    $result = Runner::run($args['job'], $args['dryRun'], $args['trigger']);
    exit(ur_runner_exit_code((string) $result['state']));
}

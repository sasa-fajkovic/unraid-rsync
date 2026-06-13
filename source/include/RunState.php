<?php
/**
 * RunState.php - per-job RUNTIME state, held in RAM (tmpfs) so it never touches
 * the FAT32 flash and is cleared on reboot.
 *
 * Two files per job under <runtimeBase>/state/:
 *   <jobid>.json   {pid, running, dryRun, startedAt, currentLog}
 *   <jobid>.abort  a flag file (its mere existence means "abort requested")
 *
 * The interesting part is isRunning(): a stale state file (the worker was
 * killed, the box rebooted into the same pid space, etc.) must NOT make us
 * think a job is running and refuse a new launch forever. So isRunning() is
 * PID-REUSE-SAFE: it requires
 *   (1) the state file says running=true,
 *   (2) the recorded pid still exists (posix_kill(pid, 0)), AND
 *   (3) that pid's process is actually OUR runner for THIS job - its cmdline
 *       contains "runner.php" and "--job=<jobid>".
 * If (1) holds but (2)/(3) don't, the state is stale and we CLEAR it (so the UI
 * re-enables the Run button) and report not-running.
 *
 * Every live-system touch (posix_kill, reading /proc/<pid>/cmdline) goes through
 * a thin, overridable seam (pidAlive / pidCmdline) so the reuse-safe logic is
 * unit-testable without spawning a process: tests subclass RunState and stub
 * those two methods.
 *
 * The runtime base is overridable via the UR_RUNTIME_BASE constant (define it
 * before requiring this file - tests point it at a temp dir) so the same code
 * runs on a live box and under PHPUnit without writing to /tmp/unraid.rsync.
 */

if (!defined('UR_RUNTIME_BASE')) {
    define('UR_RUNTIME_BASE', '/tmp/unraid.rsync');
}

class RunState
{
    /**
     * The base for tmpfs runtime state. Resolved through a method (not a bare
     * constant read) so tests that need a per-test dir can also override via the
     * $baseOverride static without redefining the constant.
     *
     * @var string|null
     */
    public static $baseOverride = null;

    /** The runtime base dir actually in effect. */
    public static function base(): string
    {
        if (self::$baseOverride !== null && self::$baseOverride !== '') {
            return rtrim((string) self::$baseOverride, '/');
        }
        return rtrim(UR_RUNTIME_BASE, '/');
    }

    /** Dir holding the per-job state + abort files. */
    public static function stateDir(): string
    {
        return self::base() . '/state';
    }

    /** Absolute path of a job's state JSON. */
    public static function statePath(string $jobId): string
    {
        return self::stateDir() . '/' . self::safeId($jobId) . '.json';
    }

    /** Absolute path of a job's abort flag. */
    public static function abortPath(string $jobId): string
    {
        return self::stateDir() . '/' . self::safeId($jobId) . '.abort';
    }

    /**
     * Sanitise a job id for use as a filename segment. Job ids are slug-shaped
     * ("j-" + [a-z0-9-]) by construction, but defend against traversal anyway.
     */
    private static function safeId(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $id);
        return ($clean === '' || $clean === null) ? 'unknown' : $clean;
    }

    /** Ensure the state dir exists (mode 700; it holds pids/log paths only). */
    private static function ensureDir(): void
    {
        $dir = self::stateDir();
        if (is_link($dir)) {
            throw new RuntimeException("Refusing to use a symlinked state dir: $dir");
        }
        if (file_exists($dir) && !is_dir($dir)) {
            throw new RuntimeException("State path exists but is not a directory: $dir");
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create state dir: $dir");
        }
        @chmod($dir, 0700);
    }

    /**
     * Write a job's runtime state atomically (temp + rename). The shape is
     * fixed: {pid, running, dryRun, startedAt, currentLog}. Unknown keys passed
     * in are dropped so the file stays canonical.
     *
     * @param array<string,mixed> $state
     */
    public static function write(string $jobId, array $state): void
    {
        self::ensureDir();

        $out = [
            'jobId'      => $jobId,
            'pid'        => isset($state['pid']) ? (int) $state['pid'] : 0,
            'running'    => !empty($state['running']),
            'dryRun'     => !empty($state['dryRun']),
            'startedAt'  => isset($state['startedAt']) ? (string) $state['startedAt'] : '',
            'currentLog' => isset($state['currentLog']) ? (string) $state['currentLog'] : '',
        ];

        $path = self::statePath($jobId);
        $dir  = dirname($path);
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode run state JSON: ' . json_last_error_msg());
        }
        $tmp = @tempnam($dir, '.state.');
        if ($tmp === false) {
            throw new RuntimeException("Unable to create temp state file in: $dir");
        }
        if (@file_put_contents($tmp, $json . "\n") === false) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write temp state file: $tmp");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to atomically replace state file: $path");
        }
    }

    /**
     * Read a job's runtime state, or null when no state file exists or it is
     * unreadable/corrupt (a missing/garbage state file just means "not
     * running"). The returned array is always the canonical shape.
     *
     * @return array{jobId:string,pid:int,running:bool,dryRun:bool,startedAt:string,currentLog:string}|null
     */
    public static function read(string $jobId): ?array
    {
        $path = self::statePath($jobId);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return [
            'jobId'      => (string) ($data['jobId'] ?? $jobId),
            'pid'        => (int) ($data['pid'] ?? 0),
            'running'    => !empty($data['running']),
            'dryRun'     => !empty($data['dryRun']),
            'startedAt'  => (string) ($data['startedAt'] ?? ''),
            'currentLog' => (string) ($data['currentLog'] ?? ''),
        ];
    }

    /** Remove a job's state file (best-effort). */
    public static function clear(string $jobId): void
    {
        $path = self::statePath($jobId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Mark a job's state as no longer running, preserving the rest of the record
     * (so the UI/log viewer can still see the last currentLog) but flipping
     * running=false and zeroing the pid. If there is no state file this is a
     * no-op.
     */
    public static function markStopped(string $jobId): void
    {
        $state = self::read($jobId);
        if ($state === null) {
            return;
        }
        $state['running'] = false;
        $state['pid']     = 0;
        self::write($jobId, $state);
    }

    /**
     * Is this job currently running? PID-reuse-safe (see the class docblock):
     * requires running=true AND the pid alive AND the pid's cmdline to be our
     * runner for THIS job. A state that claims running but fails the liveness or
     * cmdline check is STALE - we clear it and report false.
     */
    public static function isRunning(string $jobId): bool
    {
        $state = self::read($jobId);
        if ($state === null || empty($state['running'])) {
            return false;
        }
        $pid = (int) $state['pid'];
        if ($pid <= 0 || !static::pidAlive($pid)) {
            // Recorded pid is gone -> the worker died without clearing state.
            self::markStopped($jobId);
            return false;
        }
        $cmdline = static::pidCmdline($pid);
        if (!self::cmdlineMatchesJob($cmdline, $jobId)) {
            // The pid was REUSED by an unrelated process -> definitely stale.
            self::markStopped($jobId);
            return false;
        }
        return true;
    }

    /**
     * True when a process command line is our runner for the given job id. We
     * require "runner.php" AND the EXACT "--job=<jobid>" token (terminated at a
     * token boundary). The cmdline from /proc is NUL-separated; we normalise to
     * spaces so the check works regardless of source.
     *
     * The token must end at a boundary (whitespace or end-of-string) so a job id
     * is never matched as a PREFIX of another - e.g. id "j-a" must NOT match a
     * runner of "j-a-b" (--job=j-a-b). Without the boundary, isRunning() could
     * report the wrong job as running (mis-disabling Run / mis-enabling Abort)
     * and undermine PID-reuse safety.
     */
    public static function cmdlineMatchesJob(string $cmdline, string $jobId): bool
    {
        if ($cmdline === '' || $jobId === '') {
            return false;
        }
        // /proc/<pid>/cmdline uses NUL separators; normalise to spaces so a
        // boundary-anchored search works regardless of source.
        $normalized = str_replace("\0", ' ', $cmdline);
        if (strpos($normalized, 'runner.php') === false) {
            return false;
        }
        // Match "--job=<id>" only when followed by whitespace or the string end,
        // so the id is a whole token, not a prefix of a longer one.
        $pattern = '/--job=' . preg_quote($jobId, '/') . '(\s|$)/';
        return preg_match($pattern, $normalized) === 1;
    }

    /** Has an abort been requested for this job? (the flag file exists) */
    public static function abortRequested(string $jobId): bool
    {
        return is_file(self::abortPath($jobId));
    }

    /** Request an abort by touching the flag file. */
    public static function requestAbort(string $jobId): void
    {
        self::ensureDir();
        @touch(self::abortPath($jobId));
    }

    /** Clear a job's abort flag (best-effort). Called on run start + end. */
    public static function clearAbort(string $jobId): void
    {
        $path = self::abortPath($jobId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // --- live-system seams (overridden in tests) ----------------------------

    /**
     * Is pid alive? Live impl uses posix_kill(pid, 0) (no signal sent, just an
     * existence/permission check). Falls back to /proc when ext-posix is absent.
     * Overridable in tests.
     */
    protected static function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            // posix_kill(pid, 0) sends no signal; it returns true when the
            // process exists AND we may signal it. When it returns false, the
            // process may still exist but be owned by another user (EPERM = 1):
            // that still means "alive", so treat EPERM as alive. ESRCH ("no such
            // process") is the only result that means "dead".
            if (@posix_kill($pid, 0)) {
                return true;
            }
            $errno = function_exists('posix_get_last_error') ? posix_get_last_error() : 0;
            $eperm = defined('PCNTL_EPERM') ? PCNTL_EPERM : 1; // EPERM is 1 on Linux.
            return $errno === $eperm;
        }
        return is_dir('/proc/' . $pid);
    }

    /**
     * Read a pid's command line (for the reuse-safe cmdline check). Live impl
     * reads /proc/<pid>/cmdline (NUL-separated). Returns '' when unavailable.
     * Overridable in tests.
     */
    protected static function pidCmdline(int $pid): string
    {
        if ($pid <= 0) {
            return '';
        }
        $path = '/proc/' . $pid . '/cmdline';
        if (!is_file($path)) {
            return '';
        }
        $raw = @file_get_contents($path);
        return is_string($raw) ? $raw : '';
    }
}

<?php

declare(strict_types=1);

/**
 * RunState.php - per-job RUNTIME state, held in RAM (tmpfs) so it never touches
 * the FAT32 flash and is cleared on reboot.
 *
 * Files per job under <runtimeBase>/state/:
 *   <jobid>.json   {jobId, pid, running, dryRun, startedAt, currentLog} plus the
 *                  optional secrets `token` and launch generation `gen`
 *   <jobid>.abort  the abort flag: its existence means "stop", its JSON body
 *                  {by: user|signal, at, gen} who asked and for which launch
 *   <jobid>.lock   an advisory flock target for the atomic concurrency guard
 *
 * The interesting part is isRunning(): a stale state file (the worker was
 * killed, the box rebooted into the same pid space, etc.) must NOT make us
 * think a job is running and refuse a new launch forever. So isRunning() is
 * PID-REUSE-SAFE: it requires
 *   (1) the state file says running=true,
 *   (2) the recorded pid still exists (posix_kill(pid, 0)), AND
 *   (3) that pid's process is actually OUR runner for THIS job - its cmdline
 *       contains "runner.php" and "--job=<jobid>".
 * If (1) holds but (2)/(3) don't, the state is stale: we CLEAR it (so the UI
 * re-enables the Run button), report not-running, and hand it to
 * Runner::reportCrashed exactly once (see reapStale).
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

if (!defined('UR_CONFIG_BASE')) {
    define('UR_CONFIG_BASE', '/boot/config/plugins/unraid.rsync');
}

require_once __DIR__ . '/Util.php';

class RunState
{
    /**
     * The base for tmpfs runtime state. Resolved through a method (not a bare
     * constant read) so tests that need a per-test dir can also override via the
     * $baseOverride static without redefining the constant.
     *
     * @var string|null
     */
    public static ?string $baseOverride = null;

    /**
     * Test seam: fn(): void, called by clearStaleAbort once it has taken the
     * flag it judges - where a concurrent abortJob would land. null => none.
     *
     * @var (\Closure():void)|null
     */
    public static ?\Closure $afterStaleAbortRead = null;

    /**
     * Test seam: fn(int $pid, int $sig): bool in place of posix_kill for
     * signalRun. null => posix_kill.
     *
     * @var (\Closure(int,int):bool)|null
     */
    public static ?\Closure $kill = null;

    /**
     * Test seam: fn(int $pgid, string $lockPath): bool in place of the /proc
     * scan in groupHoldsLock. null => the scan.
     *
     * @var (\Closure(int,string):bool)|null
     */
    public static ?\Closure $groupHoldsLock = null;

    /** Seconds Abort waits for a dead run's group to let go of the lock before SIGKILL. */
    public static float $abortKillGraceSec = 5.0;

    /** The runtime base dir actually in effect. */
    public static function base(): string
    {
        if (self::$baseOverride !== null && self::$baseOverride !== '') {
            return rtrim((string) self::$baseOverride, '/');
        }
        return Util::runtimeBase();
    }

    /** Dir holding the per-job state + abort files. */
    public static function stateDir(): string
    {
        return self::base() . '/state';
    }

    /** Absolute path of a job's state JSON. */
    public static function statePath(string $jobId): string
    {
        return self::stateDir() . '/' . Util::safeFileId($jobId) . '.json';
    }

    /** Absolute path of a job's abort flag. */
    public static function abortPath(string $jobId): string
    {
        return self::stateDir() . '/' . Util::safeFileId($jobId) . '.abort';
    }

    /** Absolute path of a job's run lock file. */
    public static function lockPath(string $jobId): string
    {
        return self::stateDir() . '/' . Util::safeFileId($jobId) . '.lock';
    }

    /**
     * Acquire an EXCLUSIVE, ATOMIC per-job run lock. Returns the open lock-file
     * handle on success, or null when another live runner already holds it.
     *
     * isRunning() alone is a check-then-act race: two near-simultaneous launches
     * can both see "not running" and both proceed, producing duplicate concurrent
     * rsync runs for one job (dangerous with --delete). flock() with LOCK_EX |
     * LOCK_NB makes the claim atomic - only one process can hold it at a time.
     *
     * The lock file persists between runs; we re-use it and rely on flock's
     * advisory exclusivity, which the kernel releases automatically if the holder
     * dies (so a crashed runner never wedges the lock forever). The caller MUST
     * pass the returned handle to releaseLock() in a finally.
     *
     * null means contention only; an I/O failure throws, so it is never
     * mistaken for "already running".
     *
     * @return resource|null
     * @throws RuntimeException when the lock file cannot be opened or locked
     */
    public static function acquireLock(string $jobId)
    {
        self::ensureDir();
        $path = self::lockPath($jobId);
        $fh = @fopen($path, 'c'); // create if absent, do NOT truncate
        if ($fh === false) {
            throw new RuntimeException("Unable to open run lock: $path");
        }
        $wouldBlock = 0;
        if (!@flock($fh, LOCK_EX | LOCK_NB, $wouldBlock)) {
            @fclose($fh);
            if ($wouldBlock) {
                // Another live runner holds the lock.
                return null;
            }
            throw new RuntimeException("Unable to lock run lock: $path");
        }
        return $fh;
    }

    /**
     * Release a run lock acquired via acquireLock(). Best-effort; safe to call
     * with a non-resource (no-op).
     *
     * @param resource|null $handle
     */
    public static function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /** Ensure base() and stateDir() exist as private 0700 dirs (Util::ensurePrivateDir). */
    private static function ensureDir(): void
    {
        foreach ([self::base(), self::stateDir()] as $dir) {
            Util::ensurePrivateDir($dir, 'state');
        }
    }

    /**
     * Write a job's runtime state atomically (temp + rename). The written shape
     * is fixed: {jobId, pid, running, dryRun, trigger, startedAt, currentLog},
     * plus optional `token` and `gen` (jobId is always stamped from the $jobId arg).
     * Unknown keys passed in are dropped so the file stays canonical. `trigger` is clamped to the closed
     * set ('manual'|'schedule', default 'manual').
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
            'trigger'    => (($state['trigger'] ?? '') === 'schedule') ? 'schedule' : 'manual',
            'startedAt'  => isset($state['startedAt']) ? (string) $state['startedAt'] : '',
            'currentLog' => isset($state['currentLog']) ? (string) $state['currentLog'] : '',
        ];
        // The run's secrets token, so a crashed run's tmpfs secrets can be swept.
        if (is_string($state['token'] ?? null) && $state['token'] !== '') {
            $out['token'] = $state['token'];
        }
        if (is_string($state['gen'] ?? null) && $state['gen'] !== '') {
            $out['gen'] = $state['gen'];
        }
        if ((int) ($state['hookPgid'] ?? 0) > 0) {
            $out['hookPgid'] = (int) $state['hookPgid'];
        }

        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode run state JSON: ' . json_last_error_msg());
        }
        Util::writeAtomic(self::statePath($jobId), $json . "\n", 0600, false, 'state');
    }

    /**
     * Read a job's runtime state, or null when no state file exists or it is
     * unreadable/corrupt (a missing/garbage state file just means "not
     * running"). The returned array is always the canonical shape.
     *
     * @return array{jobId:string,pid:int,running:bool,dryRun:bool,trigger:string,startedAt:string,currentLog:string,token?:string,gen?:string,hookPgid?:int}|null
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
            'trigger'    => (($data['trigger'] ?? '') === 'schedule') ? 'schedule' : 'manual',
            'startedAt'  => (string) ($data['startedAt'] ?? ''),
            'currentLog' => (string) ($data['currentLog'] ?? ''),
        ] + (is_string($data['token'] ?? null) && $data['token'] !== '' ? ['token' => $data['token']] : [])
          + (is_string($data['gen'] ?? null) && $data['gen'] !== '' ? ['gen' => $data['gen']] : [])
          + ((int) ($data['hookPgid'] ?? 0) > 0 ? ['hookPgid' => (int) $data['hookPgid']] : []);
    }

    /** A fresh launch generation. */
    public static function newGen(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Record the generation of a launch before its runner starts, so an Abort
     * clicked in between names it (the runner adopts it via --gen). The caller
     * holds the run lock, so no runner can be writing this record meanwhile.
     */
    public static function beginLaunch(string $jobId): string
    {
        $gen = self::newGen();
        self::write($jobId, ['gen' => $gen] + (self::read($jobId) ?? []));
        return $gen;
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
        unset($state['hookPgid']);
        self::write($jobId, $state);
    }

    /**
     * Record (pgid > 0) or clear the process group of the hook the runner is
     * running: a hook has its own session, so a runner that dies during it
     * leaves a group only this record can name.
     */
    public static function setHookPgid(string $jobId, int $pgid): void
    {
        $state = self::read($jobId);
        if ($state === null) {
            return;
        }
        unset($state['hookPgid']);
        self::write($jobId, $state + ($pgid > 0 ? ['hookPgid' => $pgid] : []));
    }

    /**
     * Is this job currently running? PID-reuse-safe (see the class docblock):
     * requires running=true AND the pid alive AND the pid's cmdline to be our
     * runner for THIS job. A state that claims running but fails the liveness or
     * cmdline check is STALE - we clear it and report false, unless another
     * holder still has the run lock: a SIGKILLed runner's rsync inherits it and
     * keeps transferring, so the job stays busy until that child exits.
     */
    public static function isRunning(string $jobId): bool
    {
        $state = self::read($jobId);
        if ($state === null || empty($state['running'])) {
            // tmpfs state is gone after a reboot; the /boot in-flight record is not.
            if (is_file(self::inflightPath($jobId))) {
                self::reapStale($jobId);
            }
            return false;
        }
        $pid = (int) $state['pid'];
        // A dead pid, or one REUSED by an unrelated process: the worker died
        // without clearing state.
        if ($pid <= 0 || !static::pidAlive($pid) || !self::cmdlineMatchesJob(static::pidCmdline($pid), $jobId)) {
            return !self::reapStale($jobId);
        }
        return true;
    }

    /**
     * Send $sig to this job's run: the live runner's group (else its pid; it
     * stops its own hook). Once the runner is dead: its group (an orphaned
     * rsync) and the recorded hook's session, each only while a member holds
     * this job's run lock open - a dead leader's pid can be reused by an
     * unrelated group - and SIGKILLed if it still holds it after
     * $abortKillGraceSec. A pid reused by another process is never signalled,
     * but the hook it left is still checked.
     */
    public static function signalRun(string $jobId, int $sig): bool
    {
        $state = self::read($jobId);
        $pid   = (int) ($state['pid'] ?? 0);
        if ($pid > 0 && static::pidAlive($pid)) {
            if (self::cmdlineMatchesJob(static::pidCmdline($pid), $jobId)) {
                return self::kill(-$pid, $sig) || self::kill($pid, $sig);
            }
            $pid = 0; // reused by another process: neither it nor its group is ours
        }
        $signalled = false;
        foreach (array_unique([$pid, (int) ($state['hookPgid'] ?? 0)]) as $pgid) {
            if ($pgid > 0 && self::stopGroup($pgid, $sig, self::lockPath($jobId))) {
                $signalled = true;
            }
        }
        return $signalled;
    }

    private static function stopGroup(int $pgid, int $sig, string $lockPath): bool
    {
        if (!self::groupHoldsLock($pgid, $lockPath) || !self::kill(-$pgid, $sig)) {
            return false;
        }
        $deadline = microtime(true) + self::$abortKillGraceSec;
        while (self::groupHoldsLock($pgid, $lockPath)) {
            if (microtime(true) >= $deadline) {
                self::kill(-$pgid, defined('SIGKILL') ? SIGKILL : 9);
                break;
            }
            usleep(50000);
        }
        return true;
    }

    /**
     * Does a process in group $pgid have $lockPath open? Linux /proc only (no
     * /proc => false, so nothing is signalled).
     */
    public static function groupHoldsLock(int $pgid, string $lockPath): bool
    {
        if (self::$groupHoldsLock !== null) {
            return (self::$groupHoldsLock)($pgid, $lockPath);
        }
        $target = realpath($lockPath);
        if ($pgid <= 0 || $target === false) {
            return false;
        }
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
            $stat = @file_get_contents($dir . '/stat');
            // "pid (comm) state ppid pgrp ...": comm may hold spaces and ")".
            $end = is_string($stat) ? strrpos($stat, ')') : false;
            if ($end === false || (int) (explode(' ', substr((string) $stat, $end + 2))[2] ?? 0) !== $pgid) {
                continue;
            }
            foreach (glob($dir . '/fd/*') ?: [] as $fd) {
                if (@readlink($fd) === $target) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function kill(int $pid, int $sig): bool
    {
        if (self::$kill !== null) {
            return (self::$kill)($pid, $sig);
        }
        return function_exists('posix_kill') && @posix_kill($pid, $sig);
    }

    /**
     * Report a dead runner's leftover state exactly once. A live runner holds
     * the run lock from before it writes running=true until after markStopped,
     * so running=true under a lock WE hold can only be a dead one; a lock we
     * cannot get belongs to a starting runner (which reaps it itself), to a
     * concurrent detector, or to an orphaned child of a SIGKILLed runner.
     * False only when another holder has the lock.
     */
    private static function reapStale(string $jobId): bool
    {
        try {
            $lock = self::acquireLock($jobId);
        } catch (Throwable $e) {
            return true; // a status poll must not fail on it; the next run reports it
        }
        if ($lock === null) {
            return false;
        }
        try {
            $stale = self::takeStale($jobId);
            // Still under the lock, so a replacement run cannot record a newer
            // History row (and prune at retention 1) ahead of this one.
            if ($stale !== null) {
                require_once __DIR__ . '/Runner.php';
                Runner::reportCrashed($stale);
            }
        } finally {
            self::releaseLock($lock);
        }
        return true;
    }

    /**
     * With the job's run lock HELD: a state still claiming running=true, or an
     * in-flight record on /boot (all that survives a reboot), is a dead
     * runner's. Clear both and return it (once), else null.
     *
     * @return array<string,mixed>|null
     */
    public static function takeStale(string $jobId): ?array
    {
        $state    = self::read($jobId);
        $inflight = self::readInflight($jobId);
        self::clearInflight($jobId);
        if ($state !== null && !empty($state['running'])) {
            self::markStopped($jobId);
            // Only the in-flight record carries the snapshot stamp.
            if (is_array($inflight) && isset($inflight['snapshot']) && ($inflight['startedAt'] ?? null) === $state['startedAt']) {
                $state['snapshot'] = $inflight['snapshot'];
            }
            return $state;
        }
        return $inflight;
    }

    /**
     * Plugin start: reap every job's dead run, including jobs known only from
     * an in-flight record (after a reboot there is no tmpfs state at all).
     *
     * @param array<int,string> $jobIds
     */
    public static function reapCrashed(array $jobIds): void
    {
        foreach (@glob(self::inflightDir() . '/*.inflight.json') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (is_array($data) && is_string($data['jobId'] ?? null)) {
                $jobIds[] = $data['jobId'];
            }
        }
        foreach (array_unique($jobIds) as $jobId) {
            if ($jobId !== '') {
                self::isRunning($jobId);
            }
        }
    }

    // --- persistent in-flight record (survives a reboot) ---------------------

    /** Dir of the in-flight records: /boot, next to the run summaries. */
    private static function inflightDir(): string
    {
        return rtrim(UR_CONFIG_BASE, '/') . '/runs';
    }

    /** Absolute path of a job's in-flight record. */
    public static function inflightPath(string $jobId): string
    {
        return self::inflightDir() . '/' . Util::safeFileId($jobId) . '.inflight.json';
    }

    /**
     * Record on /boot that a run is in flight (Util::writeAtomic). One small
     * flash write per run, removed when the run records its outcome. Best-effort:
     * the run goes on without it, as it does without its summary.
     *
     * @param array<string,mixed> $state
     */
    public static function writeInflight(string $jobId, array $state): void
    {
        $dir = self::inflightDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $record = [
            'jobId'      => $jobId,
            'pid'        => (int) ($state['pid'] ?? 0),
            'startedAt'  => (string) ($state['startedAt'] ?? ''),
            'trigger'    => (($state['trigger'] ?? '') === 'schedule') ? 'schedule' : 'manual',
            'dryRun'     => !empty($state['dryRun']),
            'currentLog' => (string) ($state['currentLog'] ?? ''),
        ];
        if (is_string($state['snapshot'] ?? null) && $state['snapshot'] !== '') {
            $record['snapshot'] = $state['snapshot'];
        }
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        try {
            Util::writeAtomic(self::inflightPath($jobId), $json . "\n", 0600);
        } catch (Throwable $e) {
            // best-effort, see above
        }
    }

    /**
     * A job's in-flight record, or null when none (or unreadable).
     *
     * @return array<string,mixed>|null
     */
    public static function readInflight(string $jobId): ?array
    {
        $path = self::inflightPath($jobId);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? array_merge($data, ['jobId' => $jobId]) : null;
    }

    /** Remove a job's in-flight record (best-effort). */
    public static function clearInflight(string $jobId): void
    {
        $path = self::inflightPath($jobId);
        if (is_file($path)) {
            @unlink($path);
        }
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

    /**
     * Has an abort been requested for this job? (the flag file exists)
     *
     * @phpstan-impure
     */
    public static function abortRequested(string $jobId): bool
    {
        return is_file(self::abortPath($jobId));
    }

    /**
     * Raise the abort flag. $by is 'user' (the handler's abortJob) or 'signal'
     * (a SIGTERM/SIGINT the runner caught); $gen is the launch it is meant for.
     * A 'signal' flag never replaces one already there: the handler writes its
     * 'user' flag before it signals the runner's group.
     */
    public static function requestAbort(string $jobId, string $gen = '', string $by = 'user'): void
    {
        self::ensureDir();
        $path = self::abortPath($jobId);
        if ($by !== 'user' && is_file($path)) {
            return;
        }
        $json = json_encode(['by' => $by, 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'gen' => $gen]);
        Util::writeAtomic($path, $json === false ? '' : $json, 0600, false);
    }

    /**
     * The abort flag, or null when there is none. An empty or unparsable flag
     * (an older handler touched it) reads as a user abort for no generation.
     *
     * @return array{by:string,gen:string}|null
     * @phpstan-impure
     */
    public static function readAbort(string $jobId): ?array
    {
        $raw = @file_get_contents(self::abortPath($jobId));
        return $raw === false ? null : self::parseAbort($raw);
    }

    /** @return array{by:string,gen:string} */
    private static function parseAbort(string|false $raw): array
    {
        $data = json_decode((string) $raw, true);
        return [
            'by'  => is_array($data) && ($data['by'] ?? '') === 'signal' ? 'signal' : 'user',
            'gen' => is_array($data) && is_string($data['gen'] ?? null) ? $data['gen'] : '',
        ];
    }

    /**
     * Did a user ask to stop launch $gen? A flag with no generation (written
     * by an older handler, or mid-run with none recorded) counts.
     */
    public static function abortedByUser(string $jobId, string $gen): bool
    {
        $flag = self::readAbort($jobId);
        return $flag !== null && $flag['by'] === 'user' && ($flag['gen'] === '' || $flag['gen'] === $gen);
    }

    /**
     * Run start: drop a flag left for any other launch, keep one for $gen (an
     * Abort clicked between Run and this runner starting). The flag is first
     * renamed aside, so only that copy is judged and deleted: an abortJob for
     * this launch landing meanwhile writes a fresh file nothing here touches.
     * Ours goes back with link(), which never replaces a newer flag.
     */
    public static function clearStaleAbort(string $jobId, string $gen): void
    {
        $path  = self::abortPath($jobId);
        $taken = $path . '.' . bin2hex(random_bytes(4));
        if (!@rename($path, $taken)) {
            return;
        }
        $flag = self::parseAbort(@file_get_contents($taken));
        if (self::$afterStaleAbortRead !== null) {
            (self::$afterStaleAbortRead)();
        }
        if ($gen !== '' && $flag['gen'] === $gen) {
            @link($taken, $path);
        }
        @unlink($taken);
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
            // EPERM means the process exists but is owned by another user -> still
            // "alive". Use the posix errno constant (POSIX_EPERM) when available,
            // falling back to its Linux numeric value (1).
            $eperm = defined('POSIX_EPERM') ? POSIX_EPERM : 1;
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

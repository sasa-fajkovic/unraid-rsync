<?php

declare(strict_types=1);

/**
 * Logger.php - the per-run and plugin log writers, plus a bounded, HTML-escaped
 * tail for the UI.
 *
 * Logs live in RAM (tmpfs) by default, so they never wear the FAT32 flash and
 * are cleared on reboot; global.logDir ($logsDirOverride) can move them to an
 * array/pool path. Two destinations:
 *   - per-run log:  <runtimeBase>/logs/<jobid>/run-<UTCts>.log
 *                   This is BOTH the target rsync writes to via --log-file AND
 *                   where the runner writes its own preamble/epilogue (which
 *                   pair, hook output, exit code, final state). rsync appends to
 *                   it, so the plugin lines and rsync's own lines interleave in
 *                   one chronological file.
 *   - plugin log:   <runtimeBase>/logs/plugin.log  (a rolling, cross-job log)
 *
 * Two timestamp conventions, deliberately: run-log FILENAMES are UTC (a sortable,
 * DST-safe identifier), while the stamps event() writes INSIDE a log are
 * server-local with an offset, matching the rsync lines they sit beside.
 *
 * tail() returns text that is ALREADY HTML-escaped, so a caller can drop it into
 * a <pre> safely. This is the defence against the classic log-XSS hole where a
 * filename/path in the log is rendered as raw innerHTML. The UI must NEVER take
 * a raw log line and innerHTML it; it consumes this escaped output.
 *
 * The runtime base is overridable via UR_RUNTIME_BASE (shared with RunState) or
 * the $baseOverride static, so tests write to a temp dir.
 */


require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/LogDirectory.php';

// Max bytes a single in-progress RUN log may grow to before further captured
// output is dropped. The run log lives in RAM (tmpfs), so an unbounded
// verbose/debug run over a huge tree - or a chatty hook - could otherwise
// exhaust RAM (pruneRuns bounds the COUNT of logs and tail() bounds READS, but
// neither caps a single live log). Overridable for tests / a future Global
// Setting via the define; defaults to 16 MiB.
if (!defined('UR_MAX_RUN_LOG_BYTES')) {
    define('UR_MAX_RUN_LOG_BYTES', 16 * 1024 * 1024); // 16 MiB
}

// Byte budget for ALL of one job's run logs, enforced by pruneRuns() after the
// count prune: retention is a file count (up to 9999), so at 16 MiB a log a
// debug-level job could otherwise hold gigabytes of tmpfs (RAM).
if (!defined('UR_MAX_JOB_LOG_BYTES')) {
    define('UR_MAX_JOB_LOG_BYTES', 256 * 1024 * 1024); // 256 MiB
}

class Logger
{
    /** Max bytes tail() will read off the END of a log (bounds memory + DoS). */
    const TAIL_MAX_BYTES = 256 * 1024; // 256 KiB

    /** plugin.log is rotated to plugin.log.1 (one generation) past this size. */
    const PLUGIN_LOG_MAX_BYTES = 1024 * 1024;

    /**
     * Progress-line throttle for CAPTURED output. rsync's --info=progress2
     * redraws one status line with a bare \r several times a second, and the
     * capture path is byte-oriented (ProcIO hands us raw 8 KiB reads), so
     * appending chunks verbatim smeared thousands of redraws into a single
     * unreadable line - bounded only by the 16 MiB cap and the 128 KiB UI tail.
     * sink() keeps at most one progress line per PCT step or per SECS, whichever
     * comes first, and drops the rest.
     */
    const PROGRESS_MIN_PCT  = 5;
    const PROGRESS_MIN_SECS = 30.0;

    /**
     * Flush guard: a child that writes this many bytes with neither \n nor \r
     * gets them written as-is rather than buffered indefinitely.
     */
    const SINK_BUF_MAX = 8192;

    /**
     * Per-path sink state: the unterminated tail, the last progress percentage
     * and timestamp we actually wrote, and the newest DROPPED progress redraw
     * (so flushSink() can still land the final one). Keyed by log path; sink()
     * resets it, flushSink() removes it.
     *
     * @var array<string,array{buf:string,pending:string,pct:int,at:float,last:string}>
     */
    private static array $sinkState = [];

    /**
     * Exact secret strings (per-run materialised tmpfs paths) that MUST be
     * scrubbed from any captured rsync/ssh/hook output before it is written to a
     * run log or plugin.log. Threaded in by the Runner via setRedaction() at run
     * start (from Ssh::materialize's keyPath/passFile/knownHosts) and cleared in
     * its finally. On an SSH job at `debug` level rsync echoes the remote-shell
     * command it execs - the `-e "ssh -i <tmpfs-keypath> ... -p N"` line - which
     * would otherwise expose the tmpfs key/passfile/known_hosts PATHS (not the
     * key bytes or password) into a root-written, browser-visible log.
     *
     * @var array<int,string>
     */
    public static array $redactStrings = [];

    /**
     * Per-run runtime secret DIRECTORIES to redact defensively: any path under
     * these (e.g. a key/passfile/known_hosts for this run's token) is scrubbed
     * even if its exact filename differs from what setRedaction() was given.
     * Each entry is an absolute dir prefix WITHOUT a trailing slash.
     *
     * @var array<int,string>
     */
    public static array $redactDirs = [];

    /** The placeholder a redacted secret path is replaced with in the log. */
    const REDACT_PLACEHOLDER = '[redacted]';

    /** The size-cap marker written ONCE when a run log hits the byte cap. */
    const TRUNCATE_MARKER_PREFIX = '[log truncated';

    /**
     * Run logs that have already had their size-cap marker written, so the
     * marker is emitted exactly once per file even across many append() calls.
     * Keyed by absolute path => true.
     *
     * @var array<string,bool>
     */
    private static array $capped = [];

    /**
     * Arm per-run secret-path redaction for everything subsequently appended to
     * a log (the run log AND plugin.log). Pass the per-run materialised secret
     * paths (Ssh::materialize's keyPath/passFile/knownHosts) and the runtime base
     * + token so paths under this run's keys/<token>, pass/<token> and
     * known_hosts/<token> dirs are scrubbed too, even if a path's exact filename
     * differs. Empty strings are ignored. Call clearRedaction() in a finally.
     *
     * @param array<int,string> $paths exact secret path strings to redact
     * @param string            $runtimeBase the tmpfs base (Ssh::$runtimeBase)
     * @param string            $token       the per-run token (for the per-token dirs)
     */
    public static function setRedaction(array $paths, string $runtimeBase = '', string $token = ''): void
    {
        $exact = [];
        foreach ($paths as $p) {
            $p = (string) $p;
            if ($p !== '') {
                $exact[] = $p;
            }
        }
        // Longest first so a contained path is replaced as part of its longer
        // sibling rather than leaving a dangling suffix.
        usort($exact, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });
        self::$redactStrings = $exact;

        $dirs = [];
        $base = rtrim($runtimeBase, '/');
        if ($base !== '' && $token !== '') {
            foreach (['keys', 'pass', 'known_hosts'] as $sub) {
                $dirs[] = $base . '/' . $sub . '/' . $token;
            }
        }
        self::$redactDirs = $dirs;
    }

    /** Disarm per-run secret-path redaction (call in the Runner's finally). */
    public static function clearRedaction(): void
    {
        self::$redactStrings = [];
        self::$redactDirs    = [];
    }

    /**
     * Scrub the currently-armed per-run secret paths out of $text, replacing each
     * occurrence with REDACT_PLACEHOLDER. Replaces both the exact materialised
     * paths and any path under this run's per-token secret dirs. PURE w.r.t. the
     * armed state; returns $text unchanged when nothing is armed.
     */
    public static function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        foreach (self::$redactStrings as $secret) {
            if ($secret !== '' && strpos($text, $secret) !== false) {
                $text = str_replace($secret, self::REDACT_PLACEHOLDER, $text);
            }
        }
        // Defensive: scrub any remaining path under a per-run secret dir (e.g. a
        // tempnam scratch file). Match "<dir>" plus an optional "/<segment>".
        foreach (self::$redactDirs as $dir) {
            if ($dir === '' || strpos($text, $dir) === false) {
                continue;
            }
            $text = preg_replace(
                '#' . preg_quote($dir, '#') . '(/[^\s"\']*)?#',
                self::REDACT_PLACEHOLDER,
                $text
            ) ?? $text;
        }
        return $text;
    }

    /**
     * Per-run-log byte cap override. null => use the UR_MAX_RUN_LOG_BYTES
     * constant. A value < 1 is treated as "unset". Set this static (e.g. from a
     * future Global Setting, or a test) to change the cap at runtime, mirroring
     * the $retention / $baseOverride seam pattern.
     *
     * @var int|null
     */
    public static ?int $maxRunLogBytesOverride = null;

    /** The per-run-log byte cap (override > UR_MAX_RUN_LOG_BYTES > 16 MiB). */
    public static function maxRunLogBytes(): int
    {
        if (self::$maxRunLogBytesOverride !== null && (int) self::$maxRunLogBytesOverride > 0) {
            return (int) self::$maxRunLogBytesOverride;
        }
        $n = (int) UR_MAX_RUN_LOG_BYTES;
        return $n > 0 ? $n : 16 * 1024 * 1024;
    }

    /** @var int|null per-job byte budget override; null => UR_MAX_JOB_LOG_BYTES. */
    public static ?int $maxJobLogBytesOverride = null;

    /** The per-job run-log byte budget (override > UR_MAX_JOB_LOG_BYTES). */
    public static function maxJobLogBytes(): int
    {
        return max(1, (int) (self::$maxJobLogBytesOverride ?? UR_MAX_JOB_LOG_BYTES));
    }

    /**
     * Default number of per-job run logs to keep. On every run start the oldest
     * run-*.log files beyond this many are pruned (so RAM/tmpfs use is bounded).
     * Overridable via the $retention static, which the runner sets from the
     * global "keep last N executions" setting (Config::clampRetention()); this
     * constant is only the fallback when that static is unset, kept consistent
     * with Config::DEFAULT_RETENTION (100).
     */
    const DEFAULT_RETENTION = 100;

    /**
     * Retention override: how many per-job run logs to keep. null => use
     * DEFAULT_RETENTION. A value < 1 is clamped to 1 (we never prune the file we
     * just opened). Set this static (not the constant) to make N configurable.
     *
     * @var int|null
     */
    public static ?int $retention = null;

    /**
     * The basename pattern a run log matches: run-<UTCts>.log, or
     * run-<UTCts>-<n>.log (n >= 2) for a later run of the job in the same
     * second. Anchored, so only our own run logs are ever listed/pruned/served -
     * never plugin.log or any stray file dropped in the job dir.
     */
    const RUN_FILE_REGEX = '/^run-(\d{8}T\d{6}Z)(?:-([2-9]|[1-9]\d{1,3}))?\.log$/D';

    /**
     * Override the runtime base for tests (else UR_RUNTIME_BASE).
     *
     * @var string|null
     */
    public static ?string $baseOverride = null;

    /**
     * Optional absolute, persistent logs directory (an array/pool path under
     * /mnt) set by the Runner + handler from Config::logDir(). When non-empty,
     * logs are written here and survive a reboot; when null/empty, logs live in
     * RAM under base()/logs and are cleared on reboot. Decoupled from Config on
     * purpose: callers that have a config push it in, tests leave it null.
     *
     * @var string|null
     */
    public static ?string $logsDirOverride = null;

    /** @var array<string,list<resource>> */
    private static array $retainedLogs = [];

    /** @var list<list<array{string,resource}>> */
    private static array $runLogScopes = [];

    /** @param resource $handle */
    public static function retainLog(string $path, $handle): void
    {
        self::$retainedLogs[$path][] = $handle;
    }

    public static function releaseLog(string $path): void
    {
        if (isset(self::$retainedLogs[$path])) {
            array_pop(self::$retainedLogs[$path]);
            if (self::$retainedLogs[$path] === []) {
                unset(self::$retainedLogs[$path]);
            }
        }
    }

    /**
     * @template T
     * @param callable():T $run
     * @return T
     */
    public static function withRunLogs(callable $run): mixed
    {
        self::$runLogScopes[] = [];
        try {
            return $run();
        } finally {
            foreach (array_reverse(array_pop(self::$runLogScopes) ?? []) as [$path, $handle]) {
                self::releaseLog($path);
                fclose($handle);
            }
        }
    }

    public static function base(): string
    {
        if (self::$baseOverride !== null && self::$baseOverride !== '') {
            return rtrim((string) self::$baseOverride, '/');
        }
        return Util::runtimeBase();
    }

    /** True when a persistent (external) logs dir is configured. */
    private static function hasExternalLogsDir(): bool
    {
        return self::$logsDirOverride !== null && self::$logsDirOverride !== '';
    }

    /** Root of all logs: the configured persistent dir, else base()/logs (RAM). */
    public static function logsDir(): string
    {
        if (self::hasExternalLogsDir()) {
            return rtrim((string) self::$logsDirOverride, '/');
        }
        return self::base() . '/logs';
    }

    /** Per-job log dir. */
    public static function jobLogDir(string $jobId): string
    {
        return self::logsDir() . '/' . Util::safeFileId($jobId);
    }

    /** The rolling cross-job plugin log path. */
    public static function pluginLogPath(): string
    {
        return self::logsDir() . '/plugin.log';
    }

    /**
     * Build the path for a NEW run log for a job, stamped with a UTC timestamp.
     * Does not create the file; openRun() does. The format is
     * run-YYYYmmddTHHMMSSZ.log so logs sort chronologically by name.
     */
    public static function newRunLogPath(string $jobId, ?int $now = null): string
    {
        $ts = gmdate('Ymd\THis\Z', $now ?? time());
        return self::jobLogDir($jobId) . '/run-' . $ts . '.log';
    }

    public static function logDirRefusal(string $dir): string
    {
        if ($dir === '' || $dir[0] !== '/' || preg_match('#(?:^|/)\.\.?(?:/|$)|[\x00-\x1f]#', $dir)) {
            return 'Persistent log directory must be a normalized absolute path.';
        }
        $head = $dir;
        while (!file_exists($head) && !is_link($head) && $head !== '/') {
            $head = dirname($head);
        }
        try {
            LogDirectory::withDirectory($head, static fn() => null, false, $dir);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        return '';
    }

    /** @return array{string,string}|null */
    private static function location(string $path): ?array
    {
        $root = self::logsDir();
        $dir = dirname($path);
        $roots = array_unique([$root, @realpath($root) ?: $root]);
        $name = basename($path);
        if ($name === '.' || $name === '..' || str_contains($name, "\0")) {
            return null;
        }
        if (in_array($dir, $roots, true)) {
            return [$root, $name];
        }
        $job = basename($dir);
        if (in_array(dirname($dir), $roots, true) && $job !== '' && Util::safeFileId($job) === $job) {
            return [$root . '/' . $job, $name];
        }
        return null;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private static function inDirectory(string $dir, callable $callback, bool $create = false): mixed
    {
        return LogDirectory::withDirectory($dir, $callback, $create,
            self::hasExternalLogsDir() ? self::logsDir() : self::base());
    }

    private static function safeEntry(string $name, bool $allowMissing = false): bool
    {
        clearstatcache(true, $name);
        $st = @lstat($name);
        if ($st === false) {
            return $allowMissing && !file_exists($name) && !is_link($name);
        }
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        return ($st['mode'] & 0170000) === 0100000 && $st['nlink'] === 1
            && ($st['mode'] & 0022) === 0 && $euid !== null && $st['uid'] === $euid;
    }

    /** @return resource|false */
    public static function openLog(string $path, string $mode = 'rb')
    {
        if (!in_array($mode, ['rb', 'ab', 'r+b'], true)) {
            return false;
        }
        if (isset(self::$retainedLogs[$path])) {
            $handles = self::$retainedLogs[$path];
            $st = fstat(end($handles));
            foreach (glob('/proc/self/fd/*') ?: [] as $fd) {
                clearstatcache(true, $fd);
                $candidate = @stat($fd);
                if ($st !== false && $candidate !== false && $st['dev'] === $candidate['dev'] && $st['ino'] === $candidate['ino']) {
                    return @fopen($fd, $mode === 'ab' ? 'a+b' : $mode);
                }
            }
            return false;
        }
        $location = self::location($path);
        if ($location === null) {
            return false;
        }
        [$dir, $name] = $location;
        try {
            return self::inDirectory($dir, static function () use ($name, $mode) {
                if (!self::safeEntry($name, $mode === 'ab')) {
                    return false;
                }
                $before = @lstat($name);
                $mask = umask(0177);
                try {
                    $fh = @fopen($name, $mode === 'ab' ? 'a+b' : $mode);
                } finally {
                    umask($mask);
                }
                if ($fh === false) {
                    return false;
                }
                $st = fstat($fh);
                if ($st === false || !self::safeEntry($name)
                    || ($before !== false && ($st['dev'] !== $before['dev'] || $st['ino'] !== $before['ino']))) {
                    fclose($fh);
                    return false;
                }
                return $fh;
            }, $mode === 'ab');
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function safeLogReadPath(string $path): ?string
    {
        $fh = self::openLog($path);
        if ($fh === false) {
            return null;
        }
        fclose($fh);
        return @realpath($path) ?: $path;
    }

    private static function safeExistingPath(string $path, bool $allowMissing): bool
    {
        if (self::safeLogReadPath($path) !== null) {
            return true;
        }
        return $allowMissing && self::location($path) !== null && !file_exists($path) && !is_link($path);
    }

    /**
     * Create a fresh run log for a job and return its path. The file is created
     * empty at mode 600 so rsync's --log-file can append to it and the runner
     * can write its preamble. Exclusive create: a later run of the job in the
     * same second gets run-<UTCts>-2.log (then -3, ...) instead of truncating
     * the earlier run's log; the stamp stays the real start time.
     */
    public static function openRun(string $jobId, ?int $now = null): string
    {
        $dir = self::jobLogDir($jobId);
        $base = basename(substr(self::newRunLogPath($jobId, $now), 0, -strlen('.log')));
        return self::inDirectory($dir, static function () use ($dir, $base): string {
            $n = 1;
            foreach (glob($base . '*.log') ?: [] as $f) {
                if (preg_match(self::RUN_FILE_REGEX, basename($f), $m)) {
                    $n = max($n, (($m[2] ?? '') !== '' ? (int) $m[2] : 1) + 1);
                }
            }
            for (; $n <= 9999; $n++) {
                $name = $base . ($n === 1 ? '' : '-' . $n) . '.log';
                $mask = umask(0177);
                try {
                    $fh = @fopen($name, 'x+b');
                } finally {
                    umask($mask);
                }
                if ($fh !== false) {
                    $path = $dir . '/' . $name;
                    if (self::$runLogScopes !== [] && PHP_OS_FAMILY === 'Linux') {
                        self::retainLog($path, $fh);
                        self::$runLogScopes[count(self::$runLogScopes) - 1][] = [$path, $fh];
                    } else {
                        fclose($fh);
                    }
                    unset(self::$capped[$path]);
                    return $path;
                }
                if (!file_exists($name) && !is_link($name)) {
                    break;
                }
            }
            throw new RuntimeException("Unable to create run log: $dir/$base.log");
        }, true);
    }

    /**
     * Append a line (a trailing newline is added if absent) to an arbitrary log
     * file, creating its directory if needed. Used by the runner for its
     * preamble/epilogue lines around rsync's own --log-file output.
     *
     * Two security/robustness invariants are enforced here, the single write
     * primitive, so EVERYTHING logged (event lines AND captured rsync/ssh/hook
     * output via sink()) is covered:
     *   - secret-path REDACTION (F1): any currently-armed per-run tmpfs secret
     *     path is scrubbed before the bytes touch disk;
     *   - per-run-log SIZE CAP (F3): an in-progress RUN log is never grown past
     *     maxRunLogBytes(); once it hits the cap, further content is dropped and
     *     a single truncation marker is written.
     */
    public static function append(string $path, string $line): bool
    {
        if ($line === '' || substr($line, -1) !== "\n") {
            $line .= "\n";
        }
        return self::appendCapped($path, self::redact($line));
    }

    /**
     * Append already-newline-terminated, already-redacted $data to $path,
     * enforcing the per-run-log byte cap. Only RUN logs (basename matching
     * RUN_FILE_REGEX) are capped; plugin.log and the runner's own preamble go
     * uncapped (plugin.log is the rolling cross-job log, bounded on READ by
     * tail()). Once a run log reaches the cap we stop appending and write a
     * single "[log truncated - exceeded N MiB]" marker.
     *
     * @return bool false only when a write failed; bytes dropped at the cap
     *              are the cap working, not a failure.
     */
    private static function appendCapped(string $path, string $data): bool
    {
        if ($data === '') {
            return true;
        }
        $fh = self::openLog($path, 'ab');
        if ($fh === false) {
            return false;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return false;
            }
            if (!self::isRunLogPath($path)) {
                return fwrite($fh, $data) === strlen($data);
            }
            $cap = self::maxRunLogBytes();
            $size = (int) (fstat($fh)['size'] ?? 0);
            if ($size < $cap) {
                $part = substr($data, 0, $cap - $size);
                if (fwrite($fh, $part) !== strlen($part)) {
                    return false;
                }
            }
            if (($size >= $cap || $size + strlen($data) > $cap) && empty(self::$capped[$path])) {
                $marker = self::truncationMarker($cap);
                if (fwrite($fh, $marker) !== strlen($marker)) {
                    return false;
                }
                self::$capped[$path] = true;
            }
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function truncationMarker(int $cap): string
    {
        return self::TRUNCATE_MARKER_PREFIX . ' - exceeded ' . (int) round($cap / (1024 * 1024)) . " MiB]\n";
    }

    /** True when $path's basename is a run log (run-<UTCts>.log), not plugin.log. */
    private static function isRunLogPath(string $path): bool
    {
        return (bool) preg_match(self::RUN_FILE_REGEX, basename($path));
    }

    /**
     * A redacting, size-capped output sink for captured child-process output.
     * Returns a callable `fn(string $chunk): void` the Runner hands to
     * Rsync::run / its hook runner, so streamed rsync/ssh/hook output is scrubbed
     * of per-run secret paths and bounded to the run-log cap on the SAME path the
     * event() lines use. Replaces the runner's previous raw file_put_contents.
     *
     * @return callable(string):void
     */
    public static function sink(string $path): callable
    {
        // Fresh throttle state per sink: the Runner builds one per rsync pair
        // and one per hook, so a pair never inherits the previous pair's
        // percentage and every pair logs its first progress line immediately
        // (at = 0.0 makes the time condition true on the first redraw).
        self::$sinkState[$path] = ['buf' => '', 'pending' => '', 'pct' => -1, 'at' => 0.0, 'last' => ''];

        return static function (string $chunk) use ($path): void {
            if ($chunk === '') {
                return;
            }
            self::consume($path, $chunk);
        };
    }

    /**
     * Split captured output into lines and decide what reaches the log.
     *
     * A \n ends a REAL line - always written. A bare \r ends a progress
     * REDRAW, which by definition the next redraw supersedes, so only one per
     * PROGRESS_MIN_PCT step / PROGRESS_MIN_SECS window is written and the rest
     * are dropped. \r\n is a LINE ending, not a redraw.
     *
     * Which is why a \r that is the LAST byte we hold is left in the buffer:
     * until the next byte arrives it is genuinely unknowable whether it ends a
     * redraw or is the first half of a \r\n, and ProcIO's 8 KiB freads can cut
     * anywhere. Deciding early ate the line - the \r was consumed as a redraw
     * (dropped by the throttle) and its \n then closed an empty segment.
     */
    private static function consume(string $path, string $chunk): void
    {
        $st = self::$sinkState[$path] ?? ['buf' => '', 'pending' => '', 'pct' => -1, 'at' => 0.0, 'last' => ''];
        $st['buf'] .= $chunk;

        while (true) {
            $nl = strpos($st['buf'], "\n");
            $cr = strpos($st['buf'], "\r");

            if ($nl !== false && ($cr === false || $nl < $cr)) {
                $seg = substr($st['buf'], 0, $nl);
                $st['buf'] = substr($st['buf'], $nl + 1);
            } elseif ($cr !== false) {
                if ($cr === strlen($st['buf']) - 1) {
                    break; // trailing \r: wait for the byte that disambiguates it
                }
                $seg  = substr($st['buf'], 0, $cr);
                $crlf = $st['buf'][$cr + 1] === "\n";
                $st['buf'] = substr($st['buf'], $cr + ($crlf ? 2 : 1));
                if (!$crlf) {
                    self::throttleProgress($path, $seg, $st);
                    continue;
                }
            } else {
                break; // no terminator held: the rest is a partial line
            }

            // A real line supersedes any redraw still held back: the held one is
            // stale and printing it here would read out of order.
            $st['pending'] = '';
            // rsync ends its progress stream by repeating the final redraw, and
            // the LAST copy carries the stream's only \n - so it arrives here,
            // on the real-line path, byte-identical (modulo trailing padding) to
            // the 100% line throttleProgress just wrote. Every Summary run's log
            // therefore ended on two identical 100% lines, with rsync's own
            // --log-file summary wedged between them. Suppress that immediate
            // repeat; `last` is cleared right after, so this can never swallow
            // an unrelated later line, and a repeat that genuinely differs (a
            // moved rate or ETA) still lands because the match is byte-exact.
            $repeat     = ($st['last'] !== '' && rtrim($seg) === $st['last']);
            $st['last'] = '';
            if (!$repeat) {
                self::write($path, $seg . "\n");
            }
        }

        // Never hold an unterminated blob forever (a child emitting neither
        // \n nor \r would otherwise buffer without bound). Land the held-back
        // redraw first - it was produced BEFORE these bytes.
        if (strlen($st['buf']) > self::SINK_BUF_MAX) {
            if (trim($st['pending']) !== '') {
                self::write($path, rtrim($st['pending']) . "\n");
                $st['pending'] = '';
            }
            self::write($path, $st['buf']);
            $st['buf'] = '';
        }

        self::$sinkState[$path] = $st;
    }

    /**
     * Write one progress redraw only when it is due (a PROGRESS_MIN_PCT step, or
     * PROGRESS_MIN_SECS since the last one). Otherwise remember it as the newest
     * dropped redraw so flushSink() can still land the final percentage.
     *
     * @param array{buf:string,pending:string,pct:int,at:float,last:string} $st
     */
    private static function throttleProgress(string $path, string $seg, array &$st): void
    {
        if (trim($seg) === '') {
            return;
        }
        $now = microtime(true);
        $pct = (preg_match('/(\d+)%/', $seg, $m) === 1) ? (int) $m[1] : -1;
        // 100% is due ONCE regardless of the throttle: it is the line that says
        // the transfer completed, and it can otherwise fall inside the window
        // (e.g. 99% written, then 100% dropped) and be superseded by the
        // summary line that follows it.
        // Any CHANGE of PROGRESS_MIN_PCT, in EITHER direction: progress2's
        // percentage is not monotonic. With incremental recursion the
        // denominator grows as the file list is discovered, so the figure
        // regularly drops and can even touch a spurious 100% early. Against a
        // high-water mark that killed the 5% rule for the rest of the run,
        // silently degrading the promise to "one line per 30s" on exactly the
        // big-tree jobs this exists for.
        $due = ($now - $st['at'] >= self::PROGRESS_MIN_SECS)
            || ($pct >= 100 && $st['pct'] < 100)
            || ($pct >= 0 && abs($pct - $st['pct']) >= self::PROGRESS_MIN_PCT);

        if (!$due) {
            $st['pending'] = $seg;
            return;
        }
        $st['pending'] = '';
        $st['at']      = $now;
        if ($pct >= 0) {
            $st['pct'] = $pct;
        }
        // progress2 pads its line with trailing spaces to erase the previous,
        // longer redraw; pointless in a file.
        $st['last'] = rtrim($seg);
        self::write($path, $st['last'] . "\n");
    }

    /**
     * Land whatever a sink is still holding: the newest dropped progress redraw
     * (so the log ends on the real final percentage, not on whatever the 5%/30s
     * throttle last let through) and any unterminated tail. Idempotent - the
     * state is removed, so a second call is a no-op. The Runner calls this once
     * the child has exited and the pipes are drained.
     */
    public static function flushSink(string $path): void
    {
        $st = self::$sinkState[$path] ?? null;
        if ($st === null) {
            return;
        }
        unset(self::$sinkState[$path]);

        // buf holds the text after the LAST \r, i.e. newer than pending.
        $last = rtrim(($st['buf'] !== '') ? $st['buf'] : $st['pending']);
        if (trim($last) === '') {
            return;
        }
        // ...but not if it is byte-identical to the progress line the throttle
        // already wrote. rsync repeats its final redraw, and when the repeat is
        // identical the flush landed a duplicate 100% line at the end of EVERY
        // run - live-observed on a real Summary run, and worse, after the
        // end-of-run summary that rsync's own --log-file fd had written in
        // between. A repeat that genuinely differs (a moved rate or ETA) still
        // lands: it is new information, not an artefact of buffering.
        if ($last === $st['last']) {
            return;
        }
        self::write($path, $last . "\n");
    }

    /** Redact, then append under the run-log byte cap. The only write path. */
    private static function write(string $path, string $data): void
    {
        self::appendCapped($path, self::redact($data));
    }

    /**
     * Enforce the per-run-log byte cap on an existing run-log file regardless of
     * who wrote it. appendCapped() only bounds what flows through Logger
     * (captured stdout/stderr + hooks), but rsync ALSO writes the run log
     * directly via `--log-file=<runLog>`, bypassing Logger - so a verbose/debug
     * run could still grow the tmpfs log unbounded. The Runner calls this after
     * each rsync pair (rsync re-opens --log-file per invocation, so trimming
     * between pairs is safe): if the file exceeds the cap, it is truncated in
     * place to the cap (keeping the HEAD, matching appendCapped's "stop
     * appending" semantics) followed by exactly one marker. Once a log carries
     * the marker, everything after its first occurrence is cut on every later
     * call. No-op for non-run-log paths or an uncapped file under the cap.
     * Best-effort.
     *
     * Also safe WHILE a pair runs: rsync opens --log-file with fopen("a")
     * (O_APPEND), so after a truncate its next line lands at the new end, not
     * past it. The Runner calls this every Runner::$logCapCheckSec during a
     * pair, which bounds one long pair's log continuously.
     *
     * @param bool|null $failed set to true when a trim was due but the file
     *        could not be opened, locked, truncated or marked
     * @return bool true when the file was trimmed.
     */
    public static function enforceRunLogCap(string $path, ?bool &$failed = null): bool
    {
        $failed = false;
        if (!self::isRunLogPath($path)) {
            return false;
        }
        $fh = self::openLog($path, 'r+b');
        if ($fh === false) {
            $failed = is_file($path) || is_link($path);
            return false;
        }
        try {
            $cap = self::maxRunLogBytes();
            $marker = self::truncationMarker($cap);
            $size = (int) (fstat($fh)['size'] ?? 0);
            if ($size <= $cap && empty(self::$capped[$path])) {
                return false;
            }
            if (!flock($fh, LOCK_EX)) {
                $failed = true;
                return false;
            }
            $at = strpos((string) stream_get_contents($fh, $cap + strlen($marker), 0), $marker);
            $trimmed = false;
            if ($at !== false && $size > $at + strlen($marker)) {
                $trimmed = ftruncate($fh, $at + strlen($marker));
                $failed = !$trimmed;
            } elseif ($at === false && $size > $cap) {
                $trimmed = ftruncate($fh, $cap) && fseek($fh, $cap) === 0
                    && fwrite($fh, $marker) === strlen($marker);
                $failed = !$trimmed;
            }
            if ($at !== false || $trimmed) {
                self::$capped[$path] = true;
            }
            return $trimmed;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Redact the currently-armed per-run secret paths out of an existing run-log
     * FILE, in place. Returns true when the file was rewritten.
     *
     * WHY the file and not just the stream: sink() only ever sees the output WE
     * capture. rsync writes the SAME log itself through `--log-file=<runLog>` on
     * its own fd, and it names the daemon password file VERBATIM in two of its
     * own errors - authenticate.c:188 "could not open password file %s" and :215
     * "ERROR: failed to read a password from %s". Those lines never pass through
     * the sink, so the file is the only place left to scrub them. The same pass
     * closes the pre-existing SSH hole where rsync at `debug` echoes the
     * remote-shell command with the tmpfs key path in it.
     *
     * MUST be called only AFTER the pair's rsync has exited and closed its
     * --log-file fd - which is exactly where enforceRunLogCap() is already
     * called. No-op when nothing is armed, when $path is not a run log, or when
     * the file does not exist. Best-effort, like every other write here.
     *
     * ponytail: reads the whole file (bounded by maxRunLogBytes, 16 MiB) rather
     * than streaming. Chunk it if the per-pair cost ever shows up.
     */
    public static function redactRunLog(string $path): bool
    {
        if ((self::$redactStrings === [] && self::$redactDirs === []) || !self::isRunLogPath($path)) {
            return false;
        }
        $fh = self::openLog($path, 'r+b');
        if ($fh === false) {
            return false;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return false;
            }
            $body = stream_get_contents($fh);
            if ($body === false || $body === '') {
                return false;
            }
            $red = self::redact($body);
            return $red !== $body && rewind($fh) && fwrite($fh, $red) === strlen($red) && ftruncate($fh, strlen($red));
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Scrub anything that LOOKS like a per-run tmpfs secret path out of $text,
     * with nothing armed and no per-run state: every path under the runtime
     * base's keys/ , pass/ or known_hosts/ subtree becomes REDACT_PLACEHOLDER.
     * PURE.
     *
     * redactRunLog() only runs in the RUNNER process, after a pair's rsync has
     * exited. Between those passes the live Status/Overview poller is tailing the
     * same file from php-fpm, where nothing is armed and nothing can be - so the
     * password-file path rsync names in its own errors (authenticate.c:188/:215),
     * and the `-e "ssh -i <tmpfs-keypath>"` line at --debug=all, were readable in
     * the browser for the length of a pair, and stayed readable for good if the
     * runner was SIGKILLed mid-pair. This is a SHAPE match rather than an exact
     * one precisely because the reader knows no token. Only a path to a
     * root-owned 0600 file is ever at stake here, never a secret's bytes.
     */
    public static function scrubSecretPaths(string $text): string
    {
        $base = self::base();
        if ($text === '' || $base === '' || strpos($text, $base) === false) {
            return $text;
        }
        return preg_replace(
            '#' . preg_quote($base, '#') . '/(?:keys|pass|known_hosts)(?:/[^\s"\']*)?#',
            self::REDACT_PLACEHOLDER,
            $text
        ) ?? $text;
    }

    /**
     * Append a timestamped line to BOTH the given run log and the rolling
     * plugin log. The plugin log line is prefixed with the job id so the
     * cross-job log is readable.
     *
     * The stamp is SERVER-LOCAL with an explicit UTC offset
     * (2026-08-30T19:40:10+10:00), not UTC: rsync writes its own lines into this
     * same file in system local time, so a UTC stamp here put two timezones in
     * one log (issue #135). The offset is kept so the stamp stays unambiguous
     * ISO-8601. Config.php pins the process timezone to the system zone at load.
     * NOTE: the run-log FILENAME stays UTC (see newRunLogPath) - it is an
     * identifier that has to sort lexically and never repeat across a DST fold.
     */
    public static function event(string $runLog, string $jobId, string $message): bool
    {
        $stamp = date('Y-m-d\TH:i:sP');
        if ($runLog !== '') {
            self::append($runLog, '[' . $stamp . '] ' . $message);
        }
        try {
            return self::inDirectory(self::logsDir(), static function () use ($stamp, $jobId, $message): bool {
                if (!self::safeEntry('plugin.log', true) || !self::safeEntry('plugin.log.lock', true)) {
                    return false;
                }
                return Util::withLock('plugin.log.lock', static function () use ($stamp, $jobId, $message): bool {
                    clearstatcache(true, 'plugin.log');
                    if ((int) @filesize('plugin.log') > self::PLUGIN_LOG_MAX_BYTES) {
                        if (!self::safeEntry('plugin.log.1', true) || !@rename('plugin.log', 'plugin.log.1')) {
                            return false;
                        }
                    }
                    $line = self::redact('[' . $stamp . '] [' . $jobId . '] ' . $message . "\n");
                    $mask = umask(0177);
                    try {
                        return @file_put_contents('plugin.log', $line, FILE_APPEND | LOCK_EX) !== false;
                    } finally {
                        umask($mask);
                    }
                });
            }, true);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Return up to $maxBytes from the END of a log file, HTML-ESCAPED so it is
     * safe to drop straight into a <pre> in the UI. When the file is larger than
     * the cap, the returned text starts at a line boundary (we drop a possibly
     * partial leading line) and is prefixed with a truncation marker.
     *
     * Returns '' when the file does not exist or is empty. NEVER returns raw,
     * unescaped log bytes - that is the whole point (log-XSS guard).
     */
    public static function tail(string $path, int $maxBytes = self::TAIL_MAX_BYTES): string
    {
        if ($maxBytes <= 0) {
            $maxBytes = self::TAIL_MAX_BYTES;
        }
        $fh = self::openLog($path);
        if ($fh === false) {
            return '';
        }
        $size = (int) (fstat($fh)['size'] ?? 0);
        $truncated = false;
        if ($size > $maxBytes) {
            @fseek($fh, -$maxBytes, SEEK_END);
            $truncated = true;
        }
        $data = @stream_get_contents($fh);
        @fclose($fh);
        if ($data === false) {
            return '';
        }

        if ($truncated) {
            // Drop a partial first line so the tail starts cleanly, then mark it.
            $nl = strpos($data, "\n");
            if ($nl !== false) {
                $data = substr($data, $nl + 1);
            }
            $data = "[... earlier output truncated ...]\n" . $data;
        }

        // SCRUB before escaping: this is the ONLY redaction a reader process
        // gets. redactRunLog() runs in the runner, after each pair; a tail taken
        // mid-pair (or after a SIGKILLed run) would otherwise show the per-run
        // tmpfs secret PATHS rsync writes into the log through its own
        // --log-file fd. Cheap: a single strpos guard when nothing matches.
        $data = self::scrubSecretPaths($data);

        // ESCAPE before returning - the caller renders this verbatim. Log files
        // hold arbitrary bytes (rsync output, non-UTF-8 filenames); ENT_SUBSTITUTE
        // replaces invalid UTF-8 sequences with the Unicode replacement char
        // rather than letting htmlspecialchars() return '' / emit a warning, so
        // the viewer stays reliable on binary-ish output.
        return htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The last "rsync error: ..." line in the final 64 KiB of a run log, or ''.
     * Printable ASCII only (rsync writes that message in ASCII), so the History
     * record it lands in always JSON-encodes.
     */
    public static function lastRsyncError(string $path): string
    {
        $fh = self::openLog($path);
        if ($fh === false) {
            return '';
        }
        $size = (int) (fstat($fh)['size'] ?? 0);
        fseek($fh, max(0, $size - 65536));
        $data = stream_get_contents($fh);
        fclose($fh);
        if (!is_string($data) || !preg_match_all('/rsync error: [\x20-\x7e]*/', $data, $m)) {
            return '';
        }
        return substr(self::scrubSecretPaths((string) end($m[0])), 0, 300);
    }

    /**
     * Incremental companion to tail(): the COMPLETE lines appended after byte
     * offset $since, scrubbed and escaped exactly like tail(). `offset` is where
     * the next call should start.
     *
     * Only whole lines are returned, so a multi-byte character is never split
     * across two escapes (ENT_SUBSTITUTE would turn both halves into U+FFFD).
     * A $since past the end means the file was cleared or replaced: `reset` is
     * set and the read starts again from 0. A gap larger than $maxBytes is
     * skipped like tail() does, with `truncated` set and a marker line in place
     * of the gap - which also covers a single line longer than $maxBytes. A
     * file that is replaced and regrows past $since before the next call is
     * not detected.
     *
     * @return array{size:int,offset:int,text:string,truncated:bool,reset:bool}
     */
    public static function readFrom(string $path, int $since, int $maxBytes = self::TAIL_MAX_BYTES): array
    {
        if ($maxBytes <= 0) {
            $maxBytes = self::TAIL_MAX_BYTES;
        }
        $fh = self::openLog($path);
        $size = $fh !== false ? (int) (fstat($fh)['size'] ?? 0) : 0;
        $reset = ($since < 0 || $since > $size);
        if ($reset) {
            $since = 0;
        }
        $out = ['size' => $size, 'offset' => $since, 'text' => '', 'truncated' => false, 'reset' => $reset];
        if ($since >= $size || $fh === false) {
            if ($fh !== false) {
                fclose($fh);
            }
            return $out;
        }

        $start     = $since;
        $truncated = ($size - $since) > $maxBytes;
        if ($truncated) {
            $start = $size - $maxBytes;
        }
        @fseek($fh, $start);
        $data = @stream_get_contents($fh, $size - $start);
        @fclose($fh);
        if ($data === false || $data === '') {
            return $out;
        }

        // No newline yet: the offset stays at the last completed line. A line
        // longer than the window is skipped whole once its newline lands (the
        // partial first line dropped below), never handed out piecemeal.
        $end = strrpos($data, "\n");
        if ($end === false) {
            return $out;
        }
        $data = substr($data, 0, $end + 1);
        $out['offset'] = $start + $end + 1;
        if ($truncated) {
            $nl = strpos($data, "\n");
            $data = "[... earlier output truncated ...]\n" . substr($data, (int) $nl + 1);
            $out['truncated'] = true;
        }
        $out['text'] = htmlspecialchars(self::scrubSecretPaths($data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return $out;
    }

    /**
     * The effective retention count (how many per-job run logs to keep).
     * Clamped to a minimum of 1 so a run never prunes the log it just opened.
     */
    public static function retention(): int
    {
        $n = (self::$retention !== null) ? (int) self::$retention : self::DEFAULT_RETENTION;
        return $n < 1 ? 1 : $n;
    }

    /**
     * Validate a run-file id and resolve it to an absolute path CONFINED to the
     * job's own log dir. The id is the run-log BASENAME ("run-<UTCts>.log") or
     * just the stamp ("run-<UTCts>") - we accept both and re-append .log. Returns
     * null for anything that doesn't match the strict run-<UTCts>.log pattern,
     * which by construction excludes path traversal ("../"), absolute paths,
     * separators, NULs, and any non-run file (e.g. plugin.log). Belt and braces,
     * we also verify the resolved real path still lives under the job log dir.
     */
    public static function runLogPathById(string $jobId, string $runId): ?string
    {
        $runId = trim($runId);
        if ($runId === '') {
            return null;
        }
        // Accept either the full basename or the bare stamp; normalise to a
        // basename and reject anything carrying a path separator outright.
        if (strpbrk($runId, "/\\\0") !== false) {
            return null;
        }
        if (substr($runId, -4) !== '.log') {
            $runId .= '.log';
        }
        if (!preg_match(self::RUN_FILE_REGEX, $runId)) {
            return null;
        }

        $dir  = self::jobLogDir($jobId);
        $path = $dir . '/' . $runId;

        if (!self::safeExistingPath($path, true)) {
            return null;
        }
        $real = @realpath($path);
        return $real === false ? $path : $real;
    }

    /**
     * The id (basename) of a run log path, e.g. "run-20250101T000000Z.log".
     */
    public static function runIdFromPath(string $path): string
    {
        return basename($path);
    }

    /**
     * List a job's run logs, NEWEST FIRST, capped at $limit (0 => no cap).
     * Each entry is {id, ts, state}:
     *   - id:    the run-log basename ("run-<UTCts>.log"), safe to echo as an id;
     *   - ts:    the run's UTC start epoch parsed from the stamp (0 if unparsable);
     *   - state: the run's outcome state. We only persist a single last-run
     *            SUMMARY, so we can attribute a concrete state to the NEWEST run
     *            when its start stamp matches the summary's startedAt; older runs
     *            (and a newest run whose stamp doesn't match) get '' (unknown).
     *            The viewer renders '' as a neutral label.
     *
     * Returns [] when the job has no run logs (or the dir is missing). Never
     * lists plugin.log or any non-run file (the basename pattern excludes them).
     *
     * @param callable(string):(?array<string,mixed>)|null $summaryReader override
     *        for tests; fn(jobId): summary|null. null => Runner::readSummary.
     * @return array<int,array{id:string,ts:int,state:string}>
     */
    public static function listRuns(string $jobId, int $limit = 0, ?callable $summaryReader = null): array
    {
        $dir = self::jobLogDir($jobId);
        try {
            $entries = self::inDirectory($dir, static fn() => array_values(array_filter(scandir('.') ?: [],
                static fn(string $name): bool => preg_match(self::RUN_FILE_REGEX, $name) === 1 && self::safeEntry($name))));
        } catch (Throwable $e) {
            return [];
        }

        $runs = [];
        foreach ($entries as $name) {
            if (!preg_match(self::RUN_FILE_REGEX, $name, $m)) {
                continue; // not a run log (plugin.log, dotfiles, temp files...)
            }
            $stamp = $m[1]; // YYYYmmddTHHMMSSZ
            $ts    = self::stampToEpoch($stamp);
            $runs[] = ['id' => $name, 'ts' => $ts];
        }

        usort($runs, static fn($a, $b) => self::compareRunNames($b['id'], $a['id']));

        if ($limit > 0 && count($runs) > $limit) {
            $runs = array_slice($runs, 0, $limit);
        }

        // Attribute the SUMMARY's state to the newest run whose start stamp
        // matches the summary startedAt (the run that produced the summary).
        $summary = null;
        if ($summaryReader !== null) {
            $summary = $summaryReader($jobId);
        } elseif (class_exists('Runner')) {
            $summary = Runner::readSummary($jobId);
        }
        $summaryStartTs = null;
        if (is_array($summary) && !empty($summary['startedAt'])) {
            $summaryStartTs = self::iso8601ToEpoch((string) $summary['startedAt']);
        }

        $out = [];
        foreach ($runs as $run) {
            $state = '';
            if (
                $summaryStartTs !== null
                && is_array($summary)
                && abs($run['ts'] - $summaryStartTs) <= 1 // 1s slack: log stamp vs summary startedAt
            ) {
                $state = (string) ($summary['state'] ?? '');
                $summaryStartTs = null; // only the newest match ran last
            }
            $out[] = ['id' => $run['id'], 'ts' => $run['ts'], 'state' => $state];
        }
        return $out;
    }

    /**
     * Absolute path of a job's CURRENT/latest run log (the newest run-*.log), or
     * '' when the job has none. Used as the default target for the log viewer.
     */
    public static function latestRunLogPath(string $jobId): string
    {
        $runs = self::listRuns($jobId, 1);
        if (empty($runs)) {
            return '';
        }
        // F5 (confinement symmetry): resolve the "latest" run id through the SAME
        // strict-regex + realpath confinement helper that runLogPathById enforces,
        // so the "latest" and "by id" paths share one hardened resolver rather
        // than this one concatenating jobLogDir + id directly. The id always
        // matches the run-file pattern by construction, but routing it through the
        // helper keeps the two paths symmetric and defends against any future
        // change to how listRuns derives the id.
        $path = self::runLogPathById($jobId, (string) $runs[0]['id']);
        return $path ?? '';
    }

    /**
     * Order two run-log basenames chronologically: by stamp, then by the
     * same-second suffix numerically (plain = 1), so run-X.log < run-X-2.log
     * < run-X-10.log. A plain strcmp gets both wrong ('-' sorts before '.').
     */
    private static function compareRunNames(string $a, string $b): int
    {
        $key = static function (string $name): array {
            return preg_match(self::RUN_FILE_REGEX, $name, $m)
                ? [$m[1], (int) (($m[2] ?? '') !== '' ? $m[2] : 1)]
                : ['', 0];
        };
        return $key($a) <=> $key($b);
    }

    /**
     * Prune a job's run logs to the newest $keep (default = retention()), deleting
     * the OLDEST first, then keep deleting the oldest while the job's logs
     * together exceed maxJobLogBytes(). plugin.log is NEVER touched (it lives one
     * level up under logsDir and never matches the run-file pattern), and
     * neither is the newest log or $current (the run that just opened it, which
     * a clock stepped backwards could otherwise sort as old). Called on every
     * run start. Best-effort: a failed unlink is ignored rather than failing a
     * run.
     *
     * @return int the number of run logs deleted
     */
    public static function pruneRuns(string $jobId, ?int $keep = null, string $current = ''): int
    {
        $keep = ($keep === null) ? self::retention() : max(1, (int) $keep);
        try {
            return self::inDirectory(self::jobLogDir($jobId), static function () use ($keep, $current): int {
                $entries = @scandir('.');
                if ($entries === false) {
                    return 0;
                }

                $runs = [];
                foreach ($entries as $name) {
                    if (preg_match(self::RUN_FILE_REGEX, $name) && self::safeEntry($name)) {
                        $runs[] = $name;
                    }
                }
                usort($runs, static fn($a, $b) => self::compareRunNames($b, $a)); // newest first
                $protected = basename($current);
                $deleted   = 0;
                $kept      = [];
                foreach ($runs as $i => $name) {
                    if ($i >= $keep && $name !== $protected) {
                        if (@unlink($name)) {
                            $deleted++;
                        }
                        continue;
                    }
                    $kept[] = $name;
                }

                $sizes = [];
                foreach ($kept as $name) {
                    clearstatcache(true, $name);
                    $sizes[$name] = (int) @filesize($name);
                }
                $total  = array_sum($sizes);
                $budget = self::maxJobLogBytes();
                for ($i = count($kept) - 1; $i > 0 && $total > $budget; $i--) {
                    $name = $kept[$i];
                    if ($name !== $protected && @unlink($name)) {
                        $total -= $sizes[$name];
                        $deleted++;
                    }
                }
                return $deleted;
            });
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Parse a run-log stamp (YYYYmmddTHHMMSSZ) to a UTC epoch (0 on failure). */
    private static function stampToEpoch(string $stamp): int
    {
        $dt = DateTime::createFromFormat('Ymd\THis\Z', $stamp, new DateTimeZone('UTC'));
        return $dt === false ? 0 : (int) $dt->getTimestamp();
    }

    /** Parse an ISO-8601 UTC timestamp (Y-m-d\TH:i:s\Z) to epoch (null on failure). */
    private static function iso8601ToEpoch(string $iso): ?int
    {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $iso, new DateTimeZone('UTC'));
        return $dt === false ? null : (int) $dt->getTimestamp();
    }
}

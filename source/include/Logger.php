<?php
/**
 * Logger.php - the per-run and plugin log writers, plus a bounded, HTML-escaped
 * tail for the UI.
 *
 * Logs live in RAM (tmpfs) so they never wear the FAT32 flash and are cleared
 * on reboot. Two destinations:
 *   - per-run log:  <runtimeBase>/logs/<jobid>/run-<UTCts>.log
 *                   This is BOTH the target rsync writes to via --log-file AND
 *                   where the runner writes its own preamble/epilogue (which
 *                   pair, hook output, exit code, final state). rsync appends to
 *                   it, so the plugin lines and rsync's own lines interleave in
 *                   one chronological file.
 *   - plugin log:   <runtimeBase>/logs/plugin.log  (a rolling, cross-job log)
 *
 * tail() returns text that is ALREADY HTML-escaped, so a caller can drop it into
 * a <pre> safely. This is the defence against the classic log-XSS hole where a
 * filename/path in the log is rendered as raw innerHTML. The UI must NEVER take
 * a raw log line and innerHTML it; it consumes this escaped output.
 *
 * (Retention pruning of old run logs and the run-selector log VIEWER are Phase
 * 6; Phase 4 only needs a writer + a safe tail.)
 *
 * The runtime base is overridable via UR_RUNTIME_BASE (shared with RunState) or
 * the $baseOverride static, so tests write to a temp dir.
 */

if (!defined('UR_RUNTIME_BASE')) {
    define('UR_RUNTIME_BASE', '/tmp/unraid.rsync');
}

// Max bytes a single in-progress RUN log may grow to before further captured
// output is dropped. The run log lives in RAM (tmpfs), so an unbounded
// verbose/debug run over a huge tree - or a chatty hook - could otherwise
// exhaust RAM (pruneRuns bounds the COUNT of logs and tail() bounds READS, but
// neither caps a single live log). Overridable for tests / a future Global
// Setting via the define; defaults to 16 MiB.
if (!defined('UR_MAX_RUN_LOG_BYTES')) {
    define('UR_MAX_RUN_LOG_BYTES', 16 * 1024 * 1024); // 16 MiB
}

class Logger
{
    /** Max bytes tail() will read off the END of a log (bounds memory + DoS). */
    const TAIL_MAX_BYTES = 256 * 1024; // 256 KiB

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
    public static $redactStrings = [];

    /**
     * Per-run runtime secret DIRECTORIES to redact defensively: any path under
     * these (e.g. a key/passfile/known_hosts for this run's token) is scrubbed
     * even if its exact filename differs from what setRedaction() was given.
     * Each entry is an absolute dir prefix WITHOUT a trailing slash.
     *
     * @var array<int,string>
     */
    public static $redactDirs = [];

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
    private static $capped = [];

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
    public static $maxRunLogBytesOverride = null;

    /** The per-run-log byte cap (override > UR_MAX_RUN_LOG_BYTES > 16 MiB). */
    public static function maxRunLogBytes(): int
    {
        if (self::$maxRunLogBytesOverride !== null && (int) self::$maxRunLogBytesOverride > 0) {
            return (int) self::$maxRunLogBytesOverride;
        }
        $n = (int) UR_MAX_RUN_LOG_BYTES;
        return $n > 0 ? $n : 16 * 1024 * 1024;
    }

    /**
     * Default number of per-job run logs to keep. On every run start the oldest
     * run-*.log files beyond this many are pruned (so RAM/tmpfs use is bounded).
     * Overridable via the $retention static (e.g. a future Global Setting).
     */
    const DEFAULT_RETENTION = 10;

    /**
     * Retention override: how many per-job run logs to keep. null => use
     * DEFAULT_RETENTION. A value < 1 is clamped to 1 (we never prune the file we
     * just opened). Set this static (not the constant) to make N configurable.
     *
     * @var int|null
     */
    public static $retention = null;

    /**
     * The basename pattern a run log matches: run-<UTCts>.log. Anchored, so only
     * our own run logs are ever listed/pruned/served - never plugin.log or any
     * stray file dropped in the job dir.
     */
    const RUN_FILE_REGEX = '/^run-(\d{8}T\d{6}Z)\.log$/D';

    /** Override the runtime base for tests (else UR_RUNTIME_BASE). */
    public static $baseOverride = null;

    public static function base(): string
    {
        if (self::$baseOverride !== null && self::$baseOverride !== '') {
            return rtrim((string) self::$baseOverride, '/');
        }
        return rtrim(UR_RUNTIME_BASE, '/');
    }

    /** Root of all logs. */
    public static function logsDir(): string
    {
        return self::base() . '/logs';
    }

    /** Per-job log dir. */
    public static function jobLogDir(string $jobId): string
    {
        return self::logsDir() . '/' . self::safeId($jobId);
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

    private static function safeId(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $id);
        // A pure-dots id ("." / ".." / "...") survives the char-class strip but
        // is a traversal segment ("logs/.." == base()), so collapse it to a
        // literal. Mirrors ur_safe_job_id's pure-dots rejection so this inner
        // defence-in-depth layer can't be the weak link.
        if ($clean === '' || $clean === null || preg_match('/^\.+$/', $clean)) {
            return 'unknown';
        }
        return $clean;
    }

    /**
     * Ensure a log directory exists (mode 700; logs may name paths/hosts).
     *
     * Walks the ancestry base() -> logsDir() -> leaf and creates each level
     * NON-recursively, refusing a symlink at ANY level. A plain
     * `mkdir($dir, 0700, true)` would silently FOLLOW a symlinked runtime base
     * (the base lives under world-writable /tmp), letting an attacker who plants
     * /tmp/unraid.rsync -> /somewhere redirect root-written logs out of the
     * sandbox. Mirrors Ssh::ensureRuntimeDirs. The post-mkdir re-check closes the
     * TOCTOU where a level is swapped for a symlink between create and use.
     */
    private static function ensureDir(string $dir): void
    {
        $chain = [self::base(), self::logsDir()];
        if ($dir !== self::logsDir()) {
            $chain[] = $dir;
        }
        foreach ($chain as $d) {
            if (is_link($d)) {
                throw new RuntimeException("Refusing to use a symlinked log dir: $d");
            }
            if (file_exists($d) && !is_dir($d)) {
                throw new RuntimeException("Log path exists but is not a directory: $d");
            }
            if (!is_dir($d) && !@mkdir($d, 0700) && !is_dir($d)) {
                throw new RuntimeException("Unable to create log dir: $d");
            }
            if (is_link($d)) {
                throw new RuntimeException("Refusing to use a symlinked log dir: $d");
            }
            @chmod($d, 0700);
        }
    }

    /**
     * Create (touch) a fresh run log for a job and return its path. The file is
     * created empty at mode 600 so rsync's --log-file can append to it and the
     * runner can write its preamble.
     */
    public static function openRun(string $jobId, ?int $now = null): string
    {
        self::ensureDir(self::jobLogDir($jobId));
        $path = self::newRunLogPath($jobId, $now);
        if (@file_put_contents($path, '') === false) {
            throw new RuntimeException("Unable to create run log: $path");
        }
        @chmod($path, 0600);
        // A fresh log starts below the size cap; clear any stale "already capped"
        // marker for this exact path (a re-used path in a long-lived process/test).
        unset(self::$capped[$path]);
        return $path;
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
    public static function append(string $path, string $line): void
    {
        self::ensureDir(dirname($path));
        if ($line === '' || substr($line, -1) !== "\n") {
            $line .= "\n";
        }
        $line = self::redact($line);
        self::appendCapped($path, $line);
    }

    /**
     * Append already-newline-terminated, already-redacted $data to $path,
     * enforcing the per-run-log byte cap. Only RUN logs (basename matching
     * RUN_FILE_REGEX) are capped; plugin.log and the runner's own preamble go
     * uncapped (plugin.log is the rolling cross-job log, bounded on READ by
     * tail()). Once a run log reaches the cap we stop appending and write a
     * single "[log truncated - exceeded N MiB]" marker.
     */
    private static function appendCapped(string $path, string $data): void
    {
        if ($data === '') {
            return;
        }
        if (!self::isRunLogPath($path)) {
            @file_put_contents($path, $data, FILE_APPEND | LOCK_EX);
            return;
        }

        $cap  = self::maxRunLogBytes();
        $size = @filesize($path);
        $size = ($size === false) ? 0 : (int) $size;

        if ($size >= $cap) {
            self::writeTruncationMarker($path, $cap);
            return;
        }
        // Writing this whole chunk would cross the cap: write only the bytes that
        // fit in the remaining room (truncating this chunk at the cap boundary),
        // then the one-time marker. The file never exceeds the cap + the marker.
        if ($size + strlen($data) > $cap) {
            $room = $cap - $size;
            if ($room > 0) {
                @file_put_contents($path, substr($data, 0, $room), FILE_APPEND | LOCK_EX);
            }
            self::writeTruncationMarker($path, $cap);
            return;
        }
        @file_put_contents($path, $data, FILE_APPEND | LOCK_EX);
    }

    /** Write the size-cap marker exactly once per run-log path. */
    private static function writeTruncationMarker(string $path, int $cap): void
    {
        if (!empty(self::$capped[$path])) {
            return;
        }
        self::$capped[$path] = true;
        $mib    = (int) round($cap / (1024 * 1024));
        $marker = self::TRUNCATE_MARKER_PREFIX . ' - exceeded ' . $mib . " MiB]\n";
        @file_put_contents($path, $marker, FILE_APPEND | LOCK_EX);
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
        return static function (string $chunk) use ($path): void {
            if ($chunk === '') {
                return;
            }
            self::appendCapped($path, self::redact($chunk));
        };
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
     * appending" semantics) and the one-time marker is appended. No-op for
     * non-run-log paths or files under the cap. Best-effort.
     *
     * @return bool true when the file was trimmed.
     */
    public static function enforceRunLogCap(string $path): bool
    {
        if (!self::isRunLogPath($path) || !is_file($path)) {
            return false;
        }
        // Already capped (the marker itself pushes the file a few bytes past the
        // cap, so a naive size check would re-trim forever): nothing more to do.
        if (!empty(self::$capped[$path])) {
            return false;
        }
        $cap  = self::maxRunLogBytes();
        $size = @filesize($path);
        $size = ($size === false) ? 0 : (int) $size;
        if ($size <= $cap) {
            return false;
        }
        // Truncate to the cap (keep the head), then append the one-time marker.
        $fh = @fopen($path, 'r+b');
        if ($fh === false) {
            return false;
        }
        if (@flock($fh, LOCK_EX)) {
            @ftruncate($fh, $cap);
            @flock($fh, LOCK_UN);
        }
        @fclose($fh);
        self::writeTruncationMarker($path, $cap);
        return true;
    }

    /**
     * Append a timestamped line to BOTH the given run log and the rolling
     * plugin log. The plugin log line is prefixed with the job id so the
     * cross-job log is readable.
     */
    public static function event(string $runLog, string $jobId, string $message): void
    {
        $stamp = gmdate('Y-m-d\TH:i:s\Z');
        $line  = '[' . $stamp . '] ' . $message;
        if ($runLog !== '') {
            self::append($runLog, $line);
        }
        self::ensureDir(self::logsDir());
        self::append(self::pluginLogPath(), '[' . $stamp . '] [' . $jobId . '] ' . $message);
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
        if (!is_file($path)) {
            return '';
        }
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return '';
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
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

        // ESCAPE before returning - the caller renders this verbatim. Log files
        // hold arbitrary bytes (rsync output, non-UTF-8 filenames); ENT_SUBSTITUTE
        // replaces invalid UTF-8 sequences with the Unicode replacement char
        // rather than letting htmlspecialchars() return '' / emit a warning, so
        // the viewer stays reliable on binary-ish output.
        return htmlspecialchars($data, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

        // Defence in depth: the basename pattern already forbids traversal, but
        // confirm the resolved path is still inside the job log dir before use.
        $realDir  = @realpath($dir);
        $realPath = @realpath($path);
        if ($realPath === false) {
            // The file may not exist (yet) - that's fine; the pattern guarantees
            // it can only ever name a file directly under the job dir.
            return $path;
        }
        if ($realDir === false || strpos($realPath, $realDir . '/') !== 0) {
            return null;
        }
        return $realPath;
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
     * @param callable(string):(?array)|null $summaryReader override for tests;
     *        fn(jobId): summary|null. null => Runner::readSummary.
     * @return array<int,array{id:string,ts:int,state:string}>
     */
    public static function listRuns(string $jobId, int $limit = 0, ?callable $summaryReader = null): array
    {
        $dir = self::jobLogDir($jobId);
        if (!is_dir($dir)) {
            return [];
        }
        $entries = @scandir($dir);
        if ($entries === false) {
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

        // Newest first. The stamp sorts lexically the same as chronologically, so
        // sort by name descending; fall back to ts to be safe.
        usort($runs, static function ($a, $b) {
            if ($a['ts'] === $b['ts']) {
                return strcmp($b['id'], $a['id']);
            }
            return $b['ts'] <=> $a['ts'];
        });

        if ($limit > 0 && count($runs) > $limit) {
            $runs = array_slice($runs, 0, $limit);
        }

        // Attribute the SUMMARY's state to the newest run whose start stamp
        // matches the summary startedAt (the run that produced the summary).
        $summary = null;
        if ($summaryReader !== null) {
            $summary = $summaryReader($jobId);
        } elseif (class_exists('Runner') && method_exists('Runner', 'readSummary')) {
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
     * Prune a job's run logs to the newest $keep (default = retention()), deleting
     * the OLDEST first. plugin.log is NEVER touched (it lives one level up under
     * logsDir and never matches the run-file pattern). Called on every run start
     * (the one place Phase 6 mutates state). Best-effort: a failed unlink is
     * ignored rather than failing a run.
     *
     * @return int the number of run logs deleted
     */
    public static function pruneRuns(string $jobId, ?int $keep = null): int
    {
        $keep = ($keep === null) ? self::retention() : max(1, (int) $keep);
        $dir  = self::jobLogDir($jobId);
        if (!is_dir($dir)) {
            return 0;
        }
        $entries = @scandir($dir);
        if ($entries === false) {
            return 0;
        }

        $runs = [];
        foreach ($entries as $name) {
            if (preg_match(self::RUN_FILE_REGEX, $name)) {
                $runs[] = $name;
            }
        }
        if (count($runs) <= $keep) {
            return 0;
        }

        // Sort NEWEST first (stamp sorts chronologically); the tail past $keep is
        // the oldest and gets deleted.
        rsort($runs); // descending lexical == descending chronological for the stamp
        $stale   = array_slice($runs, $keep);
        $deleted = 0;
        foreach ($stale as $name) {
            if (@unlink($dir . '/' . $name)) {
                $deleted++;
            }
        }
        return $deleted;
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

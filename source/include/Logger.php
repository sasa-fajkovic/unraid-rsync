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

class Logger
{
    /** Max bytes tail() will read off the END of a log (bounds memory + DoS). */
    const TAIL_MAX_BYTES = 256 * 1024; // 256 KiB

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
        return ($clean === '' || $clean === null) ? 'unknown' : $clean;
    }

    /** Ensure a log directory exists (mode 700; logs may name paths/hosts). */
    private static function ensureDir(string $dir): void
    {
        if (is_link($dir)) {
            throw new RuntimeException("Refusing to use a symlinked log dir: $dir");
        }
        if (file_exists($dir) && !is_dir($dir)) {
            throw new RuntimeException("Log path exists but is not a directory: $dir");
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create log dir: $dir");
        }
        @chmod($dir, 0700);
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
        return $path;
    }

    /**
     * Append a line (a trailing newline is added if absent) to an arbitrary log
     * file, creating its directory if needed. Used by the runner for its
     * preamble/epilogue lines around rsync's own --log-file output.
     */
    public static function append(string $path, string $line): void
    {
        self::ensureDir(dirname($path));
        if ($line === '' || substr($line, -1) !== "\n") {
            $line .= "\n";
        }
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
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
        return self::jobLogDir($jobId) . '/' . $runs[0]['id'];
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

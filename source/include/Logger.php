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

        // ESCAPE before returning - the caller renders this verbatim.
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
}

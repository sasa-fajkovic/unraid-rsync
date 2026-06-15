<?php
/**
 * History.php - the persistent per-execution run history.
 *
 * Each finished run (real OR dry-run, manual OR scheduled) appends ONE compact
 * JSON record to a per-job append-only file on the USB flash:
 *
 *   <UR_CONFIG_BASE>/runs/<jobid>.history.jsonl   (one JSON object per line)
 *
 * This is DELIBERATELY separate from the per-run LOGS, which live in tmpfs
 * (RAM, cleared on reboot) to avoid wearing the FAT32 flash. The history records
 * are tiny (a handful of scalar fields, NO log bytes, NO secrets) and are
 * written at the SAME cadence as the existing last-run summary (one small append
 * per run, in Runner's finally), so they add no meaningful flash wear. A record
 * carries `logRef` - the run log's BASENAME only (e.g. "run-20260614T120000Z.log",
 * never a tmpfs path) - which resolves back to the live log via
 * Logger::runLogPathById() / the getJobLog?run= endpoint while that tmpfs log
 * still exists; after a reboot the record persists but the log is gone, so the
 * UI shows "log not retained".
 *
 * Every method is best-effort and MUST NOT throw: a history failure can never be
 * allowed to crash a run (mirrors Runner::writeSummary's silent-return guards).
 *
 * The config base is overridable via the UR_CONFIG_BASE constant (tests point it
 * at a temp dir), shared with Config / Runner::writeSummary.
 */

if (!defined('UR_CONFIG_BASE')) {
    define('UR_CONFIG_BASE', '/boot/config/plugins/unraid.rsync');
}

class History
{
    /** Default records kept per job until the retention setting wires in (PR-E). */
    const DEFAULT_KEEP = 100;

    /** Hard ceiling on records kept, mirroring the retention setting's max. */
    const MAX_KEEP = 9999;

    /**
     * Sanitise a job id for use as a filename segment. Mirrors
     * Runner::writeSummary / the SEC-01 safeId helpers: strip anything that
     * isn't a safe filename char, and collapse a pure-dots result (traversal)
     * to a literal.
     */
    private static function safeId(string $jobId): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $jobId);
        if ($clean === '' || $clean === null || preg_match('/^\.+$/', $clean)) {
            return 'unknown';
        }
        return $clean;
    }

    /** The runs/ directory on the flash (shared with the last-run summaries). */
    private static function dir(): string
    {
        return rtrim(UR_CONFIG_BASE, '/') . '/runs';
    }

    /** Absolute path of a job's history file. */
    public static function path(string $jobId): string
    {
        return self::dir() . '/' . self::safeId($jobId) . '.history.jsonl';
    }

    /**
     * Canonical, secret-free record shape. logRef is a run-log BASENAME only -
     * never a path - so no tmpfs path ever lands on the flash. trigger/state are
     * passed through as strings; trigger is clamped to the closed set.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function normalizeRecord(array $r): array
    {
        return [
            'startedAt'   => (string) ($r['startedAt'] ?? ''),
            'finishedAt'  => (string) ($r['finishedAt'] ?? ''),
            'jobName'     => (string) ($r['jobName'] ?? ''),
            'dryRun'      => !empty($r['dryRun']),
            'trigger'     => (($r['trigger'] ?? '') === 'schedule') ? 'schedule' : 'manual',
            'state'       => (string) ($r['state'] ?? ''),
            'exitCode'    => (int) ($r['exitCode'] ?? 0),
            'durationSec' => (int) ($r['durationSec'] ?? 0),
            'logRef'      => basename((string) ($r['logRef'] ?? '')),
        ];
    }

    /**
     * Append ONE execution record (best-effort; never throws). The append is
     * atomic per line via LOCK_EX so concurrent runs of different jobs (and the
     * lazy prune) can't interleave a half-written line.
     *
     * @param array<string,mixed> $record
     */
    public static function append(string $jobId, array $record): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode(self::normalizeRecord($record), JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        // chmod only when the file is first created, so a normal append doesn't
        // touch the inode's mode every run (extra flash metadata write).
        $path  = self::path($jobId);
        $isNew = !is_file($path);
        if (@file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
            return;
        }
        if ($isNew) {
            @chmod($path, 0644);
        }
    }

    /**
     * List a job's records NEWEST-FIRST with paging. Records are stored
     * chronologically (append order), so newest-first = reverse. offset/limit
     * are clamped (limit 1..100); total is the full record count so a client can
     * render "page X of N".
     *
     * @return array{total:int,offset:int,limit:int,runs:array<int,array<string,mixed>>}
     */
    public static function list(string $jobId, int $offset = 0, int $limit = 25): array
    {
        $offset = max(0, $offset);
        $limit  = max(1, min(100, $limit));

        $records = [];
        $path = self::path($jobId);
        if (is_file($path)) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $d = json_decode($line, true);
                    if (is_array($d)) {
                        $records[] = self::normalizeRecord($d);
                    }
                }
            }
        }

        $total   = count($records);
        $newest  = array_reverse($records); // newest-first
        $page    = array_slice($newest, $offset, $limit);
        return [
            'total'  => $total,
            'offset' => $offset,
            'limit'  => $limit,
            'runs'   => array_values($page),
        ];
    }

    /**
     * Every job's records merged and sorted NEWEST-FIRST (by startedAt, an
     * ISO-8601/Zulu string so a lexicographic compare is chronological). Each
     * row is tagged with its `jobId` (derived from the history filename) so an
     * all-jobs view can show which job a run belongs to and resolve its log.
     *
     * Reads all per-job history files; bounded by the retention cap per job, so
     * the total stays modest on a real box. Best-effort: unreadable/!json lines
     * are skipped.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function allSorted(): array
    {
        $records = [];
        foreach (@glob(self::dir() . '/*.history.jsonl') ?: [] as $file) {
            // Filename is "<jobid>.history.jsonl"; strip the suffix for the id.
            $jobId = basename($file, '.history.jsonl');
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                $d = json_decode($line, true);
                if (is_array($d)) {
                    $rec = self::normalizeRecord($d);
                    $rec['jobId'] = $jobId;
                    $records[] = $rec;
                }
            }
        }
        usort($records, static function ($a, $b) {
            return strcmp((string) ($b['startedAt'] ?? ''), (string) ($a['startedAt'] ?? ''));
        });
        return $records;
    }

    /**
     * Paginated newest-first view across ALL jobs (see allSorted). Same return
     * shape as list(); each run carries a `jobId`.
     *
     * @return array{total:int,offset:int,limit:int,runs:array<int,array<string,mixed>>}
     */
    public static function listAll(int $offset = 0, int $limit = 25): array
    {
        $offset  = max(0, $offset);
        $limit   = max(1, min(100, $limit));
        $records = self::allSorted();
        return [
            'total'  => count($records),
            'offset' => $offset,
            'limit'  => $limit,
            'runs'   => array_values(array_slice($records, $offset, $limit)),
        ];
    }

    /**
     * Prune to the newest $keep records. LAZY: only rewrites the file when it is
     * actually over the cap (so a run that doesn't exceed the cap pays no extra
     * flash write). Atomic temp + rename. Best-effort; never throws.
     */
    public static function prune(string $jobId, int $keep): void
    {
        $keep = max(1, min(self::MAX_KEEP, $keep));
        $path = self::path($jobId);
        if (!is_file($path)) {
            return;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) <= $keep) {
            return; // under cap -> no rewrite
        }
        $kept = array_slice($lines, -$keep); // newest $keep (chronological tail)
        $tmp  = @tempnam(self::dir(), '.history.');
        if ($tmp === false) {
            return;
        }
        if (@file_put_contents($tmp, implode("\n", $kept) . "\n") === false) {
            @unlink($tmp);
            return;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * Remove a job's history file (best-effort). Called when a job is deleted so
     * orphaned history files don't accumulate on the flash.
     */
    public static function delete(string $jobId): void
    {
        $path = self::path($jobId);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

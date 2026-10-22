<?php

declare(strict_types=1);

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

require_once __DIR__ . '/Util.php';

class History
{
    /** Default records kept per job until the retention setting wires in (PR-E). */
    const DEFAULT_KEEP = 100;

    /** Hard ceiling on records kept, mirroring the retention setting's max. */
    const MAX_KEEP = 9999;

    /** The runs/ directory on the flash (shared with the last-run summaries). */
    private static function dir(): string
    {
        return rtrim(UR_CONFIG_BASE, '/') . '/runs';
    }

    /** Absolute path of a job's history file. */
    public static function path(string $jobId): string
    {
        return self::dir() . '/' . Util::safeFileId($jobId) . '.history.jsonl';
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
        $out = [
            'startedAt'   => (string) ($r['startedAt'] ?? ''),
            'finishedAt'  => (string) ($r['finishedAt'] ?? ''),
            'jobName'     => (string) ($r['jobName'] ?? ''),
            'dryRun'      => !empty($r['dryRun']),
            'trigger'     => (($r['trigger'] ?? '') === 'schedule') ? 'schedule' : 'manual',
            'state'       => (string) ($r['state'] ?? ''),
            'exitCode'    => (int) ($r['exitCode'] ?? 0),
            'durationSec' => (int) ($r['durationSec'] ?? 0),
            'logRef'      => basename((string) ($r['logRef'] ?? '')),
            'reason'      => (string) ($r['reason'] ?? ''),
        ];
        // Snapshot jobs only (docs/design/snapshot-mode.md §4.3); a mirror
        // record keeps exactly the keys above.
        if (array_key_exists('snapshot', $r)) {
            $out['snapshot']  = (string) $r['snapshot'];
            $out['committed'] = !empty($r['committed']);
            $out['suspect']   = !empty($r['suspect']);
            $out['pruned']    = (int) ($r['pruned'] ?? 0);
            if (isset($r['growthKB'])) {
                $out['growthKB'] = (int) $r['growthKB'];
            }
        }
        if ((string) ($r['lastError'] ?? '') !== '') {
            $out['lastError'] = (string) $r['lastError'];
        }
        return $out;
    }

    /**
     * The per-job lock that append() and prune() share. A sibling file, never
     * the history file itself: prune() replaces that by rename, so a lock held
     * on its inode would let an append land in the file being discarded.
     */
    private static function lockPath(string $jobId): string
    {
        return self::dir() . '/' . Util::safeFileId($jobId) . '.history.lock';
    }

    /**
     * Append ONE execution record. Never throws. A partial last line (a write
     * cut short by power loss) is ended with "\n" first, so it stays one
     * unparseable line instead of swallowing this record.
     *
     * @param array<string,mixed> $record
     * @return bool false when the record could not be written
     */
    public static function append(string $jobId, array $record): bool
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode(self::normalizeRecord($record), JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $path = self::path($jobId);
        try {
            return Util::withLock(self::lockPath($jobId), static function () use ($path, $json): bool {
                clearstatcache(true, $path);
                $size = is_file($path) ? (int) @filesize($path) : -1;
                $sep  = '';
                if ($size > 0) {
                    $fh   = @fopen($path, 'rb');
                    $last = ($fh !== false && @fseek($fh, -1, SEEK_END) === 0) ? @fread($fh, 1) : "\n";
                    if ($fh !== false) {
                        fclose($fh);
                    }
                    $sep = ($last === "\n") ? '' : "\n";
                }
                if (@file_put_contents($path, $sep . $json . "\n", FILE_APPEND | LOCK_EX) === false) {
                    return false;
                }
                // chmod only on create: a mode change per run is another flash write.
                if ($size < 0) {
                    @chmod($path, 0644);
                }
                return true;
            });
        } catch (Throwable $e) {
            return false;
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
        $offset  = max(0, $offset);
        $limit   = max(1, min(100, $limit));
        $records = self::records($jobId);
        return [
            'total'  => count($records),
            'offset' => $offset,
            'limit'  => $limit,
            'runs'   => array_values(array_slice($records, $offset, $limit)),
        ];
    }

    /**
     * Every record of one job, NEWEST-FIRST (records are stored in append
     * order). Unparseable lines are skipped.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function records(string $jobId): array
    {
        $records = [];
        $path = self::path($jobId);
        $lines = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        foreach (is_array($lines) ? $lines : [] as $line) {
            $d = json_decode($line, true);
            if (is_array($d)) {
                $records[] = self::normalizeRecord($d);
            }
        }
        return array_reverse($records);
    }

    /**
     * Overwrite $fields of ONE run's record, found newest-first by its
     * startedAt AND its logRef (the run log's unique name): a teardown step
     * after the append changed that run's outcome. Never by position - a
     * SKIPPED record (no logRef) may have been appended after it, even within
     * the same second. Same lock as append() and prune(), same rename as
     * prune(). false when it could not.
     *
     * @param array<string,mixed> $fields
     */
    public static function amend(string $jobId, string $startedAt, string $logRef, array $fields): bool
    {
        if ($logRef === '') {
            return false;
        }
        $path = self::path($jobId);
        try {
            return Util::withLock(self::lockPath($jobId), static function () use ($path, $startedAt, $logRef, $fields): bool {
                $lines = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
                if (!is_array($lines)) {
                    return false;
                }
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $rec = json_decode((string) $lines[$i], true);
                    if (!is_array($rec) || ($rec['logRef'] ?? null) !== $logRef || ($rec['startedAt'] ?? null) !== $startedAt) {
                        continue;
                    }
                    $json = json_encode(self::normalizeRecord(array_merge($rec, $fields)), JSON_UNESCAPED_SLASHES);
                    if ($json === false) {
                        return false;
                    }
                    $lines[$i] = $json;
                    Util::writeAtomic($path, implode("\n", $lines) . "\n", 0644);
                    return true;
                }
                return false;
            });
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * A cheap change marker for a job's history file (one stat, no parse):
     * mtime, size and inode, so an append (size) and a prune (rename: inode)
     * both change it. '' when there is no file.
     */
    public static function revision(string $jobId): string
    {
        $path = self::path($jobId);
        clearstatcache(true, $path);
        $st = @stat($path);
        return $st === false ? '' : $st['mtime'] . '-' . $st['size'] . '-' . $st['ino'];
    }

    /**
     * The job ids that have a history file, including jobs since deleted.
     *
     * @return array<int,string>
     */
    public static function jobIds(): array
    {
        return array_map(
            static fn(string $f): string => basename($f, '.history.jsonl'),
            @glob(self::dir() . '/*.history.jsonl') ?: []
        );
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
     * Prune to the newest $keep records. LAZY: only rewrites the file when it is
     * actually over the cap (so a run that doesn't exceed the cap pays no extra
     * flash write). Util::writeAtomic under the lock append() takes, so an
     * append racing the rewrite is never lost. Best-effort; never throws.
     */
    public static function prune(string $jobId, int $keep): void
    {
        $keep = max(1, min(self::MAX_KEEP, $keep));
        $path = self::path($jobId);
        if (!is_file($path)) {
            return;
        }
        try {
            Util::withLock(self::lockPath($jobId), static function () use ($path, $keep): void {
                $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!is_array($lines) || count($lines) <= $keep) {
                    return; // under cap -> no rewrite
                }
                // newest $keep (chronological tail)
                Util::writeAtomic($path, implode("\n", array_slice($lines, -$keep)) . "\n", 0644);
            });
        } catch (Throwable $e) {
            // best-effort
        }
    }
}

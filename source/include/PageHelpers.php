<?php

declare(strict_types=1);

/**
 * PageHelpers.php - the pure display rules shared by the page bodies, the
 * handler's getStatus and (through the JS mirrors in _options_form.php) the
 * browser: the state-badge vocabulary, a job's derived state, the next-run /
 * last-run labels and the relative-time rule.
 *
 * Kept out of source/pages so it is unit-tested and inside PHPStan's scope; the
 * pages hard-require webGui paths and are neither. Every method returns PLAIN
 * TEXT: callers escape it. Labels are English on purpose - the JS mirrors that
 * repaint these cells after the first poll are English too, so a translated
 * server render would only flash before being overwritten.
 *
 * Deliberately loads nothing: the Dashboard tile reads STATES on a page that
 * must not pull in Config.php (which pins the process timezone at load).
 * nextRunLabel() needs Cron, which every caller has already loaded.
 */

final class PageHelpers
{
    public const HANDLER_URL = '/plugins/unraid.rsync/include/handler.php';

    /**
     * state => [css modifier, label]. The PHP badge, window.urBadge and the
     * Dashboard tile all render from this one map. TIMEOUT is a warning, not a
     * failure (Runner::notifyImportance agrees); PENDING means never run.
     */
    public const STATES = [
        'RUNNING' => ['cls' => 'running', 'label' => 'Running'],
        'SUCCESS' => ['cls' => 'success', 'label' => 'Success'],
        'WARNING' => ['cls' => 'warning', 'label' => 'Warning'],
        'PARTIAL' => ['cls' => 'warning', 'label' => 'Partial'],
        'TIMEOUT' => ['cls' => 'warning', 'label' => 'Timeout'],
        'FAILED'  => ['cls' => 'failed',  'label' => 'Failed'],
        'ABORTED' => ['cls' => 'aborted', 'label' => 'Aborted'],
        'PENDING' => ['cls' => 'pending', 'label' => 'Never run'],
    ];

    /** @return array{cls:string,label:string} */
    public static function state(string $state): array
    {
        return self::STATES[strtoupper($state)] ?? self::STATES['PENDING'];
    }

    /**
     * RUNNING (live) overrides the last-run summary; otherwise the summary's
     * state; PENDING when there is none (never run).
     *
     * @param array<string,mixed>|null $summary
     */
    public static function deriveState(bool $running, ?array $summary): string
    {
        if ($running) {
            return 'RUNNING';
        }
        if (is_array($summary) && !empty($summary['state'])) {
            return (string) $summary['state'];
        }
        return 'PENDING';
    }

    /**
     * The two largest ADJACENT units, the second dropped when zero:
     * "in 1h 36m", "6d 7h ago", "6d ago", "45s ago", "now". Mirrored by
     * window.urUtil.relativeTime.
     */
    public static function relativeTime(int $epoch, int $now): string
    {
        $delta = $epoch - $now;
        $abs   = abs($delta);
        if ($abs < 1) {
            return 'now';
        }
        $units = [['d', 86400], ['h', 3600], ['m', 60], ['s', 1]];
        $text  = '';
        foreach ($units as $i => [$suffix, $size]) {
            if ($abs < $size) {
                continue;
            }
            $text = intdiv($abs, $size) . $suffix;
            if (isset($units[$i + 1])) {
                [$nextSuffix, $nextSize] = $units[$i + 1];
                $n = intdiv($abs % $size, $nextSize);
                if ($n > 0) {
                    $text .= ' ' . $n . $nextSuffix;
                }
            }
            break;
        }
        return $delta > 0 ? 'in ' . $text : $text . ' ago';
    }

    /**
     * The Next-run cell. The clause order is load-bearing and mirrored by
     * window.urUtil.nextRunLabel: manual-only (such a job IS enabled and keeps a
     * stale schedule string), then disabled, then an uncomputable next fire
     * (em-dash), then the server-local time.
     *
     * @param array<string,mixed> $job
     */
    public static function nextRunLabel(array $job, int $now): string
    {
        if (!empty($job['manualOnly'])) {
            return 'manual (on demand)';
        }
        if (empty($job['enabled'])) {
            return 'disabled';
        }
        $schedule = trim((string) ($job['schedule'] ?? ''));
        $next = ($schedule === '') ? null : Cron::nextRun($schedule, $now);
        if ($next === null) {
            return '—';
        }
        return date('Y-m-d H:i', $next) . ' (' . self::relativeTime($next, $now) . ')';
    }

    /**
     * The Last-run cell from a summary's UTC finishedAt, or an em-dash. A
     * finishedAt in the future (clock step) reads as "now", never "in …".
     *
     * @param array<string,mixed>|null $summary
     */
    public static function lastRunLabel(?array $summary, int $now): string
    {
        if (!is_array($summary) || empty($summary['finishedAt'])) {
            return '—';
        }
        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', (string) $summary['finishedAt'], new DateTimeZone('UTC'));
        if ($dt === false) {
            return '—';
        }
        $ts = $dt->getTimestamp();
        return date('Y-m-d H:i', $ts) . ' (' . self::relativeTime(min($ts, $now), $now) . ')';
    }

    /**
     * Line count of a hook textarea's saved value, or of its placeholder while
     * empty. Trailing blank lines are trimmed: the caller adds one buffer row,
     * and counting a saved trailing "\n" as well doubled the blank space.
     */
    public static function hookLineCount(string $value, string $placeholder): int
    {
        $basis = rtrim(($value !== '') ? $value : $placeholder, "\n");
        return max(1, substr_count($basis, "\n") + 1);
    }
}

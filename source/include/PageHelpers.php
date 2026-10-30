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
 * TEXT: callers escape it. Labels go through t(), the same i18n() map the
 * browser gets as UR_I18N, so a translated server render and the JS repaint
 * after the first poll agree; off the webGui (getStatus, tests) t() is English.
 *
 * Deliberately loads nothing: the Dashboard tile reads STATES on a page that
 * must not pull in Config.php (which pins the process timezone at load).
 * nextRunLabel() needs Cron, config() Config and running() RunState, which
 * every caller has already loaded.
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
        'CRASHED' => ['cls' => 'failed',  'label' => 'Crashed'],
        'ABORTED' => ['cls' => 'aborted', 'label' => 'Aborted'],
        'SKIPPED' => ['cls' => 'pending', 'label' => 'Skipped'],
        'PENDING' => ['cls' => 'pending', 'label' => 'Never run'],
    ];

    /**
     * Every string the pages render from JavaScript, key => English: the
     * browser gets it translated as UR_I18N (ur_emit_i18n()), window.urI18n
     * reads it, and t() serves the PHP renderers from the same map. i18n()
     * adds state.<STATE> and dryRun.<STATE> for every STATES entry. %s / %d
     * are filled in order.
     */
    public const I18N = [
        'rel.now'             => 'now',
        'rel.future'          => 'in %s',
        'rel.past'            => '%s ago',
        'next.manual'         => 'manual (on demand)',
        'next.disabled'       => 'disabled',
        'loading'             => 'Loading…',
        'saving'              => 'Saving…',
        'saved'               => 'Saved.',
        'saveFailed'          => 'Save failed.',
        'deleted'             => 'Deleted.',
        'deleteFailed'        => 'Delete failed.',
        'done'                => 'Done.',
        'ok'                  => 'OK',
        'copied'              => 'Copied!',
        'unexpected'          => 'Unexpected error: %s',
        'ajax.failed'         => 'Request failed',
        'ajax.timedOut'       => '%s: timed out (no response in time).',
        'ajax.network'        => '%s: could not reach the server (network error).',
        'ajax.nonJson'        => '%s: the server returned a non-JSON response (HTTP %s).',
        'ajax.http'           => '%s (HTTP %s).',
        'delete.confirm'      => 'Confirm delete?',
        'delete.confirmHint'  => '%s Click "Confirm delete?" again to proceed.',
        'log.unavailable'     => 'Log not available.',
        'log.notInRam'        => 'Log not available (run logs live in RAM and are cleared on reboot).',
        'filter.ruleType'     => 'Rule type',
        'filter.include'      => '+ include',
        'filter.exclude'      => '- exclude',
        'filter.placeholder'  => 'pattern, e.g. *.tmp',
        'filter.pattern'      => 'Pattern',
        'row.up'              => 'Move up',
        'row.down'            => 'Move down',
        'row.remove'          => 'Remove',
        'preview.none'        => '(no options set)',
        'preview.failed'      => 'Preview failed',
        'preview.unreachable' => 'Preview failed: could not reach the server.',
        'pairs.localSource'   => 'Source (this Unraid server)',
        'pairs.localDest'     => 'Destination (this Unraid server)',
        'pairs.remoteSource'  => 'Source (remote host)',
        'pairs.remoteDest'    => 'Destination (remote host)',
        'pairs.localSecond'   => 'Destination (this Unraid server)',
        'pairs.moduleSource'  => 'Source (remote module)',
        'pairs.moduleDest'    => 'Destination (remote module)',
        'pairs.snapshotDest'  => 'Destination (snapshot sub-directory)',
        'jobs.applyFirst'     => 'Apply changes first.',
        'jobs.actionFailed'   => 'Action failed.',
        'jobs.dryStarted'     => 'Dry-run started.',
        'jobs.runStarted'     => 'Run started.',
        'jobs.removeNamed'    => 'Remove the job "%s"? It is deleted permanently when you click Apply.',
        'jobs.removeUnnamed'  => 'Remove this job? It is deleted permanently when you click Apply.',
        'jobs.otherTransport' => '(other transport)',
        'jobs.logTitle'       => 'Run log — %s',
        'jobs.dryRunTag'      => '(dry run)',
        'jobs.latestTag'      => '(latest)',
        'cron.at'             => 'at %s',
        'cron.everyMinute'    => 'every minute',
        'cron.everyMinutes'   => 'every %s minutes',
        'cron.minuteOfHour'   => 'at minute %s of every hour',
        'cron.everyHours'     => 'every %s hours',
        'cron.minuteHour'     => 'minute %s, hour %s',
        'cron.onDays'         => 'on %s',
        'cron.onWeekday'      => 'on weekday %s',
        'cron.onDay'          => 'on day %s',
        'cron.onDayOfMonth'   => 'on day-of-month %s',
        'cron.inMonths'       => 'in %s',
        'cron.inMonth'        => 'in month %s',
        'cron.everyDay'       => '%s every day',
        'dow.0'               => 'Sun',
        'dow.1'               => 'Mon',
        'dow.2'               => 'Tue',
        'dow.3'               => 'Wed',
        'dow.4'               => 'Thu',
        'dow.5'               => 'Fri',
        'dow.6'               => 'Sat',
        'mon.1'               => 'Jan',
        'mon.2'               => 'Feb',
        'mon.3'               => 'Mar',
        'mon.4'               => 'Apr',
        'mon.5'               => 'May',
        'mon.6'               => 'Jun',
        'mon.7'               => 'Jul',
        'mon.8'               => 'Aug',
        'mon.9'               => 'Sep',
        'mon.10'              => 'Oct',
        'mon.11'              => 'Nov',
        'mon.12'              => 'Dec',
        'conn.deleteWarn'     => 'Delete this saved connection? Jobs that use it will be DISABLED.',
        'conn.enterHost'      => 'Enter a host first.',
        'conn.discovering'    => 'Discovering…',
        'conn.discoveringFor' => 'Discovering host key… (up to %ss)',
        'conn.finishing'      => 'Finishing up…',
        'conn.discovered'     => 'Host key discovered ✓',
        'conn.discoverFailed' => 'Host key discovery failed.',
        'conn.testing'        => 'Testing…',
        'conn.testFailed'     => 'Connection test failed.',
        'cred.copyTitle'      => 'Copy manually: select all and copy',
        'cred.copyManually'   => 'Copy manually:',
        'cred.enterName'      => 'Enter a key name first.',
        'cred.generating'     => 'Generating…',
        'cred.generated'      => 'Key "%s" generated. Fingerprint: %s',
        'cred.genFailed'      => 'Key generation failed.',
        'cred.pasteKey'       => 'Paste a private and/or public key.',
        'cred.importing'      => 'Importing…',
        'cred.imported'       => 'Key "%s" imported. Fingerprint: %s',
        'cred.importFailed'   => 'Key import failed.',
        'cred.deleteWarn'     => 'Delete key "%s"? Connections that use it must be repointed first.',
        'status.abort'        => 'Abort',
        'status.jobsRunning'  => '%d job(s) running.',
        'status.idle'         => 'Idle',
        'status.noneRunning'  => 'No jobs are running.',
        'status.logEmpty'     => 'No log entries yet. The plugin log fills as jobs run; it lives in memory and is cleared on reboot.',
        'status.abortAsked'   => 'Abort requested.',
        'status.abortFailed'  => 'Abort failed.',
        'status.present'      => 'Present',
        'status.missing'      => 'Missing',
        'status.noVersion'    => '(version unavailable)',
        'status.noRsync'      => 'rsync not found.',
        'hist.dryRun'         => 'Dry run',
        'hist.real'           => 'Real',
        'hist.schedule'       => 'Schedule',
        'hist.manual'         => 'Manual',
        'hist.snapshot'       => 'Snapshot %s',
        'hist.noSnapshot'     => 'No snapshot',
        'hist.committed'      => 'committed: %s',
        'hist.yes'            => 'yes',
        'hist.no'             => 'no',
        'hist.suspect'        => 'SUSPECT',
        'hist.pruned'         => 'pruned: %s',
        'hist.noMatch'        => 'No executions match.',
        'hist.noneOnPage'     => 'No executions on this page.',
        'hist.exitTitle'      => 'rsync exit %s: %s',
        'hist.exitParen'      => '(exit %s)',
        'hist.view'           => 'View',
        'hist.download'       => 'Download',
        'hist.pageInfo'       => 'of %s (%s total)',
        'hist.loadFailed'     => 'Could not load history.',
        'hist.loadNetwork'    => 'Could not load history (network error).',
        'hist.logGone'        => 'Log not retained (run logs live in RAM and are cleared on reboot).',
        'hist.logFailed'      => 'Could not load the log.',
    ];

    /** One label per transport, shared by the Job and Connection selects. */
    public const TRANSPORT_LABELS = [
        'SSH'    => 'SSH (rsync over SSH)',
        'DAEMON' => 'rsync daemon (rsyncd)',
        'LOCAL'  => 'Local (this server)',
    ];

    /** SSH auth methods as the Connections tab names them. */
    public const AUTH_LABELS = [
        'KEYFILE'  => 'Key file on disk',
        'KEY'      => 'Managed key',
        'PASSWORD' => 'Password',
    ];

    /** @var array<string,string>|null */
    private static ?array $i18n = null;

    /** @var (Closure(string): string)|null test seam standing in for ur_t_text() */
    public static ?Closure $translate = null;

    /** @var array<string,mixed>|Throwable|null */
    private static array|Throwable|null $config = null;

    /** @var array<string,bool> */
    private static array $running = [];

    /**
     * Config::load() once per request: all seven tabs render into one
     * document. A load that threw throws again on every call, so each tab
     * still shows its own load error.
     *
     * @return array<string,mixed>
     */
    public static function config(): array
    {
        if (self::$config === null) {
            try {
                self::$config = Config::load();
            } catch (Throwable $e) {
                self::$config = $e;
            }
        }
        if (self::$config instanceof Throwable) {
            throw self::$config;
        }
        return self::$config;
    }

    /** RunState::isRunning once per job per request (a first call may reap a dead runner). */
    public static function running(string $jobId): bool
    {
        return self::$running[$jobId] ??= RunState::isRunning($jobId);
    }

    /** Forget the request-scoped config and running state (tests render several requests in one process). */
    public static function resetRequest(): void
    {
        self::$config  = null;
        self::$running = [];
    }

    /**
     * I18N plus state.<STATE> (the badge label) and dryRun.<STATE> ("Dry-run
     * OK" for SUCCESS, else "Dry-run <label>") for every STATES entry.
     *
     * @return array<string,string>
     */
    public static function i18n(): array
    {
        if (self::$i18n === null) {
            self::$i18n = self::I18N;
            foreach (self::STATES as $key => $entry) {
                self::$i18n['state.' . $key]  = $entry['label'];
                self::$i18n['dryRun.' . $key] = 'Dry-run ' . ($key === 'SUCCESS' ? 'OK' : strtolower($entry['label']));
            }
        }
        return self::$i18n;
    }

    /**
     * An i18n() entry, %s / %d filled in order: translated through ur_t_text()
     * where the page partial is loaded (the webGui), English elsewhere.
     * ur_emit_i18n() builds UR_I18N from this too, so both sides agree.
     */
    public static function t(string $key, string|int ...$args): string
    {
        $text = self::i18n()[$key] ?? $key;
        if (self::$translate !== null) {
            $text = (self::$translate)($text);
        } elseif (function_exists('ur_t_text')) {
            $text = ur_t_text($text);
        }
        if ($args === []) {
            return $text;
        }
        return (string) preg_replace_callback('/%[sd]/', static function (array $m) use (&$args): string {
            return $args === [] ? $m[0] : (string) array_shift($args);
        }, $text);
    }

    /**
     * Pair labels follow the same source/destination rule as the runner.
     * Returned keys are shared with the browser's translation map.
     *
     * @param array<string,mixed> $job
     * @return array{local:string,remote:string,arrow:string}
     */
    public static function pairPresentation(array $job): array
    {
        $transport = strtoupper(trim((string) ($job['transport'] ?? 'SSH')));
        $pull = Job::sides($job)['dst'] === 'local';
        $right = $transport === 'LOCAL' ? 'pairs.localSecond'
            : ($transport === 'DAEMON' ? ($pull ? 'pairs.moduleSource' : 'pairs.moduleDest')
                : ($pull ? 'pairs.remoteSource' : 'pairs.remoteDest'));
        if (Config::normalizeSnapshot($job['snapshot'] ?? null) !== null) {
            $right = 'pairs.snapshotDest';
        }
        return ['local' => $pull ? 'pairs.localDest' : 'pairs.localSource', 'remote' => $right, 'arrow' => $pull ? '←' : '→'];
    }

    /**
     * STATES' class and translated label (t('state.<STATE>')); an unknown
     * state is PENDING.
     *
     * @return array{cls:string,label:string}
     */
    public static function state(string $state): array
    {
        $key = strtoupper($state);
        if (!isset(self::STATES[$key])) {
            $key = 'PENDING';
        }
        return ['cls' => self::STATES[$key]['cls'], 'label' => self::t('state.' . $key)];
    }

    /**
     * RUNNING (live) overrides the last real run (Runner::lastReal: a dry run
     * never sets the badge); otherwise its state; PENDING when there is none.
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
            return self::t('rel.now');
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
        return self::t($delta > 0 ? 'rel.future' : 'rel.past', $text);
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
            return self::t('next.manual');
        }
        if (empty($job['enabled'])) {
            return self::t('next.disabled');
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
     * Callers pass the LAST REAL run (Runner::lastReal), never a dry run.
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
     * The options preview as a reader wants it: with -a present, the positive
     * flags -a already implies are dropped. Display only - Rsync::optionTokens
     * and the argv keep them - and everything kept stays in emitted order, so
     * the --no-* negations still follow -a and the filter rules keep theirs.
     * Needs Rsync, which every caller has already loaded.
     *
     * @param array<int,string> $tokens Rsync::optionTokens() output
     * @return array<int,string>
     */
    public static function previewTokens(array $tokens): array
    {
        if (!in_array('-a', $tokens, true)) {
            return array_values($tokens);
        }
        $implied = array_intersect_key(Rsync::BOOL_FLAGS, Rsync::ARCHIVE_IMPLIED);
        return array_values(array_filter($tokens, static fn(string $t): bool => !in_array($t, $implied, true)));
    }

    /**
     * The last execution, when it was a dry run: {label, finishedAt, state},
     * label t('dryRun.<state>'): "Dry-run OK" for SUCCESS, else "Dry-run
     * <state label>" ("Dry-run failed"); state is the STATES key (an unknown
     * one is PENDING), which the JS mirror translates by. null otherwise.
     * getStatus sends it as lastDryRun.
     *
     * @param array<string,mixed>|null $summary
     * @return array{label:string,finishedAt:string,state:string}|null
     */
    public static function lastDryRun(?array $summary): ?array
    {
        if (!is_array($summary) || empty($summary['dryRun'])) {
            return null;
        }
        $state = strtoupper((string) ($summary['state'] ?? ''));
        if (!isset(self::STATES[$state])) {
            $state = 'PENDING';
        }
        return [
            'label'      => self::t('dryRun.' . $state),
            'finishedAt' => (string) ($summary['finishedAt'] ?? ''),
            'state'      => $state,
        ];
    }

    /**
     * The neutral second line under Last run: "Dry-run OK · <time>", or ''.
     * Mirrored by window.urUtil.dryRunLine.
     *
     * @param array{label:string,finishedAt:string,state?:string}|null $dry lastDryRun()
     */
    public static function dryRunLine(?array $dry, int $now): string
    {
        return $dry === null ? '' : $dry['label'] . ' · ' . self::lastRunLabel($dry, $now);
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

    /**
     * The Auth cell of the Connections table. A daemon connection has no SSH
     * auth method (its stored authMethod is whatever the hidden select posted).
     *
     * @param array<string,mixed> $conn a merged connection
     */
    public static function authLabel(array $conn): string
    {
        if (strtoupper((string) ($conn['transport'] ?? 'SSH')) === 'DAEMON') {
            return ((string) ($conn['password'] ?? '')) !== '' ? 'Module secret' : 'None';
        }
        $auth = strtoupper((string) ($conn['authMethod'] ?? ''));
        return self::AUTH_LABELS[$auth] ?? $auth;
    }

    /**
     * The job editor's Connection options: every connection, the ones whose
     * transport does not match the job's hidden (never dropped: a dropped
     * selected option would clear connectionId on the next save), the selected
     * mismatched one kept visible and marked. A Local job filters nothing.
     * A record with no transport key predates the daemon transport: SSH.
     * Mirrored by filterConnOptions() in jobs.php.
     *
     * @param array<int|string,mixed> $conns raw credentials connections
     * @return list<array{id:string,label:string,transport:string,hidden:bool,mismatch:bool}>
     */
    public static function connectionOptions(array $conns, string $jobTransport, string $selectedId): array
    {
        $jobTransport = strtoupper(trim($jobTransport));
        $out = [];
        foreach ($conns as $conn) {
            if (!is_array($conn) || (string) ($conn['id'] ?? '') === '') {
                continue;
            }
            $id        = (string) $conn['id'];
            $transport = strtoupper(trim((string) ($conn['transport'] ?? 'SSH'))) === 'DAEMON' ? 'DAEMON' : 'SSH';
            $other     = $jobTransport !== 'LOCAL' && $transport !== $jobTransport;
            $selected  = $id === $selectedId;
            $out[] = [
                'id'        => $id,
                'label'     => (string) ($conn['name'] ?? $id) . ($transport === 'DAEMON' ? ' [rsyncd]' : ''),
                'transport' => $transport,
                'hidden'    => $other && !$selected,
                'mismatch'  => $other && $selected,
            ];
        }
        return $out;
    }

    /**
     * The flags a rsyncOptions block produces, as the preview shows them, or
     * '' for none. Needs Config and Rsync, which every caller has loaded.
     *
     * @param array<string,mixed> $opts
     */
    public static function optionsLine(array $opts, ?string $transport): string
    {
        return implode(' ', self::previewTokens(Rsync::optionTokens(Config::mergeRsyncOptions($opts), $transport)));
    }

    /**
     * Key type + SHA256 fingerprint of every key line in a pinned
     * known_hosts block, in the form ssh-keygen -lf prints the hash
     * (unpadded base64 of the SHA-256 of the key blob). Lines without a
     * decodable key are skipped.
     *
     * @return list<array{type:string,fingerprint:string}>
     */
    public static function hostKeyFingerprints(string $knownHosts): array
    {
        $out = [];
        foreach (preg_split('/\R/', $knownHosts) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $f = preg_split('/\s+/', $line) ?: [];
            for ($i = 0; $i + 1 < count($f); $i++) {
                if (preg_match('/^(?:ssh-[a-z0-9-]+|ecdsa-sha2-[a-z0-9-]+|sk-[a-z0-9@.-]+)$/', $f[$i]) !== 1) {
                    continue;
                }
                $blob = base64_decode($f[$i + 1], true);
                if ($blob !== false && $blob !== '') {
                    $out[] = ['type' => $f[$i], 'fingerprint' => 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=')];
                }
                break;
            }
        }
        return $out;
    }
}

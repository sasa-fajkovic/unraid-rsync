<?php

declare(strict_types=1);

/**
 * Snapshot.php - the parts of snapshot mode that need no live run
 * (docs/design/snapshot-mode.md, PR-F).
 *
 * Pure logic: stamps and name classification (§3), the monotonic, future-name
 * and clock-skew rules, the prune planner (§6), rsync --info=stats2 parsing,
 * the helper's output protocol (§5.3), the forced-off run options (§2.6) and
 * the remote command string (§5.1). Two seams touch the system: helperScript()
 * reads the shipped helper, and runRemote() starts ssh (or $remoteRunner).
 *
 * The exec seam lives here rather than in a SnapshotRemote class because it is
 * one method over remoteCommand(), and the spec names the seam
 * Snapshot::$remoteRunner (the same shape as Rsync::run / Rsync::$runner).
 * Runner::run drives the run sequence (§4) with these pieces.
 */

require_once __DIR__ . '/Job.php';
require_once __DIR__ . '/Ssh.php';
require_once __DIR__ . '/ProcIO.php';

class Snapshot
{
    /** Helper protocol version: the first stdout line is "ur-snapshot <API>". */
    const API = 1;

    /** sha256 of source/scripts/ur-snapshot.sh; SnapshotTest keeps the two in step. */
    const HELPER_SHA256 = 'ac22a4cb8e12ef48f68072ba7f6d55eb9543ec0fdf22293a8d0cad2da1639628';

    const HELPER_MAX_BYTES = 32768;

    /** Largest .ur-snapshot.json the helper lists or commit accepts (decoded). */
    const META_MAX_BYTES = 65536;

    const STDOUT_MAX_BYTES = 262144;
    const STDERR_MAX_BYTES = 65536;

    /** Receiver clock vs host clock (§3). */
    const SKEW_MAX_SEC = 120;

    /** Names this far ahead of the host clock are future-dated (§3, §6). */
    const FUTURE_MAX_SEC = 86400;

    /** runRemote() result for a call killed at its deadline (never a real exit code). */
    const REMOTE_TIMED_OUT = -1;

    /** Seconds between SIGTERM and SIGKILL for a helper call being stopped. */
    const KILL_GRACE_SEC = 2;

    /** Helper call deadlines (§4); delete has none and is stopped by Abort instead. */
    const HELPER_TIMEOUT_SEC = 120;
    const COMMIT_TIMEOUT_SEC = 600;

    /** Free space before a run: avail >= max(FREE_MIN_PCT % of the filesystem, FREE_MIN_KB). [PROPOSAL; tune] */
    const FREE_MIN_PCT = 5;
    const FREE_MIN_KB  = 10485760;

    /**
     * §4.2 anomaly thresholds [PROPOSAL; tune]: a pair below half of the
     * previous snapshot's files (when it had >= 100) or bytes (when >= 1 GiB),
     * or growth above max(3 x the median of the last 7, 5 GiB) once 3 exist.
     */
    const SUSPECT_MIN_FILES    = 100;
    const SUSPECT_MIN_BYTES    = 1073741824;
    const GROWTH_FACTOR        = 3;
    const GROWTH_MIN_KB        = 5242880;
    const GROWTH_WINDOW        = 7;
    const GROWTH_MIN_SAMPLES   = 3;

    /** Helper exit codes (§5.3); anything else is a transport failure. */
    const HELPER_EXITS = [
        10 => 'usage or invalid argument',
        11 => 'snapshot root missing, not a directory, or not canonical',
        12 => 'target marker missing or content mismatch',
        13 => 'marker or root on a different filesystem, or on the filesystem of /',
        14 => 'sentinel missing or names another job',
        15 => 'name exists or not monotonic',
        16 => 'protected',
        17 => 'not root, or a tool is missing',
        18 => 'I/O failure',
    ];

    const STAMP_REGEX  = '/^\d{4}-\d{2}-\d{2}T\d{6}Z$/D';
    const NAME_REGEX   = '/^(\d{4}-\d{2}-\d{2}T\d{6}Z)(\.inprogress|\.deleting|\.rolledback)?$/D';
    const PREFIX_REGEX = '/^\d{4}-\d{2}-\d{2}T\d{6}Z/';
    const STAMP_FORMAT = 'Y-m-d\THis\Z';

    /** Helper operation => number of arguments after the four common ones (probe takes none). */
    const OPS = [
        'probe'   => 0,
        'check'   => 0,
        'list'    => 0,
        'mkstamp' => 1,
        'commit'  => 2,
        'delete'  => 1,
        'hold'    => 1,
        'pin'     => 1,
    ];

    /**
     * Records each operation may print: 'one' = exactly once, 'opt' = at most
     * once, 'many' = any number. Anything else is a protocol violation.
     */
    const RECORDS = [
        'probe'   => ['uid' => 'one', 'now' => 'one', 'ntp' => 'one', 'ionice' => 'one'],
        'check'   => ['df' => 'one'],
        'list'    => [
            'entry' => 'many', 'stampish' => 'many', 'latest' => 'one', 'hold' => 'one',
            'foreign' => 'one', 'sub' => 'many', 'meta' => 'opt', 'df' => 'one',
        ],
        'mkstamp' => [],
        'commit'  => ['df' => 'one'],
        'delete'  => [],
        'hold'    => [],
        'pin'     => [],
    ];

    /** rsync options snapshot mode turns off for the run (§2.6). */
    const FORCED_OFF = ['mkpath', 'delete', 'deleteExcluded', 'hardlinks', 'inplace'];

    /**
     * Clock seam: fn(): DateTimeImmutable|int (an epoch). null = the system clock.
     *
     * @var (\Closure():(DateTimeImmutable|int))|null
     */
    public static ?\Closure $nowProvider = null;

    /**
     * Exec seam: fn(array $argv, ?array $env, int $timeoutSec, ?callable $stopWhen):
     * array{exit:int,stdout:string,stderr:string}. null = proc_open.
     *
     * @var \Closure|null
     */
    public static ?\Closure $remoteRunner = null;

    /** Path of the helper to read instead of the installed one (tests). */
    public static ?string $helperPathOverride = null;

    // --- stamps -------------------------------------------------------------

    public static function now(): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $t   = self::$nowProvider !== null ? (self::$nowProvider)() : new DateTimeImmutable('now', $utc);
        if (is_int($t)) {
            $t = new DateTimeImmutable('@' . $t);
        }
        return $t->setTimezone($utc);
    }

    public static function stamp(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format(self::STAMP_FORMAT);
    }

    /** True for a real calendar time written exactly as a stamp. */
    public static function isStamp(string $s): bool
    {
        if (!preg_match(self::STAMP_REGEX, $s)) {
            return false;
        }
        $t = DateTimeImmutable::createFromFormat('!' . self::STAMP_FORMAT, $s, new DateTimeZone('UTC'));
        return $t !== false && $t->format(self::STAMP_FORMAT) === $s;
    }

    /**
     * The snapshot a run summary names, for display: the UTC stamp, whether it
     * was committed, and a label in the system's timezone. Null when the run
     * took none (a dry run, or a failure before the stamp was created).
     *
     * @param array<string,mixed>|null $summary Runner::readSummary()
     * @return array{stamp:string,committed:bool,label:string}|null
     */
    public static function lastSnapshot(?array $summary): ?array
    {
        $stamp = is_string($summary['snapshot'] ?? null) ? $summary['snapshot'] : '';
        if (!self::isStamp($stamp)) {
            return null;
        }
        $t         = DateTimeImmutable::createFromFormat('!' . self::STAMP_FORMAT, $stamp, new DateTimeZone('UTC'));
        $committed = !empty($summary['committed']);
        return [
            'stamp'     => $stamp,
            'committed' => $committed,
            'label'     => 'last snapshot ' . date('Y-m-d H:i:s', $t === false ? 0 : $t->getTimestamp())
                . ($committed ? ' (committed)' : ' (not committed)'),
        ];
    }

    /**
     * 'complete', 'inprogress', 'deleting', 'rolledback', or 'foreign' for
     * every other name (including an impossible date with a valid shape).
     */
    public static function classify(string $name): string
    {
        if (!preg_match(self::NAME_REGEX, $name, $m) || !self::isStamp($m[1])) {
            return 'foreign';
        }
        return isset($m[2]) ? substr($m[2], 1) : 'complete';
    }

    /**
     * The stamp-shaped start of a name, whatever follows it, or null. Shape
     * only, like the helper's sort: a far-future impossible date still blocks.
     */
    public static function stampPrefix(string $name): ?string
    {
        return preg_match(self::PREFIX_REGEX, $name) ? substr($name, 0, 18) : null;
    }

    /**
     * §3 monotonic rule: null when $newStamp sorts strictly after every
     * stamp-shaped name (any suffix), else the reason.
     *
     * @param array<int,string> $names
     */
    public static function checkMonotonic(string $newStamp, array $names): ?string
    {
        if (!self::isStamp($newStamp)) {
            return "'$newStamp' is not a valid snapshot stamp.";
        }
        $top = '';
        foreach ($names as $name) {
            $p = self::stampPrefix((string) $name);
            if ($p !== null && strcmp($p, $top) > 0) {
                $top = $p;
            }
        }
        if ($top !== '' && strcmp($newStamp, $top) <= 0) {
            return "The new snapshot $newStamp does not sort after the existing $top, so the clock of this "
                . 'server is probably wrong.';
        }
        return null;
    }

    /**
     * Names whose stamp is more than FUTURE_MAX_SEC ahead of $now.
     *
     * @param array<int,string> $names
     * @return array<int,string>
     */
    public static function futureNames(array $names, DateTimeImmutable $now): array
    {
        $limit = self::stamp($now->modify('+' . self::FUTURE_MAX_SEC . ' seconds'));
        $out   = [];
        foreach ($names as $name) {
            $p = self::stampPrefix((string) $name);
            if ($p !== null && strcmp($p, $limit) > 0) {
                $out[] = (string) $name;
            }
        }
        return $out;
    }

    /** null when the receiver's clock is within SKEW_MAX_SEC of the host's, else the reason. */
    public static function checkSkew(int $receiverEpoch, int $hostEpoch): ?string
    {
        $diff = $receiverEpoch - $hostEpoch;
        if (abs($diff) <= self::SKEW_MAX_SEC) {
            return null;
        }
        return "The receiver's clock is " . abs($diff) . ' s ' . ($diff > 0 ? 'ahead of' : 'behind')
            . ' this server (the limit is ' . self::SKEW_MAX_SEC . ' s).';
    }

    // --- prune (§6) ---------------------------------------------------------

    /**
     * Plan one prune pass. Pure: the helper only ever receives the exact names
     * in `delete`.
     *
     * - keep:    candidate => reasons (daily, weekly, monthly, newest, latest,
     *            current, pinned, floor)
     * - delete:  this run's deletions in order: leftover .deleting first, then
     *            snapshots oldest first, at most maxPrunePerRun
     * - backlog: planned deletions carried over to later runs
     * - future:  complete names more than a day ahead (neither kept nor deleted)
     * - held:    .ur-hold exists; nothing is deleted
     * - abort:   a sanity failure; nothing is deleted
     *
     * pruneDryRun is not an input: a dry run logs exactly this plan.
     *
     * @param array<string,mixed> $listing parseHelper(…, 'list') result
     * @param array<string,mixed> $policy  the job's snapshot block
     * @return array{keep:array<string,array<int,string>>,delete:array<int,string>,backlog:int,future:array<int,string>,held:bool,abort:?string}
     */
    public static function planPrune(array $listing, array $policy, DateTimeImmutable $now, string $currentStamp): array
    {
        $plan = ['keep' => [], 'delete' => [], 'backlog' => 0, 'future' => [], 'held' => !empty($listing['hold']), 'abort' => null];
        $abort = static function (string $why) use (&$plan): array {
            $plan['abort']   = $why;
            $plan['delete']  = [];
            $plan['backlog'] = 0;
            return $plan;
        };

        foreach (Job::SNAPSHOT_BOUNDS as $key => [$min, $max]) {
            $v = $policy[$key] ?? null;
            if (!is_int($v) || $v < $min || $v > $max) {
                return $abort("The retention setting $key is invalid.");
            }
        }
        $floorN = $policy['keepDaily'] + $policy['keepWeekly'] + $policy['keepMonthly'];
        if ($floorN < 1) {
            return $abort('The retention settings keep no snapshot at all.');
        }

        $limit      = self::stamp($now->modify('+' . self::FUTURE_MAX_SEC . ' seconds'));
        $candidates = [];
        $leftovers  = [];
        $pins       = [];
        foreach (self::listedDirs($listing) as $name => $keepFlag) {
            $kind = self::classify($name);
            if ($kind === 'foreign' && preg_match(self::NAME_REGEX, $name)) {
                return $abort("'$name' is named like a snapshot but is not a valid time; rename or remove it.");
            }
            if ($kind === 'deleting') {
                $leftovers[] = $name;
            } elseif ($kind === 'complete' && strcmp($name, $limit) > 0) {
                $plan['future'][] = $name;
            } elseif ($kind === 'complete') {
                $candidates[] = $name;
                if ($keepFlag) {
                    $pins[] = $name;
                }
            }
        }
        sort($candidates, SORT_STRING);
        sort($leftovers, SORT_STRING);
        sort($plan['future'], SORT_STRING);

        if (!in_array($currentStamp, $candidates, true)) {
            return $abort("The listing taken after the commit does not contain the new snapshot $currentStamp.");
        }

        $keep = [];
        $newestFirst = array_reverse($candidates);
        $buckets = ['keepDaily' => ['daily', 'Y-m-d'], 'keepWeekly' => ['weekly', 'o-\WW'], 'keepMonthly' => ['monthly', 'Y-m']];
        foreach ($buckets as $key => [$why, $format]) {
            $seen = [];
            foreach ($newestFirst as $name) {
                $bucket = (new DateTimeImmutable(substr($name, 0, 10), new DateTimeZone('UTC')))->format($format);
                if (isset($seen[$bucket])) {
                    continue;
                }
                if (count($seen) >= $policy[$key]) {
                    break;
                }
                $seen[$bucket] = true;
                $keep[$name][] = $why;
            }
        }
        $keep[(string) end($candidates)][] = 'newest';
        $latest = (string) ($listing['latest'] ?? '');
        if (in_array($latest, $candidates, true)) {
            $keep[$latest][] = 'latest';
        }
        $keep[$currentStamp][] = 'current';
        foreach ($pins as $name) {
            $keep[$name][] = 'pinned';
        }

        $delete = array_values(array_filter($candidates, static fn(string $n): bool => !isset($keep[$n])));
        $room   = count($candidates) - min(count($candidates), $floorN);
        while (count($delete) > $room) {
            $keep[(string) array_pop($delete)][] = 'floor';
        }
        ksort($keep, SORT_STRING);
        $plan['keep'] = $keep;

        if ($plan['held']) {
            return $plan;
        }

        $all = array_merge($leftovers, $delete);
        $plan['delete']  = array_slice($all, 0, (int) $policy['maxPrunePerRun']);
        $plan['backlog'] = count($all) - count($plan['delete']);

        $why = self::pruneSanity($plan['delete'], $listing, $currentStamp);
        return $why === null ? $plan : $abort($why);
    }

    /**
     * §6 sanity aborts, checked against the listing independently of how the
     * batch was planned: null when the batch may run, else the reason.
     *
     * @param array<int,string>   $batch
     * @param array<string,mixed> $listing
     */
    public static function pruneSanity(array $batch, array $listing, string $currentStamp): ?string
    {
        $dirs     = self::listedDirs($listing);
        $complete = array_values(array_filter(array_keys($dirs), static fn(string $n): bool => self::classify($n) === 'complete'));
        sort($complete, SORT_STRING);
        $protected = [
            (string) end($complete) => 'the newest complete snapshot',
            (string) ($listing['latest'] ?? '') => 'the target of latest',
            $currentStamp => 'the new snapshot',
        ];

        $snapshots = 0;
        foreach ($batch as $name) {
            $kind = self::classify((string) $name);
            if (!array_key_exists($name, $dirs) || !in_array($kind, ['complete', 'deleting'], true)) {
                return "Refusing to prune: '$name' is not a listed snapshot directory.";
            }
            if ($kind !== 'complete') {
                continue;
            }
            if (isset($protected[$name])) {
                return "Refusing to prune: the batch contains {$protected[$name]} ($name).";
            }
            if ($dirs[$name]) {
                return "Refusing to prune: the batch contains the pinned snapshot $name.";
            }
            $snapshots++;
        }
        if ($snapshots * 2 > count($complete)) {
            return "Refusing to prune: deleting $snapshots of " . count($complete) . ' complete snapshots is more than half.';
        }
        return null;
    }

    /**
     * Listed real directories with a snapshot-shaped name => their .ur-keep flag.
     *
     * @param array<string,mixed> $listing
     * @return array<string,bool>
     */
    private static function listedDirs(array $listing): array
    {
        $out = [];
        foreach ((array) ($listing['entries'] ?? []) as $e) {
            if (is_array($e) && ($e['type'] ?? '') === 'd' && is_string($e['name'] ?? null)) {
                $out[$e['name']] = !empty($e['keep']);
            }
        }
        return $out;
    }

    // --- run checks (§4) ----------------------------------------------------

    /**
     * null when the receiver has room for a run, else the reason.
     *
     * @param array{size:int,used:int,avail:int} $df in KB
     */
    public static function freeSpaceError(array $df): ?string
    {
        $need = max(intdiv($df['size'] * self::FREE_MIN_PCT, 100), self::FREE_MIN_KB);
        if ($df['avail'] >= $need) {
            return null;
        }
        return 'The receiver has ' . $df['avail'] . " KB free; snapshot mode needs at least $need KB ("
            . self::FREE_MIN_PCT . ' % of the filesystem or ' . self::FREE_MIN_KB . ' KB, whichever is larger).';
    }

    /**
     * §4.2: why a run that passed the completion gate is suspect ([] = it is
     * not), and which checks were skipped.
     *
     * @param array<int,array{sub:string,files:?int,bytes:?int,basisMissing:bool}> $pairs
     * @param array<string,mixed>|null $prevMeta     the previous snapshot's .ur-snapshot.json, null = none
     * @param int|null                 $growthKB     df used after - before, null = not measured
     * @param array<int,int>           $pastGrowthKB earlier committed runs' growth, newest first
     * @return array{suspect:array<int,string>,skipped:array<int,string>}
     */
    public static function anomalies(array $pairs, ?array $prevMeta, ?int $growthKB, array $pastGrowthKB): array
    {
        $suspect = [];
        $skipped = [];
        foreach ($pairs as $p) {
            if ($p['basisMissing']) {
                $suspect[] = "{$p['sub']}: rsync reported that the previous snapshot's copy does not exist";
            }
        }
        $subs = $prevMeta['subs'] ?? null;
        if (!is_array($subs)) {
            $skipped[] = 'no baseline (the previous snapshot has no metadata), so the file, size and growth checks were skipped';
            return ['suspect' => $suspect, 'skipped' => $skipped];
        }
        foreach ($pairs as $p) {
            $prev = $subs[$p['sub']] ?? null;
            if (!is_array($prev)) {
                $skipped[] = "{$p['sub']}: no baseline in the previous snapshot";
                continue;
            }
            foreach (['files' => self::SUSPECT_MIN_FILES, 'bytes' => self::SUSPECT_MIN_BYTES] as $key => $min) {
                $was = $prev[$key] ?? null;
                if (!is_int($was) || $was < $min) {
                    continue;
                }
                if ($p[$key] === null) {
                    $skipped[] = "{$p['sub']}: the $key count could not be read from rsync's statistics";
                } elseif ($p[$key] * 2 < $was) {
                    $suspect[] = "{$p['sub']}: $key dropped from $was to {$p[$key]}";
                }
            }
        }
        $window = array_slice($pastGrowthKB, 0, self::GROWTH_WINDOW);
        if ($growthKB === null) {
            $skipped[] = 'growth was not measured';
        } elseif (count($window) < self::GROWTH_MIN_SAMPLES) {
            $skipped[] = 'fewer than ' . self::GROWTH_MIN_SAMPLES . ' earlier growth values, so the growth check was skipped';
        } else {
            sort($window);
            $n      = count($window);
            $median = intdiv($window[intdiv($n - 1, 2)] + $window[intdiv($n, 2)], 2);
            $limit  = max(self::GROWTH_FACTOR * $median, self::GROWTH_MIN_KB);
            if ($growthKB > $limit) {
                $suspect[] = "the filesystem grew by $growthKB KB, more than the limit of $limit KB";
            }
        }
        return ['suspect' => $suspect, 'skipped' => $skipped];
    }

    // --- rsync output -------------------------------------------------------

    /**
     * Regular-file count and total file size from rsync --info=stats2 output
     * (the last block wins). A field that cannot be read is null; this never
     * throws.
     *
     * @return array{regFiles:?int,totalSize:?int}
     */
    public static function parseStats(string $output): array
    {
        $files = null;
        if (preg_match_all('/^Number of files: ([^\r\n]*)/m', $output, $m)) {
            $line = (string) end($m[1]);
            if (preg_match('/\((.*)\)/', $line, $p)) {
                if (preg_match('/(?:^|, )reg: (\d+(?:[.,]\d+)*[KMGTPE]?)(?:,|$)/', $p[1], $r)) {
                    $files = self::parseNumber($r[1]);
                } elseif (!str_contains($p[1], 'reg:')) {
                    $files = 0;
                }
            } elseif (self::parseNumber(trim($line)) === 0) {
                $files = 0;
            }
        }
        $size = null;
        if (preg_match_all('/^Total file size: (\S+) bytes\r?$/m', $output, $m)) {
            $size = self::parseNumber((string) end($m[1]));
        }
        return ['regFiles' => $files, 'totalSize' => $size];
    }

    /**
     * One rsync number: "1234", "1,234" / "1.234" (locale separators) or, with
     * -h, "1.23K" / "1,23K". -h is level 2, which rsync prints in units of 1000
     * (-hh would be 1024; the plugin never emits it).
     */
    public static function parseNumber(string $s): ?int
    {
        if (preg_match('/^(\d+)(?:[.,](\d+))?([KMGTPE])$/D', $s, $m)) {
            $value = (float) ($m[1] . '.' . ($m[2] !== '' ? $m[2] : '0'));
            return (int) round($value * 1000 ** ((int) strpos('KMGTPE', $m[3]) + 1));
        }
        if (preg_match('/^\d{1,3}(?:([.,])\d{3})?(?:\1\d{3})*$/D', $s) || preg_match('/^\d+$/D', $s)) {
            return (int) str_replace([',', '.'], '', $s);
        }
        return null;
    }

    /**
     * Paths rsync reported as "--link-dest arg does not exist: <path>".
     *
     * @return array<int,string>
     */
    public static function linkDestMissing(string $output): array
    {
        preg_match_all('/^--link-dest arg does not exist: ([^\r\n]+)/m', $output, $m);
        return array_values(array_unique($m[1]));
    }

    // --- helper protocol (§5.3) ---------------------------------------------

    /**
     * Parse and re-validate one helper reply. ['ok' => false, 'error' => …]
     * for anything outside the protocol; otherwise ['ok' => true] plus the
     * op's fields (probe: uid, now, ntp, ionice; check/commit: df; list:
     * entries, stampish, latest, hold, foreign, subs, meta, df).
     *
     * @return array<string,mixed>
     */
    public static function parseHelper(string $stdout, string $op): array
    {
        $fail = static fn(string $why): array => ['ok' => false, 'error' => "Helper $op output rejected: $why"];
        if (!isset(self::RECORDS[$op])) {
            return $fail('unknown operation');
        }
        $lines = explode("\n", $stdout);
        if (end($lines) === '') {
            array_pop($lines);
        }
        if (($lines[0] ?? '') !== 'ur-snapshot ' . self::API) {
            return $fail(preg_match('/^ur-snapshot \d+$/D', $lines[0] ?? '') ? 'unknown API version' : 'missing header');
        }
        if (count($lines) < 2 || end($lines) !== 'ok') {
            return $fail('missing trailer');
        }

        $allowed = self::RECORDS[$op];
        $seen    = [];
        $out     = ['ok' => true];
        if ($op === 'list') {
            $out += ['entries' => [], 'stampish' => [], 'subs' => [], 'meta' => null];
        }
        foreach (array_slice($lines, 1, -1) as $line) {
            $f   = explode(' ', $line);
            $key = $f[0];
            if (!isset($allowed[$key])) {
                return $fail('unexpected record "' . self::printable($key) . '"');
            }
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($allowed[$key] !== 'many' && $seen[$key] > 1) {
                return $fail("duplicate record $key");
            }
            $err = self::parseRecord($key, array_slice($f, 1), $out);
            if ($err !== null) {
                return $fail($err);
            }
        }
        foreach ($allowed as $key => $count) {
            if ($count === 'one' && !isset($seen[$key])) {
                return $fail("missing record $key");
            }
        }
        return $out;
    }

    /**
     * Validate one record's fields into $out; null or the reason.
     *
     * @param array<int,string>   $f
     * @param array<string,mixed> $out
     */
    private static function parseRecord(string $key, array $f, array &$out): ?string
    {
        $n    = count($f);
        $uint = static fn(string $s): bool => preg_match('/^\d{1,19}$/D', $s) === 1;
        switch ($key) {
            case 'uid':
            case 'now':
            case 'foreign':
                if ($n !== 1 || !$uint($f[0])) {
                    return "bad $key record";
                }
                $out[$key] = (int) $f[0];
                return null;
            case 'ntp':
                if ($n !== 1 || !in_array($f[0], ['yes', 'no', 'unknown'], true)) {
                    return 'bad ntp record';
                }
                $out['ntp'] = $f[0];
                return null;
            case 'ionice':
                if ($n !== 1 || !in_array($f[0], ['yes', 'no'], true)) {
                    return 'bad ionice record';
                }
                $out['ionice'] = $f[0] === 'yes';
                return null;
            case 'hold':
                if ($n !== 1 || !in_array($f[0], ['0', '1'], true)) {
                    return 'bad hold record';
                }
                $out['hold'] = $f[0] === '1';
                return null;
            case 'df':
                if ($n !== 3 || !$uint($f[0]) || !$uint($f[1]) || !$uint($f[2])) {
                    return 'bad df record';
                }
                $out['df'] = ['size' => (int) $f[0], 'used' => (int) $f[1], 'avail' => (int) $f[2]];
                return null;
            case 'entry':
                if (
                    $n !== 4 || !preg_match(self::NAME_REGEX, $f[0]) || !in_array($f[1], ['d', 'l', 'f', 'o'], true)
                    || !in_array($f[2], ['0', '1'], true) || !in_array($f[3], ['0', '1'], true)
                ) {
                    return 'bad entry record "' . self::printable(implode(' ', $f)) . '"';
                }
                $out['entries'][] = ['name' => $f[0], 'type' => $f[1], 'keep' => $f[2] === '1', 'meta' => $f[3] === '1'];
                return null;
            case 'stampish':
                if ($n !== 1 || !preg_match(self::STAMP_REGEX, $f[0])) {
                    return 'bad stampish record';
                }
                $out['stampish'][] = $f[0];
                return null;
            case 'latest':
                if ($n !== 1 || !(in_array($f[0], ['none', 'invalid'], true) || preg_match(self::STAMP_REGEX, $f[0]))) {
                    return 'bad latest record';
                }
                $out['latest'] = preg_match(self::STAMP_REGEX, $f[0]) ? $f[0] : '';
                return null;
            case 'sub':
                if ($n !== 1 || !preg_match(Job::SUB_REGEX, $f[0])) {
                    return 'bad sub record "' . self::printable(implode(' ', $f)) . '"';
                }
                if (!in_array(strtolower($f[0]), Job::RESERVED_SUBS, true)) {
                    $out['subs'][] = $f[0];
                }
                return null;
            case 'meta':
                if ($n !== 2 || !self::isStamp($f[0]) || !preg_match('#^[A-Za-z0-9+/]+={0,2}$#D', $f[1])) {
                    return 'bad meta record';
                }
                $raw = strlen($f[1]) <= 4 * (int) ceil(self::META_MAX_BYTES / 3) ? base64_decode($f[1], true) : '';
                if ($raw === false) {
                    return 'bad meta record';
                }
                if ($raw === '' || strlen($raw) > self::META_MAX_BYTES) {
                    return 'meta record is empty or larger than ' . self::META_MAX_BYTES . ' bytes';
                }
                $data = json_decode($raw, true, 32);
                $out['meta'] = ['stamp' => $f[0], 'data' => is_array($data) ? $data : null];
                return null;
        }
        return "unexpected record $key";
    }

    private static function printable(string $s): string
    {
        return substr((string) preg_replace('/[^\x20-\x7e]/', '?', $s), 0, 80);
    }

    // --- run options (§2.6) -------------------------------------------------

    /**
     * The effective options with snapshot mode's forced-off flags applied. A
     * copy for the run; the stored job is never changed.
     *
     * @param array<string,mixed> $effectiveOpts Rsync::effectiveOptions()
     * @return array<string,mixed>
     */
    public static function runOptions(array $effectiveOpts): array
    {
        foreach (self::FORCED_OFF as $key) {
            $effectiveOpts[$key] = false;
        }
        $effectiveOpts['maxDelete'] = '';
        return $effectiveOpts;
    }

    // --- the helper and its remote command (§5) -----------------------------

    public static function helperPath(): string
    {
        return self::$helperPathOverride ?? dirname(__DIR__) . '/scripts/ur-snapshot.sh';
    }

    /**
     * The verified helper body: a regular file (not a symlink) of at most
     * HELPER_MAX_BYTES that starts with #!/bin/sh and hashes to HELPER_SHA256.
     * These exact bytes are what remoteCommand() sends.
     *
     * @throws RuntimeException when any check fails (the run must fail closed).
     */
    public static function helperScript(): string
    {
        $path = self::helperPath();
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException("The snapshot helper $path is missing or not a regular file.");
        }
        $body = @file_get_contents($path, false, null, 0, self::HELPER_MAX_BYTES + 1);
        if ($body === false) {
            throw new RuntimeException("The snapshot helper $path cannot be read.");
        }
        if (strlen($body) > self::HELPER_MAX_BYTES) {
            throw new RuntimeException("The snapshot helper $path is larger than " . self::HELPER_MAX_BYTES . ' bytes.');
        }
        if (!str_starts_with($body, "#!/bin/sh\n")) {
            throw new RuntimeException("The snapshot helper $path does not start with #!/bin/sh.");
        }
        if (!hash_equals(self::HELPER_SHA256, hash('sha256', $body))) {
            throw new RuntimeException("The snapshot helper $path has been modified (sha256 mismatch).");
        }
        return $body;
    }

    /**
     * The one command string ssh hands the receiver's login shell:
     * escapeshellarg of every element of
     * [sudo -n] sh -c <script> ur-snapshot <op> <args…>, joined by spaces.
     *
     * @param array<int,string> $args <root> <marker> <markerContent> <jobId> [op args]; none for probe
     * @throws InvalidArgumentException for an argument the helper would refuse
     *         anyway, or one escapeshellarg would not pass through byte-exact.
     */
    public static function remoteCommand(string $script, string $op, array $args, bool $useSudo): string
    {
        $err = self::helperArgsError($op, $args);
        if ($err !== null) {
            throw new InvalidArgumentException($err);
        }
        $argv = array_merge($useSudo ? ['sudo', '-n'] : [], ['sh', '-c', $script, 'ur-snapshot', $op], array_values($args));
        $quoted = [];
        foreach ($argv as $a) {
            $q = escapeshellarg($a);
            // escapeshellarg drops bytes that are invalid in the current
            // multibyte locale, which would silently name a different path.
            if ($q !== "'" . str_replace("'", "'\\''", $a) . "'") {
                throw new InvalidArgumentException('A helper argument is not valid text in the current locale.');
            }
            $quoted[] = $q;
        }
        return implode(' ', $quoted);
    }

    /**
     * The PHP side of the helper's argument checks (§5.1): null or the reason.
     *
     * @param array<int,mixed> $args
     */
    public static function helperArgsError(string $op, array $args): ?string
    {
        if (!isset(self::OPS[$op])) {
            return "Unknown helper operation '" . self::printable($op) . "'.";
        }
        $want = $op === 'probe' ? 0 : 4 + self::OPS[$op];
        if (!array_is_list($args) || count($args) !== $want) {
            return "Helper operation $op takes $want arguments.";
        }
        foreach ($args as $a) {
            if (!is_string($a) || $a === '' || preg_match('/[\x00-\x1f\x7f]/', $a)) {
                return "Helper operation $op got an empty or non-text argument.";
            }
        }
        if ($op === 'probe') {
            return null;
        }
        foreach ([0 => 'snapshot root', 1 => 'target marker'] as $i => $label) {
            if (!self::isSafePath($args[$i])) {
                return "The $label must be a normalised absolute path.";
            }
        }
        $name = $args[4] ?? '';
        if ($op === 'delete' ? self::classify($name) === 'foreign' : ($want > 4 && !self::isStamp($name))) {
            return "'" . self::printable($name) . "' is not a valid snapshot name for $op.";
        }
        if ($op === 'commit') {
            $meta = $args[5];
            if (!preg_match('#^[A-Za-z0-9+/]+={0,2}$#D', $meta) || strlen($meta) > 4 * (int) ceil(self::META_MAX_BYTES / 3)) {
                return 'The snapshot metadata must be base64 of at most ' . self::META_MAX_BYTES . ' bytes.';
            }
        }
        return null;
    }

    /** Absolute, no empty, '.' or '..' segment, no trailing slash, no control characters. */
    public static function isSafePath(string $p): bool
    {
        return preg_match('#^(/[^/\x00-\x1f\x7f]+)+$#D', $p) === 1 && !preg_match('#/\.\.?(/|$)#', $p);
    }

    /**
     * Run one helper operation on the receiver:
     * ssh <the job's materialised ssh argv> -o LogLevel=ERROR -- user@host '<remoteCommand>'.
     * stdout is capped at STDOUT_MAX_BYTES and stderr at STDERR_MAX_BYTES.
     *
     * @param array<string,mixed>    $sshPieces  Ssh::materialize() result (sshArgv, sshEnv)
     * @param array<int,string>      $args       see remoteCommand()
     * @param int                    $timeoutSec 0 = no deadline
     * @param (callable():bool)|null $stopWhen   true = stop now (Abort)
     * @return array{exit:int,stdout:string,stderr:string} exit is REMOTE_TIMED_OUT
     *         at the deadline and 143 when stopped
     * @throws RuntimeException when the helper file fails verification
     * @throws InvalidArgumentException for an invalid argument or ssh piece
     */
    public static function runRemote(array $sshPieces, string $userHost, string $op, array $args, bool $useSudo, int $timeoutSec, ?callable $stopWhen = null): array
    {
        $ssh = $sshPieces['sshArgv'] ?? null;
        if (!is_array($ssh) || $ssh === [] || !array_is_list($ssh) || array_filter($ssh, 'is_string') !== $ssh) {
            throw new InvalidArgumentException('The ssh argv is missing.');
        }
        if (!preg_match('/^[^\s\x00-\x1f\x7f@-][^\s\x00-\x1f\x7f@]*@[^\s\x00-\x1f\x7f@-][^\s\x00-\x1f\x7f@]*$/D', $userHost)) {
            throw new InvalidArgumentException('The ssh destination must be user@host.');
        }
        // ssh takes an IPv6 literal unbracketed; the [] form is for rsync operands.
        $userHost = preg_replace('/@\[([^\]]*)\]$/D', '@$1', $userHost) ?? $userHost;
        $argv = array_merge($ssh, ['-o', 'LogLevel=ERROR', '--', $userHost, self::remoteCommand(self::helperScript(), $op, $args, $useSudo)]);
        $env  = Ssh::childEnv(is_array($sshPieces['sshEnv'] ?? null) ? $sshPieces['sshEnv'] : []);

        if (self::$remoteRunner !== null) {
            $r = (self::$remoteRunner)($argv, $env, $timeoutSec, $stopWhen);
            return [
                'exit'   => (int) ($r['exit'] ?? 255),
                'stdout' => substr((string) ($r['stdout'] ?? ''), 0, self::STDOUT_MAX_BYTES),
                'stderr' => substr((string) ($r['stderr'] ?? ''), 0, self::STDERR_MAX_BYTES),
            ];
        }
        return self::defaultRemoteRun($argv, $env, $timeoutSec, $stopWhen);
    }

    /**
     * @param array<int,string>         $argv
     * @param array<string,string>|null $env
     * @param (callable():bool)|null    $stopWhen
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private static function defaultRemoteRun(array $argv, ?array $env, int $timeoutSec, ?callable $stopWhen): array
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc  = @proc_open($argv, $descriptors, $pipes, null, $env);
        if (!is_resource($proc)) {
            return ['exit' => Ssh::SSH_EXIT_ERROR, 'stdout' => '', 'stderr' => "Failed to start ssh.\n"];
        }

        $buf      = [1 => '', 2 => ''];
        $cap      = [1 => self::STDOUT_MAX_BYTES, 2 => self::STDERR_MAX_BYTES];
        $deadline = $timeoutSec > 0 ? microtime(true) + $timeoutSec : null;
        ProcIO::drainPipes(
            [1 => $pipes[1], 2 => $pipes[2]],
            static function (int $fd, string $chunk) use (&$buf, $cap): void {
                $room = $cap[$fd] - strlen($buf[$fd]);
                if ($room > 0) {
                    $buf[$fd] .= substr($chunk, 0, $room);
                }
            },
            $deadline,
            $stopWhen
        );

        [$status, $kill] = ProcIO::waitFor($proc, $deadline, $stopWhen);
        if ($kill === '') {
            $code = ProcIO::reapExitCode($status, proc_close($proc));
        } else {
            ProcIO::terminate($proc, (float) self::KILL_GRACE_SEC);
            $code = $kill === 'timeout' ? self::REMOTE_TIMED_OUT : 143;
        }
        return ['exit' => $code, 'stdout' => $buf[1], 'stderr' => $buf[2]];
    }
}

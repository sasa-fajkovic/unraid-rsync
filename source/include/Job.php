<?php
/**
 * Job.php - the job model + validation for the Unraid Rsync plugin.
 *
 * This is pure, I/O-free logic so it can be unit-tested without a live Unraid
 * webGui. Two jobs of work live here:
 *
 *   1. Normalisation - take a raw, untrusted job array (e.g. straight off a
 *      $_POST submission) and coerce it into the canonical stored shape:
 *      whitelist-only rsync options, a clean list of source->dest pairs, a
 *      stable id, and known-good enum values where possible.
 *
 *   2. Validation - return a structured list of errors (and non-fatal
 *      warnings) describing exactly what is wrong, so the handler can reject a
 *      save and the UI can show the problems. This includes the PATH GUARDRAILS
 *      that stop a job from ever targeting the boot drive, system dirs, or a
 *      bare array/pool root.
 *
 * Job.php does NOT build any rsync command line - mapping the structured
 * options object to argv tokens is Phase 4 (Rsync.php). Phase 2 only stores the
 * structured object.
 */

require_once __DIR__ . '/Config.php';

class Job
{
    /** Allowed enum values. */
    const TRANSPORTS  = ['SSH', 'LOCAL'];
    const DIRECTIONS  = ['PUSH', 'PULL'];
    const NOTIFY      = ['off', 'success-only', 'failure-only', 'always'];
    const LOG_LEVELS  = ['quiet', 'normal', 'verbose', 'debug'];

    /**
     * Local paths must resolve under this root. Anything outside /mnt is
     * rejected outright (system dirs, the boot flash, etc.).
     */
    const ALLOWED_LOCAL_ROOT = '/mnt';

    /**
     * Bare roots that must never be a source or destination on their own (only
     * a sub-path beneath them is allowed). These are the array/pool roots plus
     * the allowed-root itself. A path equal to any of these - or, for the pool
     * case, a single-segment /mnt/<pool> with no further sub-dir - is rejected.
     */
    const FORBIDDEN_LOCAL_EXACT = [
        '/',
        '/boot',
        '/etc',
        '/usr',
        '/var',
        '/mnt',
        '/mnt/user',
        '/mnt/user0',
    ];

    /**
     * The whitelisted boolean rsync-option keys. Only these (plus the value
     * keys below) are ever persisted; anything else in a submission is dropped.
     */
    const BOOL_OPTION_KEYS = [
        'archive', 'compress', 'humanReadable', 'times', 'perms', 'xattrs',
        'acls', 'symlinks', 'hardlinks', 'sparse', 'numericIds', 'partial',
        'inplace', 'checksum', 'update', 'wholeFile', 'sizeOnly',
        'ignoreExisting', 'delete', 'deleteExcluded',
    ];

    /** Whitelisted scalar value-input keys (stored as strings). */
    const SCALAR_OPTION_KEYS = [
        'maxDelete', 'bwlimit', 'timeout', 'contimeout', 'maxSize', 'minSize',
        'chmod', 'tempDir', 'backupDir', 'compressLevel', 'modifyWindow',
    ];

    /** Whitelisted list value-input keys (stored as lists of non-empty strings). */
    const LIST_OPTION_KEYS = ['excludes', 'includes'];

    /**
     * Normalise a raw job array into the canonical stored shape. Unknown keys
     * are dropped, rsync options are filtered to the whitelist, pairs are
     * cleaned, and a stable id is assigned if missing.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalize(array $raw): array
    {
        $job = Config::defaultJob();

        $job['name']     = isset($raw['name']) ? trim((string) $raw['name']) : '';
        $job['enabled']  = self::toBool($raw['enabled'] ?? true);
        // Only override the default schedule when one was actually supplied; an
        // omitted schedule keeps the sensible default rather than becoming the
        // always-invalid empty string.
        if (isset($raw['schedule'])) {
            $job['schedule'] = trim((string) $raw['schedule']);
        }

        $transport = strtoupper(trim((string) ($raw['transport'] ?? 'SSH')));
        $job['transport'] = in_array($transport, self::TRANSPORTS, true) ? $transport : 'SSH';

        $job['connectionId'] = isset($raw['connectionId']) ? trim((string) $raw['connectionId']) : '';

        $direction = strtoupper(trim((string) ($raw['direction'] ?? 'PUSH')));
        $job['direction'] = in_array($direction, self::DIRECTIONS, true) ? $direction : 'PUSH';
        // Direction only applies to SSH (data flows to/from a remote host). For
        // LOCAL transport both sides are on this box, so persist a canonical
        // PUSH rather than letting a stored PULL contradict the UI.
        if ($job['transport'] === 'LOCAL') {
            $job['direction'] = 'PUSH';
        }

        $job['useGlobalDefaults'] = self::toBool($raw['useGlobalDefaults'] ?? false);

        $logLevel = strtolower(trim((string) ($raw['logLevel'] ?? 'normal')));
        $job['logLevel'] = in_array($logLevel, self::LOG_LEVELS, true) ? $logLevel : 'normal';

        $job['preHook']  = isset($raw['preHook'])  ? (string) $raw['preHook']  : '';
        $job['postHook'] = isset($raw['postHook']) ? (string) $raw['postHook'] : '';

        $notify = strtolower(trim((string) ($raw['notifyMode'] ?? 'failure-only')));
        $job['notifyMode'] = in_array($notify, self::NOTIFY, true) ? $notify : 'failure-only';

        // pairs
        $job['pairs'] = self::normalizePairs($raw['pairs'] ?? []);

        // rsync options (whitelist only)
        $job['rsyncOptions'] = self::normalizeRsyncOptions($raw['rsyncOptions'] ?? []);

        // id last (slug from name if missing)
        $id = isset($raw['id']) ? trim((string) $raw['id']) : '';
        $job['id'] = $id !== '' ? $id : self::generateId($job['name']);

        return $job;
    }

    /**
     * Clean a raw pairs structure into a list of {local,remote} string pairs,
     * dropping any pair where BOTH sides are empty (a blank template row).
     *
     * @param mixed $rawPairs
     * @return array<int,array{local:string,remote:string}>
     */
    public static function normalizePairs($rawPairs): array
    {
        $pairs = [];
        if (!is_array($rawPairs)) {
            return $pairs;
        }
        foreach ($rawPairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            $local  = isset($pair['local'])  ? trim((string) $pair['local'])  : '';
            $remote = isset($pair['remote']) ? trim((string) $pair['remote']) : '';
            if ($local === '' && $remote === '') {
                continue; // skip an entirely-empty template row
            }
            $pairs[] = ['local' => $local, 'remote' => $remote];
        }
        return $pairs;
    }

    /**
     * Filter a raw rsync-options array down to the whitelist, coercing booleans
     * to bool, scalars to trimmed strings, and lists to lists of non-empty
     * trimmed strings. Keys not in the whitelist are dropped entirely - this is
     * what guarantees the stored shape can never carry a non-whitelisted flag.
     *
     * @param mixed $raw
     * @return array<string,mixed>
     */
    public static function normalizeRsyncOptions($raw): array
    {
        $opts = is_array($raw) ? $raw : [];
        $out  = [];

        foreach (self::BOOL_OPTION_KEYS as $key) {
            $out[$key] = self::toBool($opts[$key] ?? false);
        }
        foreach (self::SCALAR_OPTION_KEYS as $key) {
            $out[$key] = isset($opts[$key]) ? trim((string) $opts[$key]) : '';
        }
        foreach (self::LIST_OPTION_KEYS as $key) {
            $list = [];
            if (isset($opts[$key]) && is_array($opts[$key])) {
                foreach ($opts[$key] as $item) {
                    // ignore nested arrays; only scalars become entries
                    if (is_array($item)) {
                        continue;
                    }
                    $val = trim((string) $item);
                    if ($val !== '') {
                        $list[] = $val;
                    }
                }
            }
            $out[$key] = $list;
        }

        return $out;
    }

    /**
     * Validate a NORMALISED job. Returns a structured result:
     *   [ 'valid' => bool, 'errors' => string[], 'warnings' => string[] ]
     *
     * Errors are hard failures (the save must be rejected); warnings are
     * advisory (e.g. --delete with no max-delete cap) and do not block a save.
     *
     * @param array<string,mixed> $job   a job already run through normalize()
     * @return array{valid:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public static function validate(array $job): array
    {
        $errors   = [];
        $warnings = [];

        // name
        if (trim((string) ($job['name'] ?? '')) === '') {
            $errors[] = 'Job name is required.';
        }

        // transport / direction / enums
        if (!in_array($job['transport'] ?? '', self::TRANSPORTS, true)) {
            $errors[] = 'Transport must be SSH or LOCAL.';
        }
        if (!in_array($job['direction'] ?? '', self::DIRECTIONS, true)) {
            $errors[] = 'Direction must be PUSH or PULL.';
        }
        if (!in_array($job['notifyMode'] ?? '', self::NOTIFY, true)) {
            $errors[] = 'Notify mode is invalid.';
        }
        if (!in_array($job['logLevel'] ?? '', self::LOG_LEVELS, true)) {
            $errors[] = 'Log level is invalid.';
        }

        // schedule (5-field cron)
        if (!self::isValidCron((string) ($job['schedule'] ?? ''))) {
            $errors[] = 'Schedule must be a valid 5-field cron expression.';
        }

        // pairs: at least one, each side non-empty, each path guardrail-checked
        $pairs = isset($job['pairs']) && is_array($job['pairs']) ? $job['pairs'] : [];
        if (count($pairs) === 0) {
            $errors[] = 'At least one source -> destination pair is required.';
        }

        $opts        = isset($job['rsyncOptions']) && is_array($job['rsyncOptions']) ? $job['rsyncOptions'] : [];
        $deleteOn    = !empty($opts['delete']) || !empty($opts['deleteExcluded']);
        $maxDelete   = trim((string) ($opts['maxDelete'] ?? ''));
        $transport   = $job['transport'] ?? 'SSH';
        $direction   = $job['direction'] ?? 'PUSH';

        // The `local` field is ALWAYS a path on this Unraid box; the `remote`
        // field is on the other host (SSH) or also on this box (LOCAL). Which
        // side is the destination depends on direction: PUSH writes to remote,
        // PULL writes to local. The destructive --delete check must target the
        // destination side.
        $destIsRemote = ($direction !== 'PULL'); // PUSH (and LOCAL, coerced to PUSH)

        foreach ($pairs as $i => $pair) {
            $n      = $i + 1;
            $local  = trim((string) ($pair['local']  ?? ''));
            $remote = trim((string) ($pair['remote'] ?? ''));

            // `local` field: always a local path on this box -> local guardrails.
            if ($local === '') {
                $errors[] = "Pair #$n: local path is required.";
            } else {
                $localLabel = $destIsRemote ? "Pair #$n source (local)" : "Pair #$n destination (local)";
                foreach (self::checkLocalPath($local, $localLabel) as $e) {
                    $errors[] = $e;
                }
            }

            // `remote` field: local guardrails under LOCAL transport, otherwise
            // a non-root remote sub-path.
            if ($remote === '') {
                $errors[] = "Pair #$n: remote path is required.";
            } else {
                $remoteLabel = $destIsRemote ? "Pair #$n destination (remote)" : "Pair #$n source (remote)";
                if ($transport === 'LOCAL') {
                    foreach (self::checkLocalPath($remote, $remoteLabel) as $e) {
                        $errors[] = $e;
                    }
                } else {
                    foreach (self::checkRemotePath($remote, $remoteLabel) as $e) {
                        $errors[] = $e;
                    }
                }
            }

            // When --delete is on, the DESTINATION must be a specific
            // sub-directory (defence in depth on top of the root checks). The
            // destination is `remote` for PUSH and `local` for PULL.
            if ($deleteOn) {
                $destPath = $destIsRemote ? $remote : $local;
                if ($destPath !== '' && !self::isSpecificSubPath($destPath)) {
                    $errors[] = "Pair #$n: a delete option is enabled, so the destination must be a specific sub-directory, not a root.";
                }
            }
        }

        // --delete safety: warn (do not block) when no max-delete cap is set.
        if ($deleteOn && $maxDelete === '') {
            $warnings[] = 'A delete option is enabled without a "max delete" cap; consider setting one to limit accidental deletions.';
        }

        return [
            'valid'    => count($errors) === 0,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Guardrail check for a LOCAL path (source, or destination under LOCAL
     * transport). Returns a list of error strings (empty if the path is OK).
     *
     * Rules:
     *   - must be absolute
     *   - must resolve (lexically) under /mnt
     *   - must not be exactly a forbidden root (/, /boot, /mnt, /mnt/user, ...)
     *   - must not be a bare pool root (/mnt/<pool> with no further sub-path)
     *
     * @return array<int,string>
     */
    public static function checkLocalPath(string $path, string $label): array
    {
        $errors = [];
        $norm = self::normalizePath($path);

        if ($norm === '' || $norm[0] !== '/') {
            $errors[] = "$label must be an absolute path.";
            return $errors;
        }

        // Exact forbidden roots (compare on the normalised, de-trailing-slashed form).
        if (in_array($norm, self::FORBIDDEN_LOCAL_EXACT, true)) {
            $errors[] = "$label '$path' is a protected system or array root and cannot be used.";
            return $errors;
        }

        // Must live under /mnt (so /etc, /boot, / etc. that aren't in the exact
        // list above are still rejected for being outside the allowed root).
        if ($norm !== self::ALLOWED_LOCAL_ROOT && strpos($norm . '/', self::ALLOWED_LOCAL_ROOT . '/') !== 0) {
            $errors[] = "$label '$path' must be under " . self::ALLOWED_LOCAL_ROOT . '/.';
            return $errors;
        }

        // Bare pool root: /mnt/<single-segment> with nothing beneath it, and it
        // is NOT one of the named share roots already handled above. e.g.
        // /mnt/cache is a pool root with no sub-dir -> reject. /mnt/cache/foo OK.
        $segments = array_values(array_filter(explode('/', $norm), static fn($s) => $s !== ''));
        // $segments[0] === 'mnt'. A path with exactly two segments (mnt + one)
        // is a bare top-level root under /mnt (pool root, user, user0, etc.).
        if (count($segments) <= 2) {
            $errors[] = "$label '$path' is a bare array/pool root; use a specific sub-directory beneath it.";
            return $errors;
        }

        return $errors;
    }

    /**
     * Guardrail check for a REMOTE (SSH) path. It is a path on another host, so
     * we cannot bind it to /mnt, but it must still be an absolute, non-root
     * sub-path (reject "/", and require at least one path segment).
     *
     * @return array<int,string>
     */
    public static function checkRemotePath(string $path, string $label): array
    {
        $errors = [];
        $norm = self::normalizePath($path);

        if ($norm === '' || $norm[0] !== '/') {
            $errors[] = "$label must be an absolute path.";
            return $errors;
        }
        if (!self::isSpecificSubPath($norm)) {
            $errors[] = "$label '$path' must be a specific sub-directory, not the filesystem root.";
        }
        return $errors;
    }

    /**
     * True when the (normalised) path is an absolute path with at least one
     * non-empty segment - i.e. not "/" and not empty. Used both for the remote
     * non-root requirement and the --delete "must be a sub-directory" rule.
     */
    public static function isSpecificSubPath(string $path): bool
    {
        $norm = self::normalizePath($path);
        if ($norm === '' || $norm[0] !== '/') {
            return false;
        }
        $segments = array_values(array_filter(explode('/', $norm), static fn($s) => $s !== ''));
        return count($segments) >= 1;
    }

    /**
     * Lexically normalise a path for guardrail comparison WITHOUT touching the
     * filesystem (paths may not exist yet, and we must not follow symlinks for
     * a security check). Collapses repeated slashes, strips a trailing slash
     * (except for the root "/"), and resolves "." and ".." segments lexically
     * so "/mnt/user/../.." cannot sneak past the root checks.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $isAbsolute = ($path[0] === '/');
        $parts = explode('/', $path);
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if (!empty($stack)) {
                    array_pop($stack);
                }
                // A leading ".." on an absolute path just stays at root.
                continue;
            }
            $stack[] = $part;
        }
        $result = ($isAbsolute ? '/' : '') . implode('/', $stack);
        if ($result === '') {
            $result = $isAbsolute ? '/' : '';
        }
        return $result;
    }

    /**
     * Validate a 5-field cron expression (minute hour day-of-month month
     * day-of-week). Supports the common syntax: *, ranges (a-b), lists (a,b),
     * steps (* / n, a-b/n), and named month/day-of-week tokens (jan..dec,
     * sun..sat). This is intentionally a structural check - it does not need to
     * match vixie-cron exactly, only to reject clearly-malformed input on save.
     */
    public static function isValidCron(string $expr): bool
    {
        $expr = trim($expr);
        if ($expr === '') {
            return false;
        }
        // Normalise internal whitespace to single spaces.
        $fields = preg_split('/\s+/', $expr);
        if (!is_array($fields) || count($fields) !== 5) {
            return false;
        }

        // [min, hour, dom, month, dow] ranges.
        $ranges = [
            [0, 59],
            [0, 23],
            [1, 31],
            [1, 12],
            [0, 7],   // 0 and 7 are both Sunday
        ];
        $monthNames = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
        $dowNames   = ['sun','mon','tue','wed','thu','fri','sat'];

        foreach ($fields as $idx => $field) {
            $allowNames = ($idx === 3) ? $monthNames : (($idx === 4) ? $dowNames : []);
            if (!self::isValidCronField($field, $ranges[$idx][0], $ranges[$idx][1], $allowNames)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate a single cron field against [$min,$max], allowing the listed
     * lowercase names as substitutes for numbers. Handles comma lists, ranges,
     * and step values.
     *
     * @param array<int,string> $names
     */
    private static function isValidCronField(string $field, int $min, int $max, array $names): bool
    {
        if ($field === '') {
            return false;
        }
        // Comma-separated list: every element must be valid.
        foreach (explode(',', $field) as $part) {
            if ($part === '') {
                return false;
            }
            // Optional step: "<range>/<n>". We require n to be a positive
            // integer; the range part is validated below.
            if (strpos($part, '/') !== false) {
                [$rangePart, $stepPart] = explode('/', $part, 2);
                if (!ctype_digit($stepPart) || (int) $stepPart < 1) {
                    return false;
                }
                $part = $rangePart;
            }

            if ($part === '*') {
                // "*" or "*/n" is always fine.
                continue;
            }

            // Range "a-b" or single value "a".
            if (strpos($part, '-') !== false) {
                [$lo, $hi] = explode('-', $part, 2);
                $loVal = self::cronValue($lo, $names);
                $hiVal = self::cronValue($hi, $names);
                if ($loVal === null || $hiVal === null) {
                    return false;
                }
                if ($loVal < $min || $hiVal > $max || $loVal > $hiVal) {
                    return false;
                }
            } else {
                $val = self::cronValue($part, $names);
                if ($val === null || $val < $min || $val > $max) {
                    return false;
                }
                // A bare value with a step (e.g. "5/10") is unusual but harmless.
            }
        }
        return true;
    }

    /**
     * Resolve a single cron token to an int: either a numeric string or a
     * recognised lowercase name. Returns null if unrecognised.
     *
     * @param array<int,string> $names
     */
    private static function cronValue(string $token, array $names): ?int
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        if (ctype_digit($token)) {
            return (int) $token;
        }
        if (!empty($names)) {
            $idx = array_search(strtolower($token), $names, true);
            if ($idx !== false) {
                // month names map jan=1..dec=12; dow names map sun=0..sat=6.
                return (count($names) === 12) ? ($idx + 1) : $idx;
            }
        }
        return null;
    }

    /**
     * Generate a stable, slugified job id from a name (prefixed "j-"). Falls
     * back to a random suffix when the name yields nothing usable, so two
     * unnamed jobs never collide.
     */
    public static function generateId(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            // Random, filesystem-safe suffix.
            $slug = bin2hex(random_bytes(4));
        }
        return 'j-' . $slug;
    }

    /**
     * Loose boolean coercion for form/JSON input. Treats "1", "true", "on",
     * "yes" (any case) and real true/numeric-1 as true; everything else false.
     *
     * @param mixed $value
     */
    public static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }
        return false;
    }
}

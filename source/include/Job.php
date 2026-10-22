<?php

declare(strict_types=1);

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
 * options object to argv tokens is Rsync.php's job. Job.php only normalises,
 * validates and stores the structured object.
 */

require_once __DIR__ . '/Config.php';
// Credentials::findConnection is used by validate() to confirm an SSH or rsync
// daemon job's referenced connection exists, and that its transport matches the
// job's, when a credentials structure is supplied.
require_once __DIR__ . '/Credentials.php';

class Job
{
    /**
     * Allowed enum values. DAEMON speaks the rsync daemon (rsyncd) wire protocol
     * on a TCP port; its pairs' `remote` side is a MODULE REFERENCE
     * ("rsync_bkp/photos"), not an absolute path - see checkDaemonModule().
     */
    const TRANSPORTS  = ['SSH', 'LOCAL', 'DAEMON'];
    const DIRECTIONS  = ['PUSH', 'PULL'];
    const NOTIFY      = ['off', 'success-only', 'failure-only', 'always'];
    /** Least -> most output. The UI select renders this order verbatim. */
    const LOG_LEVELS  = ['quiet', 'summary', 'normal', 'verbose', 'debug'];

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
        'recursive',
        'archive', 'compress', 'humanReadable', 'times', 'omitDirTimes',
        'omitLinkTimes', 'perms', 'owner', 'group', 'devices', 'xattrs',
        'acls', 'symlinks', 'hardlinks', 'sparse', 'numericIds', 'partial',
        'inplace', 'checksum', 'update', 'wholeFile', 'sizeOnly',
        'ignoreExisting', 'delete', 'deleteExcluded', 'mkpath',
    ];

    /** Whitelisted scalar value-input keys (stored as strings). */
    const SCALAR_OPTION_KEYS = [
        'maxDelete', 'bwlimit', 'timeout', 'contimeout', 'maxSize', 'minSize',
        'chmod', 'tempDir', 'backupDir', 'compressLevel', 'modifyWindow',
        'remoteRsyncPath', 'protocol',
    ];

    /**
     * The whitelisted filter rule types. Aliased from Config (which owns the
     * normaliser, because Config cannot depend on Job - the dependency runs the
     * other way) so callers reading the whitelist have one place to look.
     */
    const FILTER_TYPES = Config::FILTER_TYPES;

    /** Scalar option keys whose value must be a non-negative whole number. */
    const INTEGER_SCALAR_KEYS = [
        'maxDelete', 'timeout', 'contimeout', 'compressLevel', 'modifyWindow', 'protocol',
    ];

    /** The --protocol range rsync 3.x accepts (MIN_PROTOCOL_VERSION..PROTOCOL_VERSION). */
    const PROTOCOL_MIN = 20;
    const PROTOCOL_MAX = 32;

    /**
     * Scalar option keys whose value is an rsync SIZE: a number with an optional
     * decimal part and an optional unit suffix (K/M/G/T/P, optionally i and/or B),
     * e.g. "100", "1.5m", "500K", "2GiB".
     */
    const SIZE_SCALAR_KEYS = ['bwlimit', 'maxSize', 'minSize'];

    /**
     * The one sentence every daemon-module message ends with. Kept as a const so
     * the error in checkRemotePath() and the advisory in daemonModuleNote()
     * cannot drift apart.
     *
     * It names the JOB, not the plugin: the old lead "This plugin transfers over
     * SSH" became false the moment DAEMON transport existed, and both messages
     * only ever fire on an SSH job's remote path. The final sentence is the way
     * out for the user who really did paste a module name.
     */
    const DAEMON_MODULE_HINT = 'This job transfers over SSH, so use the '
        . 'absolute filesystem path the module points at on the remote host '
        . '(for example /volume1/Backup/data). To address the module by name '
        . 'instead, set the job Transport to "rsync daemon (rsyncd)".';

    /**
     * A snapshot pair's directory name inside a stamp. No dots, so "." and ".."
     * cannot match; RESERVED_SUBS are compared case-insensitively.
     */
    const SUB_REGEX     = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D';
    const RESERVED_SUBS = ['latest'];

    /** Snapshot integer settings => [min, max]. */
    const SNAPSHOT_BOUNDS = [
        'keepDaily'      => [0, 366],
        'keepWeekly'     => [0, 260],
        'keepMonthly'    => [0, 120],
        'maxPrunePerRun' => [1, 20],
    ];

    /** Scalar key -> rsync flag, for human-readable validation messages. */
    const SCALAR_FLAG_LABELS = [
        'maxDelete'       => '--max-delete',
        'timeout'         => '--timeout',
        'contimeout'      => '--contimeout',
        'compressLevel'   => '--compress-level',
        'modifyWindow'    => '--modify-window',
        'protocol'        => '--protocol',
        'bwlimit'         => '--bwlimit',
        'maxSize'         => '--max-size',
        'minSize'         => '--min-size',
        'remoteRsyncPath' => '--rsync-path',
    ];

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
        // A manual-only job is never added to cron and has no schedule
        // requirement; it runs only via the Run / Dry-run buttons.
        $job['manualOnly'] = self::toBool($raw['manualOnly'] ?? false);
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
        // Direction only applies to a remote transport (SSH, DAEMON) where data
        // flows to/from another host. For LOCAL transport both sides are on this
        // box, so persist a canonical PUSH rather than letting a stored PULL
        // contradict the UI. DAEMON deliberately keeps PULL: pulling from a NAS
        // module is the primary reported use case.
        if ($job['transport'] === 'LOCAL') {
            $job['direction'] = 'PUSH';
        }

        $job['useGlobalDefaults'] = self::toBool($raw['useGlobalDefaults'] ?? false);

        $logLevel = strtolower(trim((string) ($raw['logLevel'] ?? 'normal')));
        $job['logLevel'] = in_array($logLevel, self::LOG_LEVELS, true) ? $logLevel : 'normal';

        $job['preHook']  = isset($raw['preHook'])  ? (string) $raw['preHook']  : '';
        $job['postHook'] = isset($raw['postHook']) ? (string) $raw['postHook'] : '';

        // Optional and absent unless set, so a job that never used them saves
        // exactly as before (see Config::OPTIONAL_JOB_KEYS).
        foreach (Config::OPTIONAL_JOB_KEYS as $key) {
            $v = (isset($raw[$key]) && is_scalar($raw[$key])) ? trim((string) $raw[$key]) : '';
            if ($v !== '') {
                $job[$key] = $v;
            }
        }

        // Form and JSON post the same keys; only the two bools need coercing.
        if (isset($raw['snapshot']) && is_array($raw['snapshot'])) {
            $s = $raw['snapshot'];
            $s['enabled']     = self::toBool($s['enabled'] ?? false);
            $s['pruneDryRun'] = self::toBool($s['pruneDryRun'] ?? true);
            $snapshot = Config::normalizeSnapshot($s);
            if ($snapshot !== null) {
                $job['snapshot'] = $snapshot;
            }
        }

        $notify = strtolower(trim((string) ($raw['notifyMode'] ?? 'failure-only')));
        $job['notifyMode'] = in_array($notify, self::NOTIFY, true) ? $notify : 'failure-only';

        // pairs
        $job['pairs'] = self::normalizePairs($raw['pairs'] ?? []);

        // rsync options (whitelist only)
        $job['rsyncOptions'] = self::normalizeRsyncOptions($raw['rsyncOptions'] ?? []);

        // id last (slug from name if missing). A supplied id is kept only when
        // it is a safe single-token slug and NOT a pure-dots traversal segment
        // ("." / "..") - matching ur_safe_job_id - so a crafted config.json can
        // never persist an id that the filesystem helpers would have to defang.
        // Anything else is regenerated from the name. Control bytes are rejected
        // BEFORE trim() (which strips NUL/whitespace) so a tampered "j-ok\0" /
        // "j-ok\n" can't be laundered into a valid id - matching the gate's
        // ordering exactly.
        $rawId   = isset($raw['id']) ? (string) $raw['id'] : '';
        $id      = trim($rawId);
        $idValid = !preg_match('/[\x00-\x1f\x7f]/', $rawId)
            && $id !== ''
            && strlen($id) <= 128
            && preg_match('/^[A-Za-z0-9._-]+$/D', $id)
            && !preg_match('/^\.+$/', $id);
        $job['id'] = $idValid ? $id : self::generateId($job['name']);

        return $job;
    }

    /**
     * Clean a raw pairs structure into a list of {local,remote} string pairs
     * (plus a snapshot `sub`/`seed` when given), dropping a blank template row.
     *
     * @param mixed $rawPairs
     * @return array<int,array{local:string,remote:string,sub?:string,seed?:true}>
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
            $sub    = isset($pair['sub']) && is_scalar($pair['sub']) ? trim((string) $pair['sub']) : '';
            if ($local === '' && $remote === '' && $sub === '') {
                continue; // skip an entirely-empty template row
            }
            $p = ['local' => $local, 'remote' => $remote];
            if ($sub !== '') {
                $p['sub'] = $sub;
            }
            if (self::toBool($pair['seed'] ?? false)) {
                $p['seed'] = true;
            }
            $pairs[] = $p;
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
        // The ordered filter list. Config owns the normaliser because it also
        // has to run on the config-LOAD path (mergeRsyncOptions), and it accepts
        // both the stored shape and the parallel-array shape the form posts.
        $out['filters'] = Config::normalizeFilters($opts['filters'] ?? []);
        $out['statsMode'] = Config::normalizeStatsMode($opts['statsMode'] ?? null);

        return $out;
    }

    /**
     * Validate a NORMALISED job. Returns a structured result:
     *   [ 'valid' => bool, 'errors' => string[], 'warnings' => string[] ]
     *
     * Errors are hard failures (the save must be rejected); warnings are
     * advisory (e.g. --delete with no max-delete cap) and do not block a save.
     *
     * @param array<string,mixed>      $job   a job already run through normalize()
     * @param array<string,mixed>|null $creds an optional loaded credentials
     *        structure. When supplied, an SSH or rsync daemon job's connectionId
     *        is additionally checked to reference a connection that actually
     *        exists and whose transport matches the job's; when null, only the
     *        cheap "non-empty connectionId" rule is enforced. The server is the
     *        source of truth, so the handler passes $creds here.
     * @param array<string,mixed>|null $global the config's `global` block. When
     *        supplied, the options checked are the EFFECTIVE ones
     *        (Rsync::effectiveOptions), so a job on "use global config" is judged
     *        by the options it will really run with. null = the job's own.
     * @return array{valid:bool,errors:array<int,string>,warnings:array<int,string>}
     */
    public static function validate(array $job, ?array $creds = null, ?array $global = null): array
    {
        $errors   = [];
        $warnings = [];

        // name
        if (trim((string) ($job['name'] ?? '')) === '') {
            $errors[] = 'Job name is required.';
        }

        // transport / direction / enums
        if (!in_array($job['transport'] ?? '', self::TRANSPORTS, true)) {
            $errors[] = 'Transport must be SSH, LOCAL or DAEMON.';
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

        // schedule (5-field cron). A manual-only job is never scheduled, so its
        // schedule is irrelevant and not validated.
        if (empty($job['manualOnly']) && !self::isValidCron((string) ($job['schedule'] ?? ''))) {
            $errors[] = 'Schedule must be a valid 5-field cron expression.';
        }

        // Resolve transport + direction EXACTLY as the RUN-TIME side does
        // (Runner::run and Runner::guardrailErrors: strtoupper + trim, defaulting
        // to SSH/PUSH) so the two guards can never disagree about the same stored
        // bytes. config.json is hand-editable on /boot, and a `"transport":
        // "daemon"` there used to take the DAEMON arm at run time while this
        // method treated it as an unknown transport - inverting the role labels
        // and picking a different --delete destination side than the Runner. The
        // enum checks above still report such a value as invalid; this only
        // decides WHICH rules are applied to it.
        $transport = strtoupper(trim((string) ($job['transport'] ?? 'SSH')));
        $direction = strtoupper(trim((string) ($job['direction'] ?? 'PUSH')));

        // connection: an SSH or DAEMON job MUST select a Connection (it is the
        // host/port/auth the transport is built from; without it there is nowhere
        // to rsync to). LOCAL transport never uses a connection, so connectionId
        // is optional there. When the caller passes a loaded credentials structure
        // we ALSO confirm the referenced connection still exists (cheap in-memory
        // lookup, mirrors Credentials::validateConnection's keyId existence check)
        // and that its transport agrees with the job's - an SSH connection cannot
        // build a daemon operand and vice versa, and the Runner re-checks that
        // authoritatively at run time.
        if (in_array($transport, ['SSH', 'DAEMON'], true)) {
            $connectionId = trim((string) ($job['connectionId'] ?? ''));
            if ($connectionId === '') {
                $errors[] = ($transport === 'DAEMON')
                    ? 'An rsync daemon job must select a Connection.'
                    : 'An SSH job must select a Connection.';
            } elseif (is_array($creds)) {
                $conn = Credentials::findConnection($creds, $connectionId);
                if ($conn === null) {
                    $errors[] = 'The selected Connection does not exist.';
                } else {
                    // findConnection returns the RAW record, not a merged one, so
                    // a pre-daemon connection has no transport key at all and the
                    // ?? 'SSH' is mandatory. Without it EVERY existing SSH job
                    // would report a mismatch on the first save after upgrade.
                    $connTransport = strtoupper(trim((string) ($conn['transport'] ?? 'SSH')));
                    if ($connTransport !== $transport) {
                        $errors[] = ($transport === 'DAEMON')
                            ? 'This job uses rsync daemon transport, but the selected Connection uses SSH '
                                . 'transport. Pick a Connection whose Transport is "rsync daemon (rsyncd)".'
                            : 'This job uses SSH transport, but the selected Connection uses rsync daemon '
                                . '(rsyncd) transport. Pick a Connection whose Transport is "SSH".';
                    }
                }
            }
        }

        // pairs: at least one, each side non-empty, each path guardrail-checked
        $pairs = isset($job['pairs']) && is_array($job['pairs']) ? $job['pairs'] : [];
        if (count($pairs) === 0) {
            $errors[] = 'At least one source -> destination pair is required.';
        }

        if ($global !== null) {
            if (!class_exists('Rsync', false)) {
                require_once __DIR__ . '/Rsync.php';
            }
            $opts = Rsync::effectiveOptions($job, $global);
        } else {
            $opts = isset($job['rsyncOptions']) && is_array($job['rsyncOptions']) ? $job['rsyncOptions'] : [];
        }
        $deleteOn    = !empty($opts['delete']) || !empty($opts['deleteExcluded']);
        $maxDelete   = trim((string) ($opts['maxDelete'] ?? ''));
        // Snapshot mode writes to <root>/<stamp>/<sub>, never to `remote`, and
        // forces delete off, so snapshotErrors() replaces the remote-path and
        // --delete destination rules.
        $snapshotOn  = Config::normalizeSnapshot($job['snapshot'] ?? null) !== null;

        // The `local` field is ALWAYS a path on this Unraid box; the `remote`
        // field is on the other host (SSH, DAEMON) or also on this box (LOCAL).
        // Which side is the destination depends on direction: PUSH writes to
        // remote, PULL writes to local. The destructive --delete check must
        // target the destination side.
        //
        // Written as an explicit three-way test rather than the old bare
        // `$direction !== 'PULL'` so it MIRRORS Runner::guardrailErrors exactly.
        // For an unknown hand-edited transport the Runner resolves the pair with
        // dest = `remote` unconditionally, so this must too, or the save-time and
        // run-time --delete guards would disagree about the same job. LOCAL is
        // unaffected: normalize() coerces it to PUSH, so both forms give true.
        $destIsRemote = in_array($transport, ['SSH', 'DAEMON'], true) ? ($direction !== 'PULL') : true;

        // The `remote` field is on another host only for SSH and DAEMON
        // transport; under LOCAL transport it is a second path on this same box,
        // and under DAEMON it is a module reference rather than a path. Qualify
        // labels accordingly so a LOCAL job's errors don't say "(remote)" and a
        // daemon job's point at the right kind of value.
        $remoteQualifier = ($transport === 'LOCAL')
            ? 'local'
            : (($transport === 'DAEMON') ? 'module' : 'remote');

        foreach ($pairs as $i => $pair) {
            $n      = $i + 1;
            $local  = trim((string) ($pair['local']  ?? ''));
            $remote = trim((string) ($pair['remote'] ?? ''));

            $localRole  = $destIsRemote ? 'source' : 'destination';
            $remoteRole = $destIsRemote ? 'destination' : 'source';

            // `local` field: always a local path on this box -> local guardrails.
            if ($local === '') {
                $errors[] = "Pair #$n: $localRole (local) path is required.";
            } else {
                foreach (self::pairLocalErrors($job, $transport, $local, "Pair #$n $localRole (local)") as $e) {
                    $errors[] = $e;
                }
            }

            // `remote` field: local guardrails under LOCAL transport, otherwise
            // a non-root remote sub-path.
            $remoteLabel = "Pair #$n $remoteRole ($remoteQualifier)";
            if ($snapshotOn) {
                continue;
            }
            if ($remote === '') {
                $errors[] = "Pair #$n: $remoteRole ($remoteQualifier) path is required.";
            } else {
                if ($transport === 'LOCAL') {
                    foreach (self::checkLocalPath($remote, $remoteLabel) as $e) {
                        $errors[] = $e;
                    }
                } elseif ($transport === 'DAEMON') {
                    // A module reference, not a path. No daemonModuleNote here:
                    // that advisory exists to catch a module name typed into an
                    // SSH job, which is exactly what this transport wants.
                    foreach (self::checkDaemonModule($remote, $remoteLabel) as $e) {
                        $errors[] = $e;
                    }
                } else {
                    foreach (self::checkRemotePath($remote, $remoteLabel) as $e) {
                        $errors[] = $e;
                    }
                    // Advisory only, and only for SSH: under LOCAL transport the
                    // `remote` field is a second path on this box and already had
                    // to clear the /mnt guardrail above.
                    $note = self::daemonModuleNote($remote);
                    if ($note !== '') {
                        $warnings[] = "$remoteLabel path '$remote' $note";
                    }
                }
            }

            // When --delete is on, the DESTINATION must be a specific
            // sub-directory (defence in depth on top of the root checks). The
            // destination is `remote` for PUSH and `local` for PULL.
            if ($deleteOn) {
                $e = self::deleteDestError($transport, $destIsRemote, $destIsRemote ? $remote : $local);
                if ($e !== '') {
                    $errors[] = "Pair #$n: $e";
                }
            }
        }

        foreach (self::pairOverlapErrors($job, $opts) as $e) {
            $errors[] = $e;
        }

        foreach (self::markerErrors($job, $transport, $direction) as $e) {
            $errors[] = $e;
        }

        foreach (self::optionErrors($opts, $transport, $direction) as $e) {
            $errors[] = $e;
        }

        if ($snapshotOn) {
            $errors   = array_merge($errors, self::snapshotErrors($job, $opts, $transport, $direction));
            $warnings = array_merge($warnings, self::snapshotOptionWarnings($opts));
        }

        // --delete safety: warn (do not block) when no max-delete cap is set.
        if ($deleteOn && $maxDelete === '' && !$snapshotOn) {
            $warnings[] = 'A delete option is enabled without a "max delete" cap; consider setting one to limit accidental deletions.';
        }

        // rsync's main.c:1558 exits 1 (RERR_SYNTAX) for --contimeout on ANY
        // non-daemon connection - remote-shell AND local; only the daemon socket
        // path returns before that check. Rsync::buildArgv therefore drops the
        // option for SSH and LOCAL rather than emit an argv that can only fail,
        // so warn that the stored value is inert. Never an ERROR: that would
        // reject an existing save that has carried the value for months.
        if ($transport !== 'DAEMON' && trim((string) ($opts['contimeout'] ?? '')) !== '') {
            $warnings[] = 'The --contimeout option only applies to rsync daemon (rsyncd) transport; '
                . 'rsync rejects it outright on SSH and Local transfers, so it is not sent for this job.';
        }

        return [
            'valid'    => count($errors) === 0,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Snapshot-mode rules beyond the options: SSH + PUSH only, the root, the
     * mandatory target marker, retention bounds and the pair `sub` names, plus
     * snapshotOptionErrors(). [] when the job's snapshot block is off.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts the job's EFFECTIVE rsync options
     * @return array<int,string>
     */
    public static function snapshotErrors(array $job, array $opts, string $transport, string $direction): array
    {
        $snap = Config::normalizeSnapshot($job['snapshot'] ?? null);
        if ($snap === null) {
            return [];
        }
        $errors = [];
        if ($transport !== 'SSH' || $direction !== 'PUSH') {
            $errors[] = 'Snapshot mode needs SSH transport and the PUSH direction.';
        }

        $root   = $snap['root'];
        $label  = 'Snapshot root';
        $rootOk = false;
        $raw    = $root === '' ? [] : self::rawPathErrors($root, $label);
        if ($root === '') {
            $errors[] = "$label is required.";
        } elseif ($raw !== []) {
            $errors = array_merge($errors, $raw);
        } elseif ($root[0] !== '/') {
            $errors[] = "$label must be an absolute path.";
        } elseif (!self::isDeepRemoteDeleteTarget($root)) {
            $errors[] = "$label '$root' must be at least two directories deep (for example /srv/snapshots) "
                . 'and not a bare /mnt/<disk> root.';
        } elseif ($root !== self::normalizePath($root)) {
            $errors[] = "$label '$root' must be written as '" . self::normalizePath($root) . "'.";
        } else {
            $rootOk = true;
        }

        $marker  = trim((string) ($job['targetMarker'] ?? ''));
        $content = trim((string) ($job['targetMarkerContent'] ?? ''));
        if ($marker === '' || $content === '') {
            $errors[] = 'Snapshot mode needs a target marker and its expected content (a UUID is recommended).';
        } elseif ($rootOk) {
            $markerDir = dirname(self::normalizePath($marker));
            if (!self::atOrInside($root, $markerDir)) {
                $errors[] = "$label '$root' must be the target marker's directory ('$markerDir') or lie inside it.";
            }
        }

        $inRange = true;
        foreach (self::SNAPSHOT_BOUNDS as $key => [$min, $max]) {
            if ($snap[$key] < $min || $snap[$key] > $max) {
                $inRange  = false;
                $errors[] = "Snapshot setting $key must be a whole number from $min to $max.";
            }
        }
        if ($inRange && $snap['keepDaily'] + $snap['keepWeekly'] + $snap['keepMonthly'] < 1) {
            $errors[] = 'Snapshot retention must keep at least one snapshot (keepDaily + keepWeekly + keepMonthly).';
        }

        $seen = [];
        foreach ((is_array($job['pairs'] ?? null) ? $job['pairs'] : []) as $i => $pair) {
            $n      = (int) $i + 1;
            $pair   = is_array($pair) ? $pair : [];
            $remote = trim((string) ($pair['remote'] ?? ''));
            $sub    = trim((string) ($pair['sub'] ?? ''));
            if ($remote !== '') {
                $errors[] = "Pair #$n: in snapshot mode the destination is <root>/<stamp>/<sub>; leave the remote path empty.";
            }
            if ($sub === '') {
                $errors[] = "Pair #$n: a snapshot sub-directory name is required.";
                continue;
            }
            if (!preg_match(self::SUB_REGEX, $sub)) {
                $errors[] = "Pair #$n: snapshot sub-directory name '$sub' must be 1 to 64 letters, digits, '_' or '-', "
                    . 'starting with a letter or digit.';
                continue;
            }
            $lower = strtolower($sub);
            if (in_array($lower, self::RESERVED_SUBS, true)) {
                $errors[] = "Pair #$n: snapshot sub-directory name '$sub' is reserved.";
            } elseif (isset($seen[$lower])) {
                $errors[] = "Pair #$n: snapshot sub-directory name '$sub' is already used by pair #{$seen[$lower]} "
                    . '(names are compared case-insensitively).';
            } else {
                $seen[$lower] = $n;
            }
        }

        return array_merge($errors, self::snapshotOptionErrors($opts));
    }

    /**
     * EFFECTIVE options snapshot mode rejects. Without -t, --link-dest never
     * matches, so every run would be a full copy.
     *
     * @param array<string,mixed> $opts
     * @return array<int,string>
     */
    public static function snapshotOptionErrors(array $opts): array
    {
        $rejected = [
            'inplace'        => 'In-place (--inplace) rewrites files that older snapshots share',
            'checksum'       => '--checksum reads every file of the previous snapshot in full',
            'sizeOnly'       => '--size-only can keep stale content',
            'ignoreExisting' => '--ignore-existing can keep stale content',
            'update'         => '--update can keep stale content',
        ];
        $errors = [];
        foreach ($rejected as $key => $why) {
            if (!empty($opts[$key])) {
                $errors[] = "Snapshot mode cannot be used with this option: $why.";
            }
        }
        if (trim((string) ($opts['backupDir'] ?? '')) !== '') {
            $errors[] = 'Snapshot mode cannot be used with --backup-dir: every snapshot is a fresh directory.';
        }
        if (empty($opts['times'])) {
            $errors[] = 'Snapshot mode needs Preserve times (-t): without it unchanged files are never linked '
                . 'to the previous snapshot, so every run is a full copy.';
        }
        return $errors;
    }

    /**
     * EFFECTIVE options snapshot mode turns off for the run. Stored values are
     * left alone, so these are warnings.
     *
     * @param array<string,mixed> $opts
     * @return array<int,string>
     */
    public static function snapshotOptionWarnings(array $opts): array
    {
        $forced = [
            'mkpath'         => '--mkpath',
            'delete'         => '--delete',
            'deleteExcluded' => '--delete-excluded',
            'hardlinks'      => 'Preserve hard links (-H)',
        ];
        $warnings = [];
        foreach ($forced as $key => $label) {
            if (!empty($opts[$key])) {
                $warnings[] = "$label is forced off in snapshot mode.";
            }
        }
        if (trim((string) ($opts['maxDelete'] ?? '')) !== '') {
            $warnings[] = '--max-delete is forced off in snapshot mode.';
        }
        return $warnings;
    }

    /**
     * Errors in a job's (EFFECTIVE) rsync options. Shared by validate() at save
     * time and Runner's pre-flight at run time, so the two cannot disagree.
     *
     * --temp-dir / --backup-dir live on the RECEIVER's filesystem. For a local
     * receiver (LOCAL transport, or a PULL whose destination is the local side)
     * they must clear the SAME /mnt guardrail as any local path - otherwise a job
     * could quietly stage into or back up onto /boot, /etc, etc. For a PUSH the
     * receiver is remote, so the weaker absolute-non-root check applies (same as
     * a remote pair path) - or, on a daemon receiver, the module-reference check,
     * because rsync resolves both flags relative to the module root there.
     *
     * @param array<string,mixed> $opts
     * @return array<int,string>
     */
    public static function optionErrors(array $opts, string $transport, string $direction): array
    {
        $errors = [];
        $receiverIsLocal = ($transport === 'LOCAL') || ($direction === 'PULL');
        foreach (['tempDir' => '--temp-dir', 'backupDir' => '--backup-dir'] as $key => $flag) {
            $p = trim((string) ($opts[$key] ?? ''));
            if ($p === '') {
                continue;
            }
            $label  = "Option $flag";
            $checks = self::rawPathErrors($p, $label);
            if ($checks === []) {
                if ($receiverIsLocal) {
                    $checks = self::checkLocalPath($p, $label);
                } elseif ($transport === 'DAEMON') {
                    $checks = self::checkDaemonModule($p, $label);
                } else {
                    // $pairPath = false: these fields are never a module name, so
                    // the daemon-shaped discriminators inside checkRemotePath are
                    // nonsense here - a tempDir of "tmp" was being reported as
                    // "looks like an rsync daemon module name, not a path" instead
                    // of the plain "must be an absolute path".
                    $checks = self::checkRemotePath($p, $label, false);
                }
            }
            foreach ($checks as $e) {
                $errors[] = $e;
            }
        }

        foreach (self::validateRsyncOptions($opts) as $e) {
            $errors[] = $e;
        }

        if ($transport !== 'LOCAL' && self::protocolTooLowForAclsXattrs($opts)) {
            $errors[] = 'Preserve ACLs (-A) and Preserve extended attributes (-X) need rsync protocol 30 or '
                . 'higher over SSH or rsync daemon; raise the --protocol value or turn those options off.';
        }

        return $errors;
    }

    /**
     * --temp-dir / --backup-dir checks that hold on EVERY receiver, for the
     * global defaults (no transport to pick a stricter check by): no control
     * characters, no ".." segment, and not the filesystem root.
     *
     * @param array<string,mixed> $opts
     * @return array<int,string>
     */
    public static function globalOptionPathErrors(array $opts): array
    {
        $errors = [];
        foreach (['tempDir' => '--temp-dir', 'backupDir' => '--backup-dir'] as $key => $flag) {
            $p = trim((string) ($opts[$key] ?? ''));
            if ($p === '') {
                continue;
            }
            $checks = self::rawPathErrors($p, "Option $flag");
            if ($checks === [] && self::normalizePath($p) === '/') {
                $checks[] = "Option $flag '$p' must not be the filesystem root.";
            }
            foreach ($checks as $e) {
                $errors[] = $e;
            }
        }
        return $errors;
    }

    /**
     * Checks on the RAW value, before normalizePath() resolves ".." lexically and
     * hides it: "../../../../" or "/mnt/x/../../boot" are never what a user meant.
     *
     * @return array<int,string>
     */
    private static function rawPathErrors(string $path, string $label): array
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return ["$label contains control characters."];
        }
        if (in_array('..', explode('/', $path), true)) {
            return ["$label '$path' must not contain a \"..\" segment."];
        }
        return [];
    }

    /**
     * The --delete rule for one pair's destination: '' when it is safe, else the
     * error (without the "Pair #n: " prefix). Shared by validate() and
     * Runner::guardrailErrors so save time and run time cannot disagree.
     *
     * A daemon module reference keeps its own rule (a module root is allowed,
     * exact parity with the old one-segment SSH rule). A remote SSH destination
     * must be at least two segments deep and not a bare /mnt/<disk> root: one
     * typo'd or empty-variable path segment must not turn a mirror of
     * /srv/backup/photos into a mirror of /srv. Local destinations already had to
     * clear checkLocalPath's /mnt/<pool>/<dir> rule.
     */
    public static function deleteDestError(string $transport, bool $destIsRemote, string $destPath): string
    {
        if ($destPath === '') {
            return '';
        }
        if ($transport === 'DAEMON' && $destIsRemote) {
            // A module reference has no leading '/', so isSpecificSubPath would
            // reject every daemon destination - this is NOT a way of skipping
            // the guard, which would leave `host::<typo>` unguarded.
            $ok = self::isSpecificDaemonTarget($destPath);
        } elseif ($transport === 'SSH' && $destIsRemote) {
            if (!self::isDeepRemoteDeleteTarget($destPath)) {
                return 'a delete option is enabled, so the remote destination must be at least two directories '
                    . 'deep (for example /srv/backup) and not a bare /mnt/<disk> root.';
            }
            $ok = true;
        } else {
            $ok = self::isSpecificSubPath($destPath);
        }
        return $ok ? '' : 'a delete option is enabled, so the destination must be a specific sub-directory, not a root.';
    }

    /** At least two segments, and at least three under /mnt (/mnt/disk1 is a disk root). */
    public static function isDeepRemoteDeleteTarget(string $path): bool
    {
        $norm = self::normalizePath($path);
        if ($norm === '' || $norm[0] !== '/') {
            return false;
        }
        $segments = array_values(array_filter(explode('/', $norm), static fn($s) => $s !== ''));
        return count($segments) >= ($segments !== [] && $segments[0] === 'mnt' ? 3 : 2);
    }

    /**
     * Where a job writes: a scope (the remote endpoint, see remoteScope(), or
     * 'local' for this box) and the destination path of each pair, keyed by pair
     * index. Resolved exactly as Runner::resolvePair does.
     *
     * @param array<string,mixed>      $job
     * @param array<string,mixed>|null $creds loaded credentials, to resolve the endpoint
     * @return array{scope:string,paths:array<int,string>}
     */
    public static function destinations(array $job, ?array $creds = null): array
    {
        $transport    = strtoupper(trim((string) ($job['transport'] ?? 'SSH')));
        $direction    = strtoupper(trim((string) ($job['direction'] ?? 'PUSH')));
        $remoteHost   = in_array($transport, ['SSH', 'DAEMON'], true);
        $destIsRemote = $remoteHost && $direction !== 'PULL';
        // Under LOCAL (and an unknown transport) the destination is the `remote`
        // field, a second path on this box.
        $field = ($remoteHost && $direction === 'PULL') ? 'local' : 'remote';
        $paths = [];
        foreach ((is_array($job['pairs'] ?? null) ? $job['pairs'] : []) as $i => $pair) {
            $p = is_array($pair) ? trim((string) ($pair[$field] ?? '')) : '';
            if ($p !== '') {
                $paths[(int) $i] = $p;
            }
        }
        $scope = $destIsRemote ? self::remoteScope($transport, trim((string) ($job['connectionId'] ?? '')), $creds) : 'local';
        return ['scope' => $scope, 'paths' => $paths];
    }

    /**
     * The filesystem a remote destination lives on: host:port, so two
     * Connections to one endpoint share a scope. Not the user: an absolute path
     * is the same directory whoever logs in, and every daemon path starts with
     * its module. Falls back to the connection id when it cannot be resolved;
     * validate() reports that.
     *
     * @param array<string,mixed>|null $creds
     */
    private static function remoteScope(string $transport, string $connectionId, ?array $creds): string
    {
        $conn = is_array($creds) ? Credentials::findConnection($creds, $connectionId) : null;
        if ($conn === null) {
            return $transport . ':' . $connectionId;
        }
        $c = Credentials::mergeConnection($conn);
        return $transport . '@' . strtolower((string) $c['host']) . ':' . $c['port'];
    }

    /**
     * With a delete option on, no two pairs of one job may write to the same
     * directory or one inside the other: pairs run as separate transfers, so a
     * later pair's --delete removes what an earlier pair just copied.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts the job's EFFECTIVE rsync options
     * @return array<int,string>
     */
    public static function pairOverlapErrors(array $job, array $opts): array
    {
        if ((empty($opts['delete']) && empty($opts['deleteExcluded']))
            || Config::normalizeSnapshot($job['snapshot'] ?? null) !== null) {
            return [];
        }
        $paths  = self::destinations($job)['paths'];
        $errors = [];
        foreach ($paths as $i => $a) {
            foreach ($paths as $j => $b) {
                $na = self::normalizePath($a);
                $nb = self::normalizePath($b);
                if ($j > $i && ($na === $nb || self::pathContains($na, $nb) || self::pathContains($nb, $na))) {
                    $errors[] = 'Pair #' . ($i + 1) . ' and pair #' . ($j + 1) . ': a delete option is enabled, so their '
                        . "destinations must not be the same directory or lie inside one another ('$a', '$b').";
                }
            }
        }
        return $errors;
    }

    /**
     * The targets a --delete destination must keep clear of: every OTHER job's
     * destinations. A list so further protected roots can be appended to it.
     *
     * @param array<int,mixed>         $jobs
     * @param array<string,mixed>|null $creds loaded credentials, to resolve endpoints
     * @return array<int,array{scope:string,path:string,label:string}>
     */
    public static function protectedTargets(array $jobs, string $exceptJobId, ?array $creds = null): array
    {
        $out = [];
        foreach ($jobs as $other) {
            if (!is_array($other) || (string) ($other['id'] ?? '') === $exceptJobId) {
                continue;
            }
            $d     = self::destinations($other, $creds);
            $label = 'job "' . (string) (($other['name'] ?? '') !== '' ? $other['name'] : ($other['id'] ?? '')) . '"';
            foreach ($d['paths'] as $p) {
                $out[] = ['scope' => $d['scope'], 'path' => $p, 'label' => $label];
            }
            $snap = Config::normalizeSnapshot($other['snapshot'] ?? null);
            if ($snap !== null) {
                $out[] = [
                    'scope' => self::remoteScope('SSH', trim((string) ($other['connectionId'] ?? '')), $creds),
                    'path'  => $snap['root'],
                    'label' => 'the snapshot root of ' . $label,
                ];
            }
        }
        return $out;
    }

    /**
     * Snapshot roots must not overlap each other, and no job may write into one,
     * with or without --delete. Same scope (remote endpoint) only.
     *
     * @param array<string,mixed>      $job
     * @param array<int,mixed>         $jobs  the whole job set ($job may be among them)
     * @param array<string,mixed>|null $creds loaded credentials, to resolve endpoints
     * @return array<int,string>
     */
    public static function snapshotRootErrors(array $job, array $jobs, ?array $creds = null): array
    {
        $errors = [];
        $id     = (string) ($job['id'] ?? '');
        $mine   = self::snapshotRoot($job, $creds);
        $myDest = self::destinations($job, $creds);
        foreach ($jobs as $other) {
            if (!is_array($other) || (string) ($other['id'] ?? '') === $id) {
                continue;
            }
            $label  = 'job "' . (string) (($other['name'] ?? '') !== '' ? $other['name'] : ($other['id'] ?? '')) . '"';
            $theirs = self::snapshotRoot($other, $creds);
            if ($mine !== null && $theirs !== null && $mine['scope'] === $theirs['scope']
                && (self::atOrInside($mine['path'], $theirs['path']) || self::pathContains($mine['path'], $theirs['path']))) {
                $errors[] = "Snapshot root '{$mine['path']}' must not be, contain, or lie inside the snapshot root of "
                    . "$label ('{$theirs['path']}').";
            }
            if ($theirs !== null && $myDest['scope'] === $theirs['scope']) {
                foreach ($myDest['paths'] as $i => $p) {
                    if (self::atOrInside(self::normalizePath($p), $theirs['path'])) {
                        $errors[] = 'Pair #' . ($i + 1) . ': the destination must not be or lie inside the snapshot root of '
                            . "$label ('{$theirs['path']}').";
                    }
                }
            }
            if ($mine !== null) {
                $d = self::destinations($other, $creds);
                if ($d['scope'] === $mine['scope']) {
                    foreach ($d['paths'] as $p) {
                        if (self::atOrInside(self::normalizePath($p), $mine['path'])) {
                            $errors[] = "Snapshot root '{$mine['path']}' contains the destination of $label ('$p').";
                        }
                    }
                }
            }
        }
        return $errors;
    }

    /**
     * A job's enabled snapshot root and its scope, or null.
     *
     * @param array<string,mixed>      $job
     * @param array<string,mixed>|null $creds
     * @return array{scope:string,path:string}|null
     */
    private static function snapshotRoot(array $job, ?array $creds): ?array
    {
        $snap  = Config::normalizeSnapshot($job['snapshot'] ?? null);
        $root  = $snap !== null ? self::normalizePath($snap['root']) : '';
        $scope = self::remoteScope('SSH', trim((string) ($job['connectionId'] ?? '')), $creds);
        return $root === '' ? null : ['scope' => $scope, 'path' => $root];
    }

    private static function atOrInside(string $path, string $root): bool
    {
        return $path === $root || self::pathContains($root, $path);
    }

    /**
     * Ancestry rule: a --delete destination must not equal, contain, or lie
     * inside a protected target in the same scope - a mirror over another job's
     * destination deletes that job's backups.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $opts      the job's EFFECTIVE rsync options
     * @param array<int,array{scope:string,path:string,label:string}> $protected
     * @param array<string,mixed>|null $creds the credentials $protected was resolved with
     * @return array<int,string>
     */
    public static function ancestryErrors(array $job, array $opts, array $protected, ?array $creds = null): array
    {
        if (empty($opts['delete']) && empty($opts['deleteExcluded'])) {
            return [];
        }
        $errors = [];
        $d = self::destinations($job, $creds);
        foreach ($d['paths'] as $i => $path) {
            $mine = self::normalizePath($path);
            foreach ($protected as $t) {
                $theirs = self::normalizePath($t['path']);
                if ($t['scope'] !== $d['scope'] || $mine === '' || $theirs === '') {
                    continue;
                }
                if ($mine === $theirs || self::pathContains($mine, $theirs) || self::pathContains($theirs, $mine)) {
                    // A snapshot root's label already names what it is.
                    $what = str_starts_with($t['label'], 'the ') ? $t['label'] : "the destination of {$t['label']}";
                    $errors[] = 'Pair #' . ($i + 1) . ': a delete option is enabled, so the destination must not be, '
                        . "contain, or lie inside $what ('{$t['path']}').";
                }
            }
        }
        return $errors;
    }

    /** True when normalised $inner lies strictly inside normalised $outer. */
    private static function pathContains(string $outer, string $inner): bool
    {
        return $outer === '/' ? $inner !== '/' : str_starts_with($inner, $outer . '/');
    }

    /**
     * The optional target marker: a file on the DESTINATION side that must exist
     * (and, with targetMarkerContent, hold that value) before a run may write.
     * Validated like any destination path for the transport.
     *
     * @param array<string,mixed> $job
     * @return array<int,string>
     */
    public static function markerErrors(array $job, string $transport, string $direction): array
    {
        $destIsRemote = in_array($transport, ['SSH', 'DAEMON'], true) && $direction !== 'PULL';
        $marker  = trim((string) ($job['targetMarker'] ?? ''));
        $content = trim((string) ($job['targetMarkerContent'] ?? ''));
        $label   = 'Target marker';
        $errors  = [];
        if ($content !== '' && ($marker === '' || strlen($content) > 256 || preg_match('/[\x00-\x1f\x7f]/', $content))) {
            $errors[] = 'Target marker expected content needs a marker path and must be one line of at most 256 characters.';
        }
        if ($marker === '') {
            return $errors;
        }
        $checks = self::rawPathErrors($marker, $label);
        if ($checks === [] && str_ends_with($marker, '/')) {
            $checks[] = "$label '$marker' must name a file, not a directory.";
        }
        if ($checks === []) {
            if (!$destIsRemote) {
                $checks = self::checkLocalPath($marker, $label);
            } elseif ($transport === 'DAEMON') {
                $checks = self::checkDaemonModule($marker, $label);
            } else {
                $checks = self::checkRemotePath($marker, $label, false);
            }
        }
        return array_merge($errors, $checks);
    }

    /**
     * The one exception to checkLocalPath (spec §8): a pair's `local` may be
     * exactly /boot/ in a snapshot job that pushes over SSH, where `local` can
     * only be the source. LOCAL ignores direction, so it is excluded by the
     * transport test, not the direction.
     *
     * @param array<string,mixed> $job
     */
    public static function isAllowedBootSource(array $job, string $transport, string $local): bool
    {
        return $local === '/boot/'
            && $transport === 'SSH'
            && strtoupper(trim((string) ($job['direction'] ?? 'PUSH'))) === 'PUSH'
            && Config::normalizeSnapshot($job['snapshot'] ?? null) !== null;
    }

    /**
     * Guardrails for a pair's `local` field, shared by Job::validate and
     * Runner::guardrailErrors so save time and run time cannot disagree.
     * "/boot" without the slash would copy into <sub>/boot/, where the anchored
     * excludes (/previous/, the VPN state) silently stop matching.
     *
     * @param array<string,mixed> $job
     * @return array<int,string>
     */
    public static function pairLocalErrors(array $job, string $transport, string $local, string $label): array
    {
        if (self::isAllowedBootSource($job, $transport, $local)) {
            return [];
        }
        if ($local === '/boot' && self::isAllowedBootSource($job, $transport, '/boot/')) {
            return ["$label '/boot': Use \"/boot/\" (with the trailing slash) so the copy lands in <sub>/ and the excludes match."];
        }
        return self::checkLocalPath($local, $label);
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
            $errors[] = "$label '$path' must be a sub-directory of " . self::ALLOWED_LOCAL_ROOT . " (for example /mnt/user/share/...).";
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
     * @param bool $pairPath true (the default) when $path is a pair's `remote`
     *        side. false for --temp-dir / --backup-dir, where the daemon-shaped
     *        discriminators below are nonsense: those fields are never a module
     *        name, so a non-absolute value there gets the plain "must be an
     *        absolute path". The default preserves every existing call site.
     * @return array<int,string>
     */
    public static function checkRemotePath(string $path, string $label, bool $pairPath = true): array
    {
        $errors = [];
        $norm = self::normalizePath($path);

        if ($norm === '' || $norm[0] !== '/') {
            // Not a path at all. Name the specific thing the user most likely
            // pasted instead of the bare "must be an absolute path".
            //
            // These tests live INSIDE the non-absolute branch on purpose: a
            // colon is a legal POSIX filename character, so "::" can appear in
            // a perfectly good absolute path (/mnt/tank/Fate::Zero). Testing
            // the raw string would turn such a path into an error, and because
            // Runner::guardrailErrors shares this method that would fail an
            // ALREADY-SAVED job at run time. A real daemon address never
            // starts with "/", so this branch is the only safe place for it.
            $raw = trim($path);
            if ($pairPath && (stripos($raw, 'rsync://') === 0 || strpos($raw, '::') !== false)) {
                $errors[] = "$label '$path' is an rsync daemon address (host::module or rsync://). "
                    . self::DAEMON_MODULE_HINT;
                return $errors;
            }
            // A bare token shaped like a name, not a path: exactly how a NAS
            // "Rsync Server" page labels its backup modules.
            if ($pairPath && preg_match('/^[A-Za-z0-9._-]+$/D', $raw)) {
                $errors[] = "$label '$path' looks like an rsync daemon module name, not a path. "
                    . self::DAEMON_MODULE_HINT;
                return $errors;
            }
            // "nas:/vol/data" or "user@nas:/vol/data" - the other thing that
            // gets copied out of a working command line. The host belongs to
            // the Connection, so only the path goes in this field.
            if (preg_match('#^[^/]+:/#', $raw)) {
                $errors[] = "$label '$path' includes a host. The host comes from the job's "
                    . 'Connection, so enter only the path on the remote host here.';
                return $errors;
            }
            $errors[] = "$label must be an absolute path.";
            return $errors;
        }
        if (!self::isSpecificSubPath($norm)) {
            $errors[] = "$label '$path' must be a specific sub-directory, not the filesystem root.";
        }
        return $errors;
    }

    /**
     * Guardrail check for an rsync DAEMON module reference - the `remote` side of
     * a DAEMON pair, and --temp-dir/--backup-dir on a daemon receiver. The
     * operand is built as "[user@]host::<this>", so this value is a RELATIVE
     * module reference: "rsync_bkp", "rsync_bkp/photos", "rsync_bkp/photos/2026".
     * The host, port, username and secret all come from the job's Connection.
     *
     * A trailing slash is meaningful to rsync (it copies the directory's contents
     * rather than the directory), so it is left alone - rule 9 strips it only to
     * analyse the segments.
     *
     * Returns AT MOST ONE error (first rule wins), mirroring checkRemotePath.
     *
     * @return array<int,string>
     */
    public static function checkDaemonModule(string $path, string $label): array
    {
        $raw = trim($path);

        if ($raw === '') {
            return ["$label must be an rsync daemon module reference (for example rsync_bkp or rsync_bkp/photos)."];
        }
        if (strlen($raw) > 4096) {
            return ["$label is too long."];
        }
        if (preg_match('/[\x00-\x20\x7f]/', $raw)) {
            return ["$label '$path' contains whitespace or control characters."];
        }
        // A leading '-' would be read by rsync as an option, not an operand.
        if ($raw[0] === '-') {
            return ["$label '$path' must not begin with \"-\"."];
        }
        if (stripos($raw, 'rsync://') === 0 || strpos($raw, '::') !== false) {
            return ["$label '$path' includes the daemon host. The host, port and username come from the job's "
                . 'Connection, so enter only the module reference here (for example rsync_bkp or rsync_bkp/photos).'];
        }
        if ($raw[0] === '/') {
            return ["$label '$path' must not begin with \"/\". An rsync daemon path is relative to the module, "
                . 'so enter the module reference (for example rsync_bkp or rsync_bkp/photos), '
                . 'not an absolute filesystem path.'];
        }
        // A single ':' is left after the "::" test above, so this is the
        // "nas:module" / "host:873" paste. It matters more here than for an SSH
        // path: rsync's parse_hostspec breaks the operand's authority at the
        // FIRST ':' or '/', so a colon in the module half re-splits the whole
        // operand and can silently retarget the transfer.
        if (strpos($raw, ':') !== false) {
            return ["$label '$path' includes a host or port. The host, port and username come from the job's "
                . 'Connection, so enter only the module reference here.'];
        }
        if (preg_match('/[;&|`$()<>"\'\\\\]/', $raw)) {
            return ["$label '$path' contains unsafe characters."];
        }
        foreach (explode('/', rtrim($raw, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return ["$label '$path' must not contain \".\", \"..\" or empty path segments."];
            }
        }
        // The first segment is the MODULE name. rsyncd.conf.5 ("MODULE
        // PARAMETERS") only forbids '/' and ']' in a [module] name and collapses
        // whitespace, so this is OUR narrowing, not rsync's - kept tight because
        // the value becomes an rsync operand. A LEADING '.' or '_' is legal and
        // common ("_backup", ".hidden"), so it is accepted: barring it rejected
        // real modules while the message itself promised "letters, digits, dot,
        // dash or underscore". A leading '-' stays barred by the rule above (rsync
        // would read it as an option) and '.'/'..' segments by the segment rule above.
        $first = explode('/', $raw)[0];
        if (!preg_match('/^[A-Za-z0-9._][A-Za-z0-9._-]*$/D', $first)) {
            return ["$label '$path' is not a valid rsync daemon module reference. The first segment is the "
                . 'module name (letters, digits, dot, dash or underscore), optionally followed by a path '
                . 'inside it, for example rsync_bkp/photos.'];
        }

        return [];
    }

    /**
     * An advisory when a remote path COULD be an rsync daemon module name
     * rather than a filesystem path on the far host, or '' when it looks fine.
     *
     * WHY this exists: NAS appliances (Asustor, QNAP, Synology) expose an
     * "Rsync Server" page whose backup MODULES are addressed as host::module,
     * and that page shows only the module name - never the folder behind it.
     * Typed into a job's remote path, a module name becomes `host:/module` over
     * SSH and rsync fails with an opaque link_stat "..." No such file or
     * directory, long after save. Reported on the support forum.
     *
     * Advisory ONLY, never an error: a single top-level directory such as
     * /data, /backup or /srv is a perfectly ordinary remote path.
     */
    public static function daemonModuleNote(string $path): string
    {
        $norm = self::normalizePath($path);
        if ($norm === '' || $norm[0] !== '/') {
            return '';
        }
        $segments = array_values(array_filter(explode('/', $norm), static fn($s) => $s !== ''));
        if (count($segments) !== 1) {
            return '';
        }
        return 'is a single top-level directory, which may be an rsync daemon MODULE name '
            . 'rather than a folder on the remote host. ' . self::DAEMON_MODULE_HINT;
    }

    /**
     * True when $path is safe to hand to the remote host as a program to run:
     * one bare absolute path, no whitespace, no shell metacharacters, no ".."
     * segment, and not a directory. See the --rsync-path check in validate().
     */
    public static function isRemoteProgramPath(string $path): bool
    {
        $p = trim($path);
        // The accepted charset holds no shell metacharacter, and that must stay
        // true of the WHOLE value, because the remote shell re-parses it. /D is
        // belt-and-braces: the trim() above already removes the trailing newline
        // that PCRE's "$" would otherwise tolerate, so this only matters if a
        // future refactor drops the trim.
        if ($p === '' || strlen($p) > 4096 || !preg_match('#^/[A-Za-z0-9._/+-]+$#D', $p)) {
            return false;
        }
        if (str_ends_with($p, '/')) {
            return false;
        }
        foreach (explode('/', $p) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * Errors for the rsync OPTION values that need more than normalisation.
     *
     * Separate from validate() because the Global Settings tab stores this very
     * same option object with no job around it (handler.php's global branch),
     * and a job left on "use global config" - the DEFAULT for a new job - takes
     * those values verbatim. Validating only inside validate() would therefore
     * leave the default path unchecked.
     *
     * @param  array<string,mixed> $opts a job's (or the global) rsync options
     * @return array<int,string>
     */
    public static function validateRsyncOptions(array $opts): array
    {
        $errors = [];

        // --rsync-path names a program the REMOTE host is asked to run, so its
        // value is re-parsed by the remote shell - unlike every other scalar
        // here, whose value rsync consumes itself. Constrain it to a bare
        // absolute path so this stays an option and does not become the
        // free-form remote-command field the closed whitelist exists to avoid.
        $remoteRsync = trim((string) ($opts['remoteRsyncPath'] ?? ''));
        if ($remoteRsync !== '' && !self::isRemoteProgramPath($remoteRsync)) {
            $errors[] = 'The ' . self::SCALAR_FLAG_LABELS['remoteRsyncPath']
                . ' value must be an absolute path to the rsync binary on the remote host'
                . ' (for example /usr/local/bin/rsync), with no spaces or shell characters.';
        }

        // Numeric scalar options. A non-numeric value here would otherwise sail
        // through to rsync as `--max-delete=abc` and fail the run mid-flight with
        // a confusing rsync error; reject it at save time instead. (The `=value`
        // argv form already prevents option-injection - this is correctness/UX.)
        // rsync parses these integers base-0 (popt), so a leading zero means
        // OCTAL: --protocol=031 negotiates 25 and --timeout=08 is a syntax error.
        foreach (self::INTEGER_SCALAR_KEYS as $key) {
            $v = trim((string) ($opts[$key] ?? ''));
            if ($v !== '' && !preg_match('/^(0|[1-9]\d*)$/D', $v)) {
                $errors[] = 'The ' . self::SCALAR_FLAG_LABELS[$key]
                    . ' value must be a whole number without leading zeros.';
            }
        }
        foreach (self::SIZE_SCALAR_KEYS as $key) {
            $v = trim((string) ($opts[$key] ?? ''));
            // number (+ optional decimal), then an OPTIONAL suffix that is either
            // a unit letter [KMGTP] with optional binary "i" and optional "B"
            // (K, KiB, MB, G, ...), OR a bare "B" for bytes. A standalone "iB"
            // (no unit letter) is rejected.
            if ($v !== '' && !preg_match('/^\d+(\.\d+)?([KkMmGgTtPp]i?[Bb]?|[Bb])?$/', $v)) {
                $errors[] = 'The ' . self::SCALAR_FLAG_LABELS[$key]
                    . ' value must be a number, optionally with a size suffix (K, M, G, ...).';
            }
        }

        // rsync 3.x speaks protocols 20-32 and refuses anything else at run time
        // (0 or 99 surface as "protocol version mismatch -- is your shell clean?").
        // Forcing a version only ever LOWERS it - negotiation already picks the
        // highest both sides share - so a future rsync raising its maximum
        // cannot make a value above 32 useful.
        $protocol = trim((string) ($opts['protocol'] ?? ''));
        if (preg_match('/^(0|[1-9]\d*)$/D', $protocol)) {
            $n = (int) $protocol;
            if ($n < self::PROTOCOL_MIN || $n > self::PROTOCOL_MAX) {
                $errors[] = 'The ' . self::SCALAR_FLAG_LABELS['protocol'] . ' value must be between '
                    . self::PROTOCOL_MIN . ' and ' . self::PROTOCOL_MAX . '.';
            }
        }

        return $errors;
    }

    /**
     * True when -A/-X meet a forced protocol below 30, which rsync refuses with
     * exit 2 ("--acls requires protocol 30 or higher") on SSH and daemon
     * transfers. A local copy is exempt, so callers decide by transport.
     *
     * @param array<string,mixed> $opts
     */
    public static function protocolTooLowForAclsXattrs(array $opts): bool
    {
        $p = trim((string) ($opts['protocol'] ?? ''));
        return preg_match('/^[1-9]\d*$/D', $p) === 1
            && (int) $p >= self::PROTOCOL_MIN && (int) $p < 30
            && (!empty($opts['acls']) || !empty($opts['xattrs']));
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
     * True when a daemon module reference names something specific enough to be a
     * --delete destination. EXACT PARITY with isSpecificSubPath's rule for SSH,
     * where one segment ("/data") is enough - so a module ROOT is allowed, exactly
     * as "/data" is, and only an empty or structurally broken value is not.
     *
     * Do NOT skip the --delete guard for daemon instead: that would leave
     * `host::<typo>` unguarded while both other transports reject a bare root.
     */
    public static function isSpecificDaemonTarget(string $moduleRef): bool
    {
        $raw = trim($moduleRef);
        if ($raw === '' || $raw[0] === '/') {
            return false;
        }
        foreach (explode('/', rtrim($raw, '/')) as $segment) {
            if ($segment !== '' && $segment !== '.' && $segment !== '..') {
                return true;
            }
        }
        return false;
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
     * day-of-week). Supports the common syntax: "*", ranges (a-b), lists (a,b),
     * step values ("*" or "a-b" followed by "/N", with no spaces), and named
     * month/day-of-week tokens (jan..dec, sun..sat).
     *
     * Delegates to Cron::isValidExpression so the SAVE-TIME validator and the
     * NEXT-RUN calculator share one grammar and can never drift (previously this
     * re-implemented the same parser independently - CQ-04). Cron is lazily
     * required because some callers (e.g. the runner) load Job without Cron; the
     * require is a no-op when Cron is already loaded, and Cron.php's own top-level
     * require of Job.php is already satisfied by the time we get here.
     */
    public static function isValidCron(string $expr): bool
    {
        if (!class_exists('Cron', false)) {
            require_once __DIR__ . '/Cron.php';
        }
        return Cron::isValidExpression($expr);
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

<?php
/**
 * Config.php - the single source of truth for the Unraid Rsync plugin's
 * persistent configuration (config.json).
 *
 * Responsibilities (Phase 2):
 *   - Locate config.json on the USB flash at
 *     /boot/config/plugins/unraid.rsync/config.json.
 *   - Load it, merging in defaults for any missing keys so callers always get
 *     a fully-populated structure (forward-compatible reads).
 *   - Save it atomically (write to a temp file in the same directory, then
 *     rename over the target) so a crash mid-write can never leave a truncated
 *     or partial config behind.
 *   - Carry a schemaVersion and provide a forward-migration scaffold so future
 *     phases can upgrade older on-disk configs in place.
 *
 * This file is intentionally free of any webGui dependency and does no output:
 * all logic is plain, unit-testable PHP. The config base directory is
 * overridable via the UR_CONFIG_BASE constant (define it before requiring this
 * file - e.g. tests point it at a temp dir) so the same code runs on a live
 * Unraid box and under PHPUnit without touching /boot.
 */

// Base directory that holds config.json. Overridable for tests / alternate
// installs by defining UR_CONFIG_BASE before this file is required.
if (!defined('UR_CONFIG_BASE')) {
    define('UR_CONFIG_BASE', '/boot/config/plugins/unraid.rsync');
}

class Config
{
    /** Current schema version this code writes. */
    const SCHEMA_VERSION = 1;

    /**
     * Absolute path to config.json under the (possibly overridden) base dir.
     */
    public static function path(): string
    {
        return rtrim(UR_CONFIG_BASE, '/') . '/config.json';
    }

    /**
     * The full default rsync-options object (the whitelist shape). Every key
     * the UI renders appears here so a freshly-created config and a new job
     * both start from a known, complete shape. Booleans default to a safe,
     * conservative set; value inputs default to empty string / empty list.
     *
     * @return array<string,mixed>
     */
    public static function defaultRsyncOptions(): array
    {
        return [
            // boolean flags
            // Default profile = a safe, recursive copy for the typical user:
            // recurse into dirs (-r) + preserve mtimes (-t, sane incrementals) +
            // human-readable, but NOT archive (-a) — -a's owner/group/perm
            // preservation is a cross-host/non-root footgun. --delete (true
            // mirror) is deliberately OFF by default: it is destructive and is a
            // per-job opt-in (the options form auto-seeds --max-delete when it is
            // enabled). Progress is already shown at the default 'normal' log
            // level (--info=...,progress2), so there is no separate flag.
            // mkpath (--mkpath) defaults ON so a brand-new destination path
            // (incl. missing parent dirs) is created automatically instead of
            // failing the first run with "mkdir failed: No such file or directory".
            'recursive'       => true,
            'archive'         => false,
            'compress'        => false,
            'humanReadable'   => true,
            'times'           => true,
            'perms'           => false,
            'xattrs'          => false,
            'acls'            => false,
            'symlinks'        => false,
            'hardlinks'       => false,
            'sparse'          => false,
            'numericIds'      => false,
            'partial'         => true,
            'inplace'         => false,
            'checksum'        => false,
            'update'          => false,
            'wholeFile'       => false,
            'sizeOnly'        => false,
            'ignoreExisting'  => false,
            'delete'          => false,
            'deleteExcluded'  => false,
            'mkpath'          => true,
            // value inputs
            'excludes'        => [],
            'includes'        => [],
            'maxDelete'       => '',
            'bwlimit'         => '',
            'timeout'         => '',
            'contimeout'      => '',
            'maxSize'         => '',
            'minSize'         => '',
            'chmod'           => '',
            'tempDir'         => '',
            'backupDir'       => '',
            'compressLevel'   => '',
            'modifyWindow'    => '',
        ];
    }

    /**
     * The default top-level config structure (an empty install).
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'global' => [
                'defaultRsyncOptions' => self::defaultRsyncOptions(),
                // How many past executions to keep PER JOB - bounds both the
                // tmpfs run logs and the persistent history records.
                'retention' => self::DEFAULT_RETENTION,
                // Optional persistent log directory (an array/pool path under
                // /mnt). Empty (the default) = run logs live in RAM (tmpfs) and
                // are cleared on reboot. When set, run logs + plugin.log are
                // written here so they survive a reboot. See sanitizeLogDir().
                'logDir' => '',
                // Optional secrets directory (an array/pool path under /mnt).
                // Empty (the default) = credentials.json lives on /boot (the USB
                // flash). When set, credentials.json is relocated there so it gets
                // real chmod 600 perms (and array-at-rest encryption if enabled)
                // instead of world-readable FAT32. See sanitizeSecretsDir().
                'secretsDir' => '',
            ],
            'jobs' => [],
        ];
    }

    /** Default + bounds for the "keep last N executions" retention setting. */
    const DEFAULT_RETENTION = 100;
    const MIN_RETENTION     = 1;
    const MAX_RETENTION     = 9999;

    /**
     * Clamp an arbitrary retention input to [MIN_RETENTION, MAX_RETENTION]; a
     * non-numeric / missing value falls back to DEFAULT_RETENTION. Single source
     * of truth for both the save-time clamp and the loaded value.
     *
     * @param mixed $value
     */
    public static function clampRetention($value): int
    {
        // Strict integer validation: FILTER_VALIDATE_INT rejects "1e3", "2.9",
        // "" and other non-integer numerics (which a bare (int) cast would
        // silently mangle to 1/2/0), falling back to the default instead.
        $n = filter_var($value, FILTER_VALIDATE_INT);
        if ($n === false) {
            return self::DEFAULT_RETENTION;
        }
        if ($n < self::MIN_RETENTION) {
            return self::MIN_RETENTION;
        }
        if ($n > self::MAX_RETENTION) {
            return self::MAX_RETENTION;
        }
        return $n;
    }

    /** The effective retention from the loaded config (clamped). */
    public static function retention(): int
    {
        try {
            $cfg = self::load();
        } catch (Throwable $e) {
            return self::DEFAULT_RETENTION;
        }
        return self::clampRetention($cfg['global']['retention'] ?? self::DEFAULT_RETENTION);
    }

    /**
     * Confine a user-supplied directory to an Unraid storage path, returning the
     * cleaned absolute path or '' (meaning "unset"). Shared by sanitizeLogDir()
     * and sanitizeSecretsDir() so both honour ONE confinement rule and can't
     * redirect root-written data into a system location:
     *   - must be a non-empty, absolute (leading "/") string;
     *   - no control bytes (NUL/newline) - those could break a path or a header;
     *   - no "." / ".." traversal segments;
     *   - must live UNDER /mnt/<something>/ (array disk, cache, pool, or an
     *     Unassigned Devices mount) - never the bare /mnt, /mnt/user root, or a
     *     system path like /etc or /boot (flash wear).
     * Anything failing these returns '' rather than throwing, so an invalid value
     * falls back to the caller's safe default; the save path surfaces a warning.
     *
     * @param mixed $value
     */
    private static function sanitizeMntDir($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $v = trim($value);
        if ($v === '' || $v[0] !== '/') {
            return '';
        }
        if (preg_match('/[\x00-\x1f]/', $v)) {
            return '';
        }
        foreach (explode('/', $v) as $seg) {
            if ($seg === '.' || $seg === '..') {
                return '';
            }
        }
        $v = rtrim($v, '/');
        // Require at least /mnt/<top>/<leaf> so the bare /mnt and /mnt/user roots
        // (and any non-/mnt system path) are rejected.
        if (!preg_match('#^/mnt/[^/]+/.+#', $v)) {
            return '';
        }
        return $v;
    }

    /**
     * Validate a user-supplied persistent log directory, returning the cleaned
     * absolute path or '' (meaning "unset" -> logs stay in RAM/tmpfs). See
     * sanitizeMntDir() for the confinement rules.
     *
     * @param mixed $value
     */
    public static function sanitizeLogDir($value): string
    {
        return self::sanitizeMntDir($value);
    }

    /**
     * The effective persistent log dir from the loaded config (validated), or ''
     * when unset/invalid (RAM-only). Mirrors retention().
     */
    public static function logDir(): string
    {
        try {
            $cfg = self::load();
        } catch (Throwable $e) {
            return '';
        }
        return self::sanitizeLogDir($cfg['global']['logDir'] ?? '');
    }

    /**
     * Validate a user-supplied secrets directory (where credentials.json lives),
     * returning the cleaned absolute path or '' (meaning "use the default /boot
     * config dir"). Same confinement as the log dir (see sanitizeMntDir): an
     * absolute path under /mnt, so credentials can sit on a permission-respecting
     * (and optionally encrypted) array/pool filesystem with real chmod 600 instead
     * of world-readable FAT32 flash. '' keeps credentials.json on /boot - the
     * backward-compatible default that survives a reboot before the array starts
     * and is captured by the standard USB flash backup.
     *
     * @param mixed $value
     */
    public static function sanitizeSecretsDir($value): string
    {
        return self::sanitizeMntDir($value);
    }

    /**
     * The effective secrets dir from the loaded config (validated), or '' when
     * unset/invalid (credentials.json stays on /boot). Mirrors logDir().
     */
    public static function secretsDir(): string
    {
        try {
            $cfg = self::load();
        } catch (Throwable $e) {
            return '';
        }
        return self::sanitizeSecretsDir($cfg['global']['secretsDir'] ?? '');
    }

    /**
     * The default shape of a single job. New jobs are seeded from this; the
     * rsyncOptions sub-object uses the same whitelist shape as the global
     * defaults.
     *
     * @return array<string,mixed>
     */
    public static function defaultJob(): array
    {
        return [
            'id'                => '',
            'name'              => '',
            'enabled'           => true,
            'manualOnly'        => false,
            'schedule'          => '0 3 * * *',
            'transport'         => 'SSH',
            'connectionId'      => '',
            'direction'         => 'PUSH',
            'pairs'             => [],
            'useGlobalDefaults' => false,
            'rsyncOptions'      => self::defaultRsyncOptions(),
            'logLevel'          => 'normal',
            'preHook'           => '',
            'postHook'          => '',
            'notifyMode'        => 'failure-only',
        ];
    }

    /**
     * Load config.json, returning a fully-populated structure. Missing keys are
     * filled from defaults so callers never have to null-check.
     *
     * Contract:
     *   - File absent or empty            -> the default (empty-install) config.
     *   - File present but unreadable      -> throws RuntimeException.
     *   - File present but malformed JSON  -> throws RuntimeException.
     *   - File from a NEWER schemaVersion than this build understands -> throws
     *     RuntimeException (migrate() refuses to downgrade).
     *
     * Callers MUST treat a thrown exception as "do not overwrite": the save
     * path turns it into a 4xx/5xx error rather than falling back to defaults
     * and clobbering a recoverable file. (The read-only UI pages may catch and
     * render defaults for display only; they do not persist on load.)
     *
     * @return array<string,mixed>
     * @throws RuntimeException when the file exists but is unreadable, contains
     *                          malformed JSON, or is from a newer schema.
     */
    public static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return self::defaults();
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            // The file exists but can't be read (permissions / transient I/O).
            // Treat this as a hard error like malformed JSON: returning defaults
            // here would let the save path overwrite a recoverable file with a
            // fresh empty config, silently clobbering it.
            throw new RuntimeException("config.json exists but could not be read: $path");
        }
        $raw = trim($raw);
        if ($raw === '') {
            return self::defaults();
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('config.json is not valid JSON');
        }

        $data = self::migrate($data);
        return self::mergeDefaults($data);
    }

    /**
     * Persist the given config structure atomically.
     *
     * The schemaVersion is always stamped to the current version. The base
     * directory is created if it does not exist. The write goes to a temp file
     * in the same directory and is renamed over the target (rename is atomic on
     * a POSIX filesystem within one directory), so readers never see a
     * half-written file.
     *
     * @param array<string,mixed> $config
     * @throws RuntimeException on any filesystem failure.
     */
    public static function save(array $config): void
    {
        $config['schemaVersion'] = self::SCHEMA_VERSION;
        // Persist the merged shape so on-disk config is always complete.
        $config = self::mergeDefaults($config);

        $path = self::path();
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create config directory: $dir");
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode config as JSON: ' . json_last_error_msg());
        }
        // Trailing newline keeps the file POSIX-tidy / diff-friendly.
        $json .= "\n";

        $tmp = @tempnam($dir, '.config.json.');
        if ($tmp === false) {
            throw new RuntimeException("Unable to create temp file in: $dir");
        }

        if (@file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write temp config file: $tmp");
        }
        // Best-effort: make it group/world readable like other webGui config
        // (FAT32 ignores this; on the test filesystem it keeps perms sane).
        @chmod($tmp, 0644);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to atomically replace config: $path");
        }
    }

    /**
     * Forward-migration scaffold. Given an on-disk config of any prior schema
     * version, return it upgraded to the current SCHEMA_VERSION. Phase 2 only
     * knows about version 1, so this currently just stamps the version; future
     * phases add `case` arms that transform the structure step by step.
     *
     * If the on-disk config carries a schemaVersion NEWER than this build
     * understands, we refuse to "migrate" it (we cannot downgrade, and
     * mergeDefaults would drop fields this build doesn't know about, silently
     * destroying them). Throw instead so the caller can warn and avoid data
     * loss - e.g. when a user has rolled back to an older plugin version.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     * @throws RuntimeException when $data is from a newer schema version.
     */
    public static function migrate(array $data): array
    {
        $version = isset($data['schemaVersion']) && is_int($data['schemaVersion'])
            ? $data['schemaVersion']
            : 1;

        if ($version > self::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf(
                'config.json schemaVersion %d is newer than this plugin supports (%d); '
                . 'refusing to load to avoid data loss. Update the plugin.',
                $version,
                self::SCHEMA_VERSION
            ));
        }

        // Step the config up one version at a time. Each future arm mutates
        // $data from version N to N+1, then falls through to the next.
        while ($version < self::SCHEMA_VERSION) {
            switch ($version) {
                // case 1: ... transform v1 -> v2 ...; $version = 2; break;
                default:
                    // No migration path defined; stop to avoid an infinite loop.
                    $version = self::SCHEMA_VERSION;
                    break;
            }
        }

        $data['schemaVersion'] = self::SCHEMA_VERSION;
        return $data;
    }

    /**
     * Recursively merge loaded data over the default structure so missing keys
     * are filled while present keys win. Jobs and rsync-option objects are
     * normalised to their full default shape so the UI never encounters a
     * missing field.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function mergeDefaults(array $data): array
    {
        $defaults = self::defaults();

        // schemaVersion: keep what we have (migrate() already normalised it),
        // else default.
        $out = [];
        $out['schemaVersion'] = isset($data['schemaVersion']) && is_int($data['schemaVersion'])
            ? $data['schemaVersion']
            : $defaults['schemaVersion'];

        // global.defaultRsyncOptions: fill any missing option keys.
        $globalIn  = isset($data['global']) && is_array($data['global']) ? $data['global'] : [];
        $optsIn    = isset($globalIn['defaultRsyncOptions']) && is_array($globalIn['defaultRsyncOptions'])
            ? $globalIn['defaultRsyncOptions']
            : [];
        $out['global'] = [
            'defaultRsyncOptions' => self::mergeRsyncOptions($optsIn),
            // retention: clamp to [MIN,MAX]; missing/garbage -> default.
            'retention' => self::clampRetention($globalIn['retention'] ?? self::DEFAULT_RETENTION),
            // logDir: validate/confine; invalid or missing -> '' (RAM-only).
            'logDir' => self::sanitizeLogDir($globalIn['logDir'] ?? ''),
            // secretsDir: validate/confine; invalid or missing -> '' (/boot).
            'secretsDir' => self::sanitizeSecretsDir($globalIn['secretsDir'] ?? ''),
        ];

        // jobs: normalise each to the full default-job shape.
        $out['jobs'] = [];
        if (isset($data['jobs']) && is_array($data['jobs'])) {
            foreach ($data['jobs'] as $job) {
                if (is_array($job)) {
                    $out['jobs'][] = self::mergeJob($job);
                }
            }
        }

        return $out;
    }

    /**
     * Fill a single rsync-options object with defaults for any missing key.
     * Only known whitelist keys are kept; unknown keys are discarded so the
     * stored shape stays canonical.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function mergeRsyncOptions(array $opts): array
    {
        $defaults = self::defaultRsyncOptions();
        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = array_key_exists($key, $opts) ? $opts[$key] : $default;
        }
        return $out;
    }

    /**
     * Normalise a single job to the full default-job shape (missing keys
     * filled, rsyncOptions canonicalised).
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function mergeJob(array $job): array
    {
        $defaults = self::defaultJob();
        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = array_key_exists($key, $job) ? $job[$key] : $default;
        }
        // rsyncOptions must be the canonical whitelist shape.
        $out['rsyncOptions'] = self::mergeRsyncOptions(
            is_array($out['rsyncOptions']) ? $out['rsyncOptions'] : []
        );
        // pairs must be a list of {local,remote}.
        $pairs = [];
        if (is_array($out['pairs'])) {
            foreach ($out['pairs'] as $pair) {
                if (is_array($pair)) {
                    $pairs[] = [
                        'local'  => isset($pair['local'])  ? (string) $pair['local']  : '',
                        'remote' => isset($pair['remote']) ? (string) $pair['remote'] : '',
                    ];
                }
            }
        }
        $out['pairs'] = $pairs;
        return $out;
    }
}

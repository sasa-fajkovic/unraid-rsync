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
            'archive'         => true,
            'compress'        => false,
            'humanReadable'   => true,
            'times'           => false,
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
            ],
            'jobs' => [],
        ];
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
     *   - File present but unreadable     -> the default config (best effort).
     *   - File present but malformed JSON -> throws RuntimeException.
     *   - File from a NEWER schemaVersion than this build understands -> throws
     *     RuntimeException (migrate() refuses to downgrade).
     *
     * Callers MUST treat a thrown exception as "do not overwrite": the save
     * path turns it into a 4xx error rather than falling back to defaults and
     * clobbering a recoverable file. (The read-only UI pages may catch and
     * render defaults for display only; they do not persist on load.)
     *
     * @return array<string,mixed>
     * @throws RuntimeException on malformed JSON or a newer-than-supported schema.
     */
    public static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return self::defaults();
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            // Unreadable - treat as empty rather than crashing the UI.
            return self::defaults();
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

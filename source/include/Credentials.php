<?php
/**
 * Credentials.php - load/save credentials.json for the Unraid Rsync plugin.
 *
 * credentials.json is a SEPARATE file from config.json (a different blast
 * radius: secrets vs. job definitions). It mirrors Config.php's conventions
 * exactly so the two behave identically on disk:
 *   - atomic temp+rename writes (a crash mid-write never truncates the file);
 *   - JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
 *   - a schemaVersion with a forward-migration scaffold that REFUSES to load a
 *     newer schema (no silent downgrade / field loss);
 *   - throws on an existing-but-unreadable file so the caller can refuse to
 *     clobber a recoverable file;
 *   - the base directory is overridable via the UR_CONFIG_BASE constant (the
 *     same constant Config.php uses), so unit tests point both files at a temp
 *     dir and never touch /boot.
 *
 * This file holds two object kinds:
 *
 *   SSH Keys
 *     { id, name, privateKey, publicKey, fingerprint }
 *     - name is unique across keys.
 *     - privateKey is stored verbatim (PEM/OpenSSH). It is a SECRET; on a live
 *       box credentials.json lives on FAT32 /boot (world-readable) - this is
 *       documented in the UI/README. Empty passphrase only (unattended cron).
 *
 *   Connections
 *     { id, name, host, port, username, authMethod, keyId, password,
 *       remoteHostKey, strictHostKey, connectTimeout }
 *     - authMethod KEY references a key by id (keyId); PASSWORD stores an
 *       OBFUSCATED password (reversible, NOT encryption - documented as
 *       recoverable by anyone with flash access).
 *
 * Referential integrity (the TrueNAS used_by pattern) is enforced by the
 * handler, which calls usedBy() / the typed delete helpers here:
 *   - deleting a KEY referenced by any connection is BLOCKED (the caller is
 *     told which connections depend on it);
 *   - deleting a CONNECTION referenced by jobs DISABLES those jobs in
 *     config.json rather than silently breaking them.
 *
 * SECURITY NOTE: this class NEVER builds an ssh/rsync command line and never
 * touches the network. Generating/importing keys and discovering host keys (the
 * thin ssh-keygen / ssh-keyscan wrappers) live in KeyTools.php, and the ssh
 * transport/materialisation lives in Ssh.php, so the persistence logic here
 * stays pure and unit-testable offline. Password obfuscation here is
 * deliberately reversible and is NOT a security boundary.
 */

require_once __DIR__ . '/Config.php';

class Credentials
{
    /** Current schema version this code writes. */
    const SCHEMA_VERSION = 1;

    /** Allowed connection auth methods. */
    const AUTH_METHODS = ['KEY', 'PASSWORD'];

    /** Allowed StrictHostKeyChecking modes (the ssh -o value). */
    const STRICT_HOST_KEY_MODES = ['accept-new', 'yes', 'no'];

    /**
     * A fixed XOR pad used for the reversible password obfuscation. This is NOT
     * encryption and provides NO confidentiality against anyone with flash
     * access (the key is right here in the source). Its only purpose is to keep
     * the password from sitting in plain sight in credentials.json / a casual
     * `cat`. The UI warns the user explicitly; we recommend a dedicated
     * low-privilege remote account. Documented in the plan as obfuscation-only.
     */
    const OBFUSCATION_PAD = 'unraid.rsync/credentials/v1';

    /**
     * Absolute path to credentials.json under the (possibly overridden) base
     * dir. Reuses UR_CONFIG_BASE so it lives beside config.json.
     */
    public static function path(): string
    {
        return rtrim(UR_CONFIG_BASE, '/') . '/credentials.json';
    }

    /**
     * The default top-level credentials structure (an empty keychain).
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'keys'          => [],
            'connections'   => [],
        ];
    }

    /**
     * The default shape of a single SSH key entry.
     *
     * @return array<string,mixed>
     */
    public static function defaultKey(): array
    {
        return [
            'id'          => '',
            'name'        => '',
            'privateKey'  => '',
            'publicKey'   => '',
            'fingerprint' => '',
        ];
    }

    /**
     * The default shape of a single connection entry.
     *
     * @return array<string,mixed>
     */
    public static function defaultConnection(): array
    {
        return [
            'id'             => '',
            'name'           => '',
            'host'           => '',
            'port'           => 22,
            'username'       => '',
            'authMethod'     => 'KEY',
            'keyId'          => '',
            'password'       => '',           // stored obfuscated
            'remoteHostKey'  => '',
            'strictHostKey'  => 'accept-new',
            'connectTimeout' => 10,
        ];
    }

    /**
     * Load credentials.json, returning a fully-populated structure. Missing
     * keys are filled from defaults so callers never have to null-check.
     *
     * Contract (identical to Config::load):
     *   - File absent or empty            -> the default (empty) keychain.
     *   - File present but unreadable      -> throws RuntimeException.
     *   - File present but malformed JSON  -> throws RuntimeException.
     *   - File from a NEWER schemaVersion  -> throws RuntimeException.
     *
     * @return array<string,mixed>
     * @throws RuntimeException when the file exists but is unreadable, malformed,
     *                          or from a newer schema.
     */
    public static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return self::defaults();
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            // Existing-but-unreadable: a hard error, like malformed JSON.
            // Returning defaults here would let the save path overwrite a
            // recoverable secrets file with an empty one.
            throw new RuntimeException("credentials.json exists but could not be read: $path");
        }
        $raw = trim($raw);
        if ($raw === '') {
            return self::defaults();
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('credentials.json is not valid JSON');
        }

        $data = self::migrate($data);
        return self::mergeDefaults($data);
    }

    /**
     * Persist the given credentials structure atomically (temp file in the same
     * dir, then rename). The schemaVersion is always stamped to the current
     * version and the shape is normalised so on-disk data is always complete.
     *
     * @param array<string,mixed> $creds
     * @throws RuntimeException on any filesystem failure.
     */
    public static function save(array $creds): void
    {
        $creds['schemaVersion'] = self::SCHEMA_VERSION;
        $creds = self::mergeDefaults($creds);

        $path = self::path();
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create credentials directory: $dir");
        }

        $json = json_encode($creds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode credentials as JSON: ' . json_last_error_msg());
        }
        $json .= "\n";

        $tmp = @tempnam($dir, '.credentials.json.');
        if ($tmp === false) {
            throw new RuntimeException("Unable to create temp file in: $dir");
        }

        if (@file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write temp credentials file: $tmp");
        }
        // Best-effort tighten perms. FAT32 ignores this (the documented reason
        // secrets are obfuscation-only and keys are materialised to tmpfs 600
        // before use); on a real filesystem it at least keeps perms sane.
        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to atomically replace credentials: $path");
        }
    }

    /**
     * Forward-migration scaffold. Refuses to load a NEWER schema (cannot
     * downgrade; mergeDefaults would drop unknown fields and destroy data).
     * Mirrors Config::migrate.
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
                'credentials.json schemaVersion %d is newer than this plugin supports (%d); '
                . 'refusing to load to avoid data loss. Update the plugin.',
                $version,
                self::SCHEMA_VERSION
            ));
        }

        while ($version < self::SCHEMA_VERSION) {
            switch ($version) {
                // case 1: ... transform v1 -> v2 ...; $version = 2; break;
                default:
                    $version = self::SCHEMA_VERSION;
                    break;
            }
        }

        $data['schemaVersion'] = self::SCHEMA_VERSION;
        return $data;
    }

    /**
     * Recursively merge loaded data over the default structure so missing keys
     * are filled while present keys win. Keys and connections are normalised to
     * their full default shape so the UI never encounters a missing field.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function mergeDefaults(array $data): array
    {
        $out = [];
        $out['schemaVersion'] = isset($data['schemaVersion']) && is_int($data['schemaVersion'])
            ? $data['schemaVersion']
            : self::SCHEMA_VERSION;

        $out['keys'] = [];
        if (isset($data['keys']) && is_array($data['keys'])) {
            foreach ($data['keys'] as $key) {
                if (is_array($key)) {
                    $out['keys'][] = self::mergeKey($key);
                }
            }
        }

        $out['connections'] = [];
        if (isset($data['connections']) && is_array($data['connections'])) {
            foreach ($data['connections'] as $conn) {
                if (is_array($conn)) {
                    $out['connections'][] = self::mergeConnection($conn);
                }
            }
        }

        return $out;
    }

    /**
     * Normalise a single key to the full default-key shape (missing keys filled,
     * unknown keys dropped, scalars coerced to strings).
     *
     * @param array<string,mixed> $key
     * @return array<string,mixed>
     */
    public static function mergeKey(array $key): array
    {
        $defaults = self::defaultKey();
        $out = [];
        foreach ($defaults as $k => $default) {
            $out[$k] = array_key_exists($k, $key) ? (string) $key[$k] : $default;
        }
        // Canonicalise identity fields by trimming, so stored values are
        // whitespace-clean and downstream uniqueness checks (which compare
        // case-insensitively) can't be fooled by leading/trailing whitespace.
        // The key MATERIAL (privateKey/publicKey/fingerprint) is left intact.
        $out['id']   = trim($out['id']);
        $out['name'] = trim($out['name']);
        return $out;
    }

    /**
     * Normalise a single connection to the full default shape. Enum-ish fields
     * are clamped to known-good values; port/timeout are coerced to sane ints.
     *
     * @param array<string,mixed> $conn
     * @return array<string,mixed>
     */
    public static function mergeConnection(array $conn): array
    {
        $defaults = self::defaultConnection();
        $out = [];

        // Identity / connection-target fields are trimmed so stored values are
        // whitespace-clean: a name/host/username with stray whitespace can look
        // valid in the UI yet fail (or behave inconsistently in uniqueness
        // checks) at run time. password + remoteHostKey are NOT trimmed here
        // (a password may legitimately contain leading/trailing spaces; the
        // host key is multi-line text written verbatim).
        $out['id']       = isset($conn['id'])       ? trim((string) $conn['id'])       : $defaults['id'];
        $out['name']     = isset($conn['name'])     ? trim((string) $conn['name'])     : $defaults['name'];
        $out['host']     = isset($conn['host'])     ? trim((string) $conn['host'])     : $defaults['host'];
        $out['username'] = isset($conn['username']) ? trim((string) $conn['username']) : $defaults['username'];
        $out['keyId']    = isset($conn['keyId'])    ? trim((string) $conn['keyId'])    : $defaults['keyId'];
        $out['password'] = isset($conn['password']) ? (string) $conn['password'] : $defaults['password'];
        $out['remoteHostKey'] = isset($conn['remoteHostKey']) ? (string) $conn['remoteHostKey'] : $defaults['remoteHostKey'];

        $port = isset($conn['port']) ? (int) $conn['port'] : $defaults['port'];
        $out['port'] = ($port >= 1 && $port <= 65535) ? $port : $defaults['port'];

        $auth = strtoupper(trim((string) ($conn['authMethod'] ?? $defaults['authMethod'])));
        $out['authMethod'] = in_array($auth, self::AUTH_METHODS, true) ? $auth : $defaults['authMethod'];

        $mode = strtolower(trim((string) ($conn['strictHostKey'] ?? $defaults['strictHostKey'])));
        $out['strictHostKey'] = in_array($mode, self::STRICT_HOST_KEY_MODES, true) ? $mode : $defaults['strictHostKey'];

        $timeout = isset($conn['connectTimeout']) ? (int) $conn['connectTimeout'] : $defaults['connectTimeout'];
        $out['connectTimeout'] = ($timeout >= 1 && $timeout <= 600) ? $timeout : $defaults['connectTimeout'];

        return $out;
    }

    // --- validation --------------------------------------------------------

    /**
     * Validate a NORMALISED (merged) key entry. Returns a structured result:
     *   [ 'valid' => bool, 'errors' => string[] ]
     * A key must have a non-empty name (unique across $existingNames, which the
     * caller assembles excluding this key's own current name on edit) and at
     * least a public key or a private key from which one was derived.
     *
     * @param array<string,mixed> $key
     * @param array<int,string>   $existingNames other keys' names (lowercased
     *                                            comparison) to enforce uniqueness
     * @return array{valid:bool,errors:array<int,string>}
     */
    public static function validateKey(array $key, array $existingNames = []): array
    {
        $errors = [];

        $name = trim((string) ($key['name'] ?? ''));
        if ($name === '') {
            $errors[] = 'Key name is required.';
        } else {
            $lower = strtolower($name);
            // Trim AND lowercase the existing names so a hand-edited
            // credentials.json with stray whitespace can't sneak in a duplicate
            // that differs only by invisible whitespace.
            $taken = array_map(static fn($n) => strtolower(trim((string) $n)), $existingNames);
            if (in_array($lower, $taken, true)) {
                $errors[] = 'A key named "' . $name . '" already exists; names must be unique.';
            }
        }

        if (trim((string) ($key['publicKey'] ?? '')) === '' && trim((string) ($key['privateKey'] ?? '')) === '') {
            $errors[] = 'A key must have at least a public or a private key.';
        }

        return ['valid' => count($errors) === 0, 'errors' => $errors];
    }

    /**
     * Validate a NORMALISED (merged) connection entry against a loaded
     * credentials structure (so a KEY-auth connection's keyId is checked to
     * reference an existing key). Returns [ 'valid' => bool, 'errors' => [] ].
     *
     * @param array<string,mixed> $conn  a connection already run through mergeConnection()
     * @param array<string,mixed> $creds loaded credentials structure (for keyId lookup)
     * @return array{valid:bool,errors:array<int,string>}
     */
    public static function validateConnection(array $conn, array $creds): array
    {
        $errors = [];

        if (trim((string) ($conn['name'] ?? '')) === '') {
            $errors[] = 'Connection name is required.';
        }

        // host + username become an `ssh user@host` argv token at run time.
        // Even though we never build a shell string, a value beginning with '-'
        // (or carrying whitespace / shell metacharacters) could be parsed by
        // OpenSSH itself as an OPTION (e.g. -oProxyCommand=...) - an
        // option-injection vector. Reject unsafe values here (defence in depth
        // on top of the run-time argv construction).
        $host = trim((string) ($conn['host'] ?? ''));
        if ($host === '') {
            $errors[] = 'Host is required.';
        } elseif (!self::isSafeSshToken($host)) {
            $errors[] = 'Host contains unsafe characters or begins with "-".';
        }

        $username = trim((string) ($conn['username'] ?? ''));
        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (!self::isSafeSshToken($username)) {
            $errors[] = 'Username contains unsafe characters or begins with "-".';
        }

        $port = (int) ($conn['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $errors[] = 'Port must be between 1 and 65535.';
        }

        $auth = (string) ($conn['authMethod'] ?? '');
        if (!in_array($auth, self::AUTH_METHODS, true)) {
            $errors[] = 'Auth method must be KEY or PASSWORD.';
        }
        if (!in_array((string) ($conn['strictHostKey'] ?? ''), self::STRICT_HOST_KEY_MODES, true)) {
            $errors[] = 'Strict host key checking must be accept-new, yes or no.';
        }

        if ($auth === 'KEY') {
            $keyId = trim((string) ($conn['keyId'] ?? ''));
            if ($keyId === '') {
                $errors[] = 'Key-based connections must select an SSH key.';
            } elseif (self::findKey($creds, $keyId) === null) {
                $errors[] = 'The selected SSH key does not exist.';
            }
        }
        // PASSWORD: an empty password is permitted at save time (the user may
        // be editing other fields); testConnection surfaces an auth failure.

        return ['valid' => count($errors) === 0, 'errors' => $errors];
    }

    /**
     * True when a host/username string is safe to use as part of an ssh argv
     * token. Rejects a leading '-' (would be read as an option) and any
     * whitespace or shell metacharacters. PURE.
     */
    public static function isSafeSshToken(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || $value[0] === '-') {
            return false;
        }
        // Reject '@' explicitly: the SSH destination is built as "user@host", so
        // an '@' inside either component makes it ambiguous (user@evil@host) and
        // could connect to an unintended user/host.
        if (strpos($value, '@') !== false) {
            return false;
        }
        // No whitespace or shell metacharacters (defence in depth - we build
        // argv arrays, but ssh itself parses leading-dash tokens as options).
        return !preg_match('/[\s;&|`$()<>"\'\\\\]/', $value);
    }

    // --- lookups -----------------------------------------------------------

    /**
     * Find a key by id. Returns the merged key array or null.
     *
     * @param array<string,mixed> $creds a loaded/merged credentials structure
     * @return array<string,mixed>|null
     */
    public static function findKey(array $creds, string $id): ?array
    {
        foreach (($creds['keys'] ?? []) as $key) {
            if (is_array($key) && ($key['id'] ?? '') === $id) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Find a connection by id. Returns the merged connection array or null.
     *
     * @param array<string,mixed> $creds
     * @return array<string,mixed>|null
     */
    public static function findConnection(array $creds, string $id): ?array
    {
        foreach (($creds['connections'] ?? []) as $conn) {
            if (is_array($conn) && ($conn['id'] ?? '') === $id) {
                return $conn;
            }
        }
        return null;
    }

    // --- id / uniqueness helpers ------------------------------------------

    /**
     * Generate a stable, slugified id from a name with the given prefix
     * ('k-' for keys, 'c-' for connections), ensuring uniqueness against an
     * existing id set. Falls back to a random suffix when the name yields
     * nothing usable.
     *
     * @param array<int,string> $existingIds
     */
    public static function generateId(string $name, string $prefix, array $existingIds = []): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            $slug = bin2hex(random_bytes(4));
        }
        $base = $prefix . $slug;
        $id   = $base;
        $n    = 2;
        $set  = array_flip($existingIds);
        while (isset($set[$id])) {
            $id = $base . '-' . $n;
            $n++;
        }
        return $id;
    }

    // --- referential integrity (used_by) -----------------------------------

    /**
     * Report what depends on a credential object so the UI/handler can enforce
     * the TrueNAS used_by pattern on delete.
     *
     * For a KEY ($type='key'): returns the NAMES of connections referencing it
     * (deletion must be blocked while non-empty).
     * For a CONNECTION ($type='connection'): returns the ids+names of jobs in
     * the supplied config that reference it (deletion disables those jobs).
     *
     * @param array<string,mixed>      $creds  loaded credentials structure
     * @param string                   $type   'key' | 'connection'
     * @param string                   $id     the key/connection id
     * @param array<string,mixed>|null $config a loaded config structure (only
     *                                          needed for 'connection')
     * @return array{
     *   connections?: array<int,array{id:string,name:string}>,
     *   jobs?: array<int,array{id:string,name:string}>
     * }
     */
    public static function usedBy(array $creds, string $type, string $id, ?array $config = null): array
    {
        if ($type === 'key') {
            $deps = [];
            foreach (($creds['connections'] ?? []) as $conn) {
                if (!is_array($conn)) {
                    continue;
                }
                // Only KEY-auth connections actually consume a key.
                $authMethod = (string) ($conn['authMethod'] ?? '');
                if ($authMethod === 'KEY' && (string) ($conn['keyId'] ?? '') === $id) {
                    $deps[] = [
                        'id'   => (string) ($conn['id'] ?? ''),
                        'name' => (string) ($conn['name'] ?? ''),
                    ];
                }
            }
            return ['connections' => $deps];
        }

        if ($type === 'connection') {
            $deps = [];
            // $config is nullable: a null/absent config means "no jobs to
            // depend on", not an error.
            $jobs = (is_array($config) && isset($config['jobs']) && is_array($config['jobs']))
                ? $config['jobs']
                : [];
            if (is_array($jobs)) {
                foreach ($jobs as $job) {
                    if (!is_array($job)) {
                        continue;
                    }
                    if ((string) ($job['connectionId'] ?? '') === $id) {
                        $deps[] = [
                            'id'   => (string) ($job['id'] ?? ''),
                            'name' => (string) ($job['name'] ?? ''),
                        ];
                    }
                }
            }
            return ['jobs' => $deps];
        }

        return [];
    }

    // --- password obfuscation (reversible; NOT encryption) ------------------

    /**
     * Obfuscate a plaintext password for storage. The output is base64 of the
     * XOR of the UTF-8 bytes against a repeating fixed pad. This is reversible
     * and provides NO confidentiality against anyone with the source / flash
     * access - it exists only so the password is not stored in literal plain
     * text. The UI warns the user; a dedicated low-privilege remote account is
     * recommended. An empty string maps to an empty string (no obfuscation
     * marker) so "not set" round-trips cleanly.
     */
    public static function obfuscate(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        return base64_encode(self::xorPad($plain));
    }

    /**
     * Reverse obfuscate(). Returns the plaintext password. A value that is not
     * valid base64 (e.g. hand-edited) yields an empty string rather than
     * throwing, so a corrupt field degrades to "no password" instead of
     * breaking a save/test.
     */
    public static function deobfuscate(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        $bytes = base64_decode($stored, true);
        if ($bytes === false) {
            return '';
        }
        return self::xorPad($bytes);
    }

    /** XOR a byte string against the repeating obfuscation pad. */
    private static function xorPad(string $data): string
    {
        $pad    = self::OBFUSCATION_PAD;
        $padLen = strlen($pad);
        $out    = '';
        $len    = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $out .= $data[$i] ^ $pad[$i % $padLen];
        }
        return $out;
    }
}

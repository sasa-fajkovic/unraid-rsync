<?php

declare(strict_types=1);

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
 *     { id, name, transport, host, port, username, authMethod, keyId,
 *       keyFilePath, password, remoteHostKey, strictHostKey, connectTimeout }
 *     - transport SSH (the default) builds an `ssh` command line; DAEMON speaks
 *       the rsync daemon (rsyncd) wire protocol straight to a TCP port (873) and
 *       ignores every SSH-only field below - see CONN_TRANSPORTS.
 *     - authMethod KEYFILE points at an EXISTING private key file already on
 *       this Unraid system (keyFilePath, e.g. /root/.ssh/id_ed25519). The plugin
 *       NEVER reads, copies, uploads or stores the key material - OpenSSH reads
 *       the file directly via `ssh -i`. This is the common Unraid case and the
 *       default for new connections.
 *     - authMethod KEY references a managed (generated/imported) key by id
 *       (keyId) stored in this file; PASSWORD stores an OBFUSCATED password
 *       (reversible, NOT encryption - documented as recoverable by anyone with
 *       flash access).
 *
 * Referential integrity (a used_by pattern) is enforced by the
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
require_once __DIR__ . '/Logger.php';

class Credentials
{
    /** Current schema version this code writes. */
    const SCHEMA_VERSION = 1;

    /**
     * Allowed connection transports. SSH is the default and the only one that
     * builds an ssh command line. DAEMON speaks the rsync daemon (rsyncd) wire
     * protocol directly on a TCP port (873 by default).
     *
     * This is deliberately NOT a fourth AUTH_METHODS value: Ssh::materialize's
     * PASSWORD arm is the else FALL-THROUGH, so a fourth auth method would
     * silently materialise an SSH_ASKPASS passfile and an `ssh` -e string for a
     * daemon connection - and rsync does not reject an `-e` beside a
     * host::module operand, it quietly switches to daemon-over-remote-shell.
     */
    const CONN_TRANSPORTS = ['SSH', 'DAEMON'];

    /**
     * Allowed connection auth methods.
     *   KEYFILE  - use an existing private key file already on this system
     *              (keyFilePath); nothing is read/copied/stored by the plugin.
     *   KEY      - use a managed (generated/imported) key stored in this file.
     *   PASSWORD - use an obfuscated stored password.
     */
    const AUTH_METHODS = ['KEYFILE', 'KEY', 'PASSWORD'];

    /**
     * The default key file path offered for a new KEYFILE connection. This is
     * the conventional location of root's ed25519 key on Unraid; most users who
     * already set up SSH have their key here.
     */
    const DEFAULT_KEY_FILE_PATH = '/root/.ssh/id_ed25519';

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
     * Optional override for the directory that holds credentials.json. When null
     * (the default) credentials.json lives on /boot beside config.json
     * (UR_CONFIG_BASE). When set to an array/pool path under /mnt (validated by
     * Config::sanitizeSecretsDir), credentials.json is read/written there instead,
     * so it gets real chmod 600 perms rather than world-readable FAT32.
     *
     * This class stays decoupled from Config (mirroring Logger::$logsDirOverride):
     * the callers that have already loaded config (the Runner run path and the
     * handler request dispatcher) PUSH the validated path in, so path() never has
     * to read config itself and unit tests can set it directly.
     *
     * @var string|null
     */
    public static ?string $secretsDirOverride = null;

    /**
     * Absolute path to credentials.json. Lives under $secretsDirOverride when set
     * (an /mnt array/pool path), else beside config.json on /boot (UR_CONFIG_BASE).
     */
    public static function path(): string
    {
        $base = (self::$secretsDirOverride !== null && self::$secretsDirOverride !== '')
            ? self::$secretsDirOverride
            : UR_CONFIG_BASE;
        return rtrim($base, '/') . '/credentials.json';
    }

    /**
     * Where credentials.json lives now, for the Global Settings status line:
     * its path, its real mode ('' while it does not exist yet) and whether it
     * is on the USB flash (no secretsDir).
     *
     * @return array{path:string,mode:string,onFlash:bool}
     */
    public static function location(): array
    {
        $path = self::path();
        clearstatcache(true, $path);
        return [
            'path'    => $path,
            'mode'    => is_file($path) ? sprintf('%04o', fileperms($path) & 0777) : '',
            'onFlash' => self::$secretsDirOverride === null || self::$secretsDirOverride === '',
        ];
    }

    /**
     * Run $fn holding the exclusive credentials lock. Every load-modify-save of
     * credentials.json goes through it (handler saves, Ssh::pinHostKey, the
     * secretsDir move). The lock stays beside config.json whatever secretsDir
     * says, so a relocation and every writer contend for the same file. A move
     * a crash interrupted is settled first (reconcilePendingMove), before $fn
     * can change the live file.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function withLock(callable $fn): mixed
    {
        return Util::withLock(rtrim(UR_CONFIG_BASE, '/') . '/credentials.lock', static function () use ($fn) {
            self::reconcilePendingMove();
            return $fn();
        });
    }

    /** The record of a secretsDir move in progress; beside config.json, like the lock. */
    public static function movePath(): string
    {
        return rtrim(UR_CONFIG_BASE, '/') . '/credentials.move.json';
    }

    /**
     * Record a move of credentials.json from $sourceDir to $destinationDir whose
     * verified copy hashes to $sha256. Written BEFORE the new pointer is saved,
     * so whatever step a crash interrupts, the next writer knows what to finish.
     *
     * @throws RuntimeException when the record cannot be written.
     */
    public static function recordMove(string $sourceDir, string $destinationDir, string $sha256): void
    {
        self::writeMove([
            'source'      => rtrim($sourceDir, '/'),
            'destination' => rtrim($destinationDir, '/'),
            'sha256'      => $sha256,
            'startedAt'   => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Settle a move a crash interrupted, by where config.json now points. Runs at
     * the start of every withLock() scope; returns a warning, or '' when there
     * was nothing to do or it is done.
     *   - at the destination: the move committed; finish it (completeMove).
     *   - still at the source: it never committed; drop the record and the copy
     *     it left at the destination.
     *   - anywhere else: leave everything, log once.
     */
    public static function reconcilePendingMove(): string
    {
        $rec = self::readMove();
        if ($rec === null) {
            return '';
        }
        try {
            $dir = Config::sanitizeSecretsDir(Config::load()['global']['secretsDir'] ?? '');
        } catch (Throwable $e) {
            return '';
        }
        $current = ($dir !== '') ? $dir : rtrim(UR_CONFIG_BASE, '/');
        if ($current === $rec['destination']) {
            return self::completeMove();
        }
        if ($current === $rec['source']) {
            // A pointer save the filesystem rolled back after the source was
            // already unlinked leaves the destination as the only copy.
            if (@hash_file('sha256', $rec['source'] . '/credentials.json') !== $rec['sha256']) {
                return self::stuckMove($rec, 'secretsDir points back at the source, but its credentials.json is gone or has changed');
            }
            $copy = $rec['destination'] . '/credentials.json';
            if (@hash_file('sha256', $copy) === $rec['sha256']) {
                @unlink($copy);
            }
            @unlink(self::movePath());
            return '';
        }
        return self::stuckMove($rec, 'secretsDir now points at neither end of it');
    }

    /**
     * Finish the recorded move once the new pointer is saved: remove the source's
     * .last-good, then the source itself, then the record. Only while the moved
     * file still hashes to the record (no writer reached it unsettled) and the
     * source is the copy that was moved; otherwise everything stays and the
     * mismatch is logged once. Returns a warning, or '' when done.
     */
    public static function completeMove(): string
    {
        $rec = self::readMove();
        if ($rec === null) {
            return '';
        }
        $src = $rec['source'] . '/credentials.json';
        if (@hash_file('sha256', $rec['destination'] . '/credentials.json') !== $rec['sha256']) {
            return self::stuckMove($rec, 'the moved credentials.json has changed since');
        }
        if (is_file($src) && @hash_file('sha256', $src) !== $rec['sha256']) {
            return self::stuckMove($rec, 'the old credentials.json has changed since');
        }
        foreach ([$src . '.last-good', $src] as $file) {
            if (is_file($file) && !@unlink($file)) {
                return self::stuckMove($rec, $file . ' could not be removed - delete it manually');
            }
        }
        @unlink(self::movePath());
        return '';
    }

    /** @return array{source:string,destination:string,sha256:string,startedAt?:string,logged?:bool}|null */
    private static function readMove(): ?array
    {
        $path = self::movePath();
        $rec  = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        if (!is_array($rec) || !is_string($rec['source'] ?? null) || !is_string($rec['destination'] ?? null)
            || !is_string($rec['sha256'] ?? null)) {
            return null;
        }
        return $rec;
    }

    /**
     * @param array<string,mixed> $rec
     */
    private static function writeMove(array $rec): void
    {
        Util::writeAtomic(self::movePath(), json_encode($rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
    }

    /**
     * A move that cannot be settled: keep the record (and both files), log it
     * once, and return the message as a warning.
     *
     * @param array{source:string,destination:string,sha256:string,startedAt?:string,logged?:bool} $rec
     */
    private static function stuckMove(array $rec, string $why): string
    {
        $msg = 'The move of credentials.json from ' . $rec['source'] . ' to ' . $rec['destination']
            . ' could not be finished: ' . $why . '. Check both copies, then delete ' . self::movePath() . '.';
        if (($rec['logged'] ?? false) !== true) {
            try {
                Logger::event('', 'credentials', $msg);
                $rec['logged'] = true;
                self::writeMove($rec);
            } catch (Throwable $e) {
            }
        }
        return $msg;
    }

    /**
     * Re-point $secretsDirOverride at config.json's secretsDir. For a writer
     * holding withLock() whose pointer was resolved earlier (a request's
     * dispatcher, a run's start): a relocation may have moved the file since,
     * and a write to the old path would start a second file. Reads config
     * WITHOUT its lock - the lock order is config, then credentials. An
     * unreadable config keeps the current value.
     */
    public static function refreshSecretsDir(): void
    {
        try {
            $dir = Config::sanitizeSecretsDir(Config::load()['global']['secretsDir'] ?? '');
        } catch (Throwable $e) {
            return;
        }
        self::$secretsDirOverride = ($dir !== '') ? $dir : null;
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
            // SSH for new connections AND for every pre-existing one:
            // mergeConnection() backfills a missing/unknown value from here,
            // which is what makes this field additive WITHIN schema v1 - no
            // version bump, no migration, exactly like keyFilePath (see
            // migrate()). Bumping the schema would make an OLDER plugin's
            // migrate() throw and take every existing connection down with it.
            'transport'      => 'SSH',
            'host'           => '',
            'port'           => 22,
            'username'       => '',
            // KEYFILE is the default for NEW connections: it's the common Unraid
            // case (the user already has /root/.ssh/id_ed25519 and has copied the
            // matching public key to the remote's authorized_keys).
            'authMethod'     => 'KEYFILE',
            'keyId'          => '',
            'keyFilePath'    => self::DEFAULT_KEY_FILE_PATH,
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
     *   - File absent                      -> the default (empty) keychain.
     *   - File present but unreadable      -> throws RuntimeException.
     *   - File present but empty, whitespace-only or malformed JSON -> throws
     *     RuntimeException (a truncated file is not an empty keychain).
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
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException(trim($raw) === ''
                ? "credentials.json is empty: $path"
                : 'credentials.json is not valid JSON');
        }

        $data = self::migrate($data);
        return self::mergeDefaults($data);
    }

    /**
     * Persist the given credentials structure atomically (Util::writeAtomic:
     * same-dir temp file, fsync, rename). The schemaVersion is always stamped to
     * the current version and the shape is normalised so on-disk data is always
     * complete. The file being replaced is first kept as
     * credentials.json.last-good, when it still parses and differs.
     *
     * @param array<string,mixed> $creds
     * @throws RuntimeException on any filesystem failure.
     */
    public static function save(array $creds): void
    {
        $creds['schemaVersion'] = self::SCHEMA_VERSION;
        $creds = self::mergeDefaults($creds);

        $path = self::path();
        Util::mkdirs(dirname($path), 0700);

        $json = json_encode($creds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode credentials as JSON: ' . json_last_error_msg());
        }
        $json .= "\n";

        // 0600 is a no-op on the default FAT32 /boot (the documented reason
        // secrets are obfuscation-only and keys are materialised to tmpfs 600
        // before use); on an /mnt secretsDir it STICKS and is the real at-rest
        // protection for credentials.json.
        $current = is_file($path) ? @file_get_contents($path) : false;
        if (is_string($current) && $current !== $json && is_array(json_decode($current, true))) {
            Util::writeAtomic($path . '.last-good', $current, 0600);
        }
        Util::writeAtomic($path, $json, 0600);
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

        // The KEYFILE auth method + keyFilePath field were added WITHIN schema v1
        // as a purely additive, backward-compatible change (no version bump). No
        // explicit transform is needed: mergeConnection() fills a missing
        // keyFilePath from the default and leaves any existing KEY/PASSWORD
        // authMethod untouched, so old connections load unchanged.

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
        // rsync splits "user@host:path" at the first ':', so a bare IPv6 literal
        // retargets the transfer; the bracketed form is the one it parses.
        if (Util::isIpv6Address($out['host'])) {
            $out['host'] = '[' . $out['host'] . ']';
        }
        $out['username'] = isset($conn['username']) ? trim((string) $conn['username']) : $defaults['username'];
        $out['keyId']    = isset($conn['keyId'])    ? trim((string) $conn['keyId'])    : $defaults['keyId'];
        // keyFilePath: a path to an existing key file (KEYFILE auth). Trimmed
        // (whitespace-clean) so the run-time `ssh -i <path>` token is sane. An
        // existing connection with no keyFilePath (pre-KEYFILE on-disk data)
        // falls back to the default path - safe, since the field is only USED
        // when authMethod is KEYFILE (existing KEY/PASSWORD connections ignore it).
        $out['keyFilePath'] = isset($conn['keyFilePath']) ? trim((string) $conn['keyFilePath']) : $defaults['keyFilePath'];
        $out['password'] = isset($conn['password']) ? (string) $conn['password'] : $defaults['password'];
        $out['remoteHostKey'] = isset($conn['remoteHostKey']) ? (string) $conn['remoteHostKey'] : $defaults['remoteHostKey'];

        // Clamp the transport FIRST: the port default below reads the RESOLVED
        // value, so this ordering is load-bearing. Reversed, a daemon connection
        // would silently default to 22 and rsync would speak the rsyncd protocol
        // at sshd. An unknown/absent value backfills to SSH.
        $transport = strtoupper(trim((string) ($conn['transport'] ?? $defaults['transport'])));
        $out['transport'] = in_array($transport, self::CONN_TRANSPORTS, true) ? $transport : $defaults['transport'];

        // The port default follows the transport (873 for rsyncd, 22 for SSH),
        // so a card whose port was never touched lands on the right one when the
        // transport is switched. An explicitly stored port always wins.
        $defaultPort = ($out['transport'] === 'DAEMON') ? self::RSYNCD_PORT : $defaults['port'];
        $port = isset($conn['port']) ? (int) $conn['port'] : $defaultPort;
        $out['port'] = ($port >= 1 && $port <= 65535) ? $port : $defaultPort;

        $auth = strtoupper(trim((string) ($conn['authMethod'] ?? $defaults['authMethod'])));
        $out['authMethod'] = in_array($auth, self::AUTH_METHODS, true) ? $auth : $defaults['authMethod'];

        $mode = strtolower(trim((string) ($conn['strictHostKey'] ?? $defaults['strictHostKey'])));
        $out['strictHostKey'] = in_array($mode, self::STRICT_HOST_KEY_MODES, true) ? $mode : $defaults['strictHostKey'];

        $timeout = isset($conn['connectTimeout']) ? (int) $conn['connectTimeout'] : $defaults['connectTimeout'];
        $out['connectTimeout'] = ($timeout >= 1 && $timeout <= 600) ? $timeout : $defaults['connectTimeout'];

        // Snapshot mode's sudo on the receiver: additive within schema 1 like
        // transport, and written only when on so existing files round-trip.
        if (($conn['useSudo'] ?? null) === true && $out['transport'] === 'SSH') {
            $out['useSudo'] = true;
        }

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
     * Longest usable rsync daemon module secret, in bytes. rsync's getpassf()
     * reads the password file with `read(fd, buffer, sizeof buffer - 1)` into a
     * `char buffer[512]` (authenticate.c:204), so byte 512 onwards is discarded
     * without a word - the run just fails to authenticate.
     */
    const DAEMON_SECRET_MAX_BYTES = 511;

    /** The TCP port the rsync DAEMON (rsyncd) listens on. Not an SSH port. */
    public const RSYNCD_PORT = 873;

    /**
     * A warning when a connection's host/port look inconsistent with its
     * TRANSPORT, or '' when they look fine.
     *
     * WHY this exists: NAS appliances ship an "Rsync Server" toggle (QNAP HBS 3,
     * Synology) that enables rsyncd on 873 - a DIFFERENT protocol from
     * rsync-over-SSH. Port 873 passes the 1-65535 check and silently becomes
     * `ssh -p 873`, so the daemon accepts the TCP connection, fails to speak SSH
     * and drops it. The user then sees an opaque "not running SSH / connection
     * closed" much later, at Discover-host-key or first run, with nothing linking
     * it back to the port they typed. Reported on the support forum; this is
     * advisory only, because running sshd on 873 is unusual but perfectly legal.
     *
     * For DAEMON transport the port test is INVERTED rather than dropped: a
     * daemon connection still sitting on 22 (the SSH default it was created with)
     * is now the likeliest misconfiguration. $transport defaults to 'SSH' so
     * every pre-daemon call site keeps its exact behaviour, and any unknown
     * value takes the SSH arm.
     */
    public static function rsyncDaemonNote(int $port, string $host = '', string $transport = 'SSH'): string
    {
        $h = strtolower(trim($host));
        // "rsync://host/module" or the daemon's "host::module" double-colon form
        // pasted into the Host field - wrong on BOTH transports, because the
        // module belongs on the job's pair, not here. An IPv6 literal is FULL of
        // colons ("fe80::1", "::1", "[2001:db8::1]") and is a perfectly good SSH
        // host, so it must be excluded before the double-colon test - otherwise
        // every IPv6 user gets this warning.
        $daemonShaped = strpos($h, 'rsync://') === 0
            || (!self::isIpv6Literal($h) && strpos($h, '::') !== false);

        if (strtoupper(trim($transport)) === 'DAEMON') {
            if ($daemonShaped) {
                return 'This looks like a full rsync daemon address (rsync:// or host::module). '
                    . 'Enter just the hostname or IP here; the module goes in the job\'s pair.';
            }
            if ($port !== self::RSYNCD_PORT) {
                return 'Port ' . $port . ' is unusual for an rsync daemon; rsyncd normally listens on 873.';
            }
            return '';
        }

        if ($daemonShaped) {
            return 'This looks like an rsync daemon address (rsync:// or host::module). '
                . 'This Connection uses SSH transport, so enter just the hostname or IP here '
                . 'and put the remote path on the job\'s pair. To use an rsync daemon instead, '
                . 'set this Connection\'s Transport to "rsync daemon (rsyncd)".';
        }
        if ($port === self::RSYNCD_PORT) {
            return 'Port 873 is the rsync daemon (rsyncd) port, which is a different protocol '
                . 'from rsync-over-SSH. Either enable SSH on the remote host and use its SSH port '
                . '(usually 22), or set this Connection\'s Transport to "rsync daemon (rsyncd)".';
        }
        return '';
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

        // findConnection() hands back the RAW on-disk record and a pre-daemon
        // credentials.json has no transport key at all, so the ?? 'SSH' backfill
        // is mandatory here even though a merged connection always carries one.
        $transport = strtoupper(trim((string) ($conn['transport'] ?? 'SSH')));

        if (trim((string) ($conn['name'] ?? '')) === '') {
            $errors[] = 'Connection name is required.';
        }

        // host + username become an `ssh user@host` argv token at run time.
        // Even though we never build a shell string, a value beginning with '-'
        // (or carrying whitespace / shell metacharacters) could be parsed by
        // OpenSSH itself as an OPTION (e.g. -oProxyCommand=...) - an
        // option-injection vector. Reject unsafe values here (defence in depth
        // on top of the run-time argv construction). A DAEMON connection builds
        // "user@host::module" instead, which rsync itself re-parses - hence the
        // STRICTER charset; see isSafeDaemonToken for why ':' and '/' are fatal.
        $host = trim((string) ($conn['host'] ?? ''));
        if ($host === '') {
            $errors[] = 'Host is required.';
        } elseif ($transport === 'DAEMON') {
            if (self::isIpv6Literal($host)) {
                // A bare IPv6 literal cannot be concatenated into "host::module"
                // (its own colons collide with the module separator) and the
                // bracketed form is rejected by isSafeDaemonToken. Say so plainly
                // instead of emitting the generic charset message.
                $errors[] = 'IPv6 daemon hosts are not supported yet. Use the host name or an IPv4 '
                    . 'address for an rsync daemon Connection.';
            } elseif (!self::isSafeDaemonToken($host)) {
                $errors[] = 'Host is not valid for an rsync daemon Connection: it must not contain '
                    . '":", "/", "?", "#", "[" or "]", must not begin with "-", and must not contain '
                    . 'whitespace or shell characters. rsync would silently reinterpret such a value '
                    . 'as an SSH target or as a local path.';
            }
        } elseif (!self::isSafeSshHost($host)) {
            $errors[] = self::SSH_HOST_RULE;
        }

        // A username stays REQUIRED for DAEMON: rsyncd's auth_server() returns
        // immediately for a module with no `auth users`, so a supplied username
        // is simply unused there - requiring it costs nothing and keeps the
        // operand builder free of an empty-"@"-prefix edge case.
        $username = trim((string) ($conn['username'] ?? ''));
        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif ($transport === 'DAEMON') {
            if (!self::isSafeDaemonToken($username)) {
                $errors[] = 'Username is not valid for an rsync daemon Connection: it must not contain '
                    . '":", "/", "?", "#", "[" or "]", must not begin with "-", and must not contain '
                    . 'whitespace or shell characters. rsync would silently reinterpret such a value '
                    . 'as an SSH target or as a local path.';
            }
        } elseif (!self::isSafeSshUser($username)) {
            $errors[] = self::SSH_USER_RULE;
        }

        $port = (int) ($conn['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $errors[] = 'Port must be between 1 and 65535.';
        }

        if ($transport === 'DAEMON') {
            // No authMethod, strictHostKey or key rules here: those fields are
            // SSH-only and the daemon card hides them, but a hidden <select>
            // still POSTs its value - rejecting a daemon connection for, say, a
            // missing key file would make every one of them unsaveable.
            //
            // The module secret is OPTIONAL: anonymous modules (no `auth users`)
            // are legal, and Ssh::materializeDaemon writes no password file at
            // all for an empty secret. Both rules below test the DEOBFUSCATED
            // value, because that is the byte string that reaches the password
            // file. They cover every value the UI writes, which is what they are
            // for. They do NOT also catch a hand-edited credentials.json, despite
            // what an earlier comment here claimed: a hand-editor writes
            // PLAINTEXT, which deobfuscate() XOR-decodes into unrelated bytes
            // that sail past both rules. Ssh::materializeDaemon re-checks the
            // line-break rule at run time; that is the hand-edit guard.
            $stored = (string) ($conn['password'] ?? '');
            $secret = $stored !== '' ? self::deobfuscate($stored) : '';

            // rsync's getpassf() strtok()s the password file at the first \n or
            // \r (authenticate.c:212), so everything after one is silently
            // discarded and the user gets an auth failure with no clue why.
            if (preg_match('/[\x00\r\n]/', $secret)) {
                $errors[] = 'The module secret must not contain line breaks: rsync reads only the first '
                    . 'line of the password file, so everything after it would be silently discarded. '
                    . 'Type a new secret into this Connection to replace the stored one - leaving the '
                    . 'field blank keeps it.';
            } elseif (strlen($secret) > self::DAEMON_SECRET_MAX_BYTES) {
                // getpassf() reads into `char buffer[512]` with
                // `read(fd, buffer, sizeof buffer - 1)` (authenticate.c:204), so
                // byte 512 onwards never exists as far as rsync is concerned. A
                // longer secret authenticates with a silent prefix and the run
                // dies with an unexplained "auth failed on module".
                $errors[] = 'The module secret must be ' . self::DAEMON_SECRET_MAX_BYTES . ' bytes or shorter: '
                    . 'rsync reads at most that much of the password file, so a longer secret is silently '
                    . 'truncated and authentication fails with no explanation. Type a shorter secret into '
                    . 'this Connection to replace the stored one - leaving the field blank keeps it.';
            }

            return ['valid' => count($errors) === 0, 'errors' => $errors];
        }

        $auth = (string) ($conn['authMethod'] ?? '');
        if (!in_array($auth, self::AUTH_METHODS, true)) {
            $errors[] = 'Auth method must be KEYFILE, KEY or PASSWORD.';
        }
        if (!in_array((string) ($conn['strictHostKey'] ?? ''), self::STRICT_HOST_KEY_MODES, true)) {
            $errors[] = 'Strict host key checking must be accept-new, yes or no.';
        }

        if ($auth === 'KEYFILE') {
            // An existing key file already on this system. We require a non-empty,
            // ABSOLUTE, option-injection-safe path. We deliberately do NOT require
            // the file to EXIST at save time (the user may create/persist it
            // later); existence + readability are checked at RUN time (Ssh /
            // Runner) with a clear error there.
            $keyFilePath = trim((string) ($conn['keyFilePath'] ?? ''));
            if ($keyFilePath === '') {
                $errors[] = 'Existing-key-file connections require a key file path.';
            } elseif (!self::isSafeKeyFilePath($keyFilePath)) {
                $errors[] = 'The key file path must be an absolute path (starting with "/") '
                    . 'and must not contain unsafe characters.';
            }
        } elseif ($auth === 'KEY') {
            $keyId = trim((string) ($conn['keyId'] ?? ''));
            if ($keyId === '') {
                $errors[] = 'Key-based connections must select an SSH key.';
            } elseif (self::findKey($creds, $keyId) === null) {
                $errors[] = 'The selected SSH key does not exist.';
            }
        } elseif ($auth === 'PASSWORD') {
            // A PASSWORD connection needs a password to authenticate with - an
            // empty one would always fail at run time, so reject it on save.
            // The stored password is OBFUSCATED, never trimmed (a password may
            // legitimately contain leading/trailing whitespace), so we test the
            // raw stored value for emptiness only.
            if ((string) ($conn['password'] ?? '') === '') {
                $errors[] = 'Password-based connections require a password.';
            }
        }

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
        // This legacy shared-token check remains conservative for daemon operands.
        // SSH usernames use isSafeSshUser(), which permits email-style names.
        if (strpos($value, '@') !== false) {
            return false;
        }
        // No whitespace or shell metacharacters (defence in depth - we build
        // argv arrays, but ssh itself parses leading-dash tokens as options).
        return !preg_match('/[\s;&|`$()<>"\'\\\\]/', $value);
    }

    const SSH_HOST_RULE = 'Host must be a host name or IPv4 address (letters, digits, ".", "-" and "_", '
        . 'not beginning with "-") or an IPv6 address.';
    const SSH_USER_RULE = 'Username must start with a letter, digit or "_", and may contain only letters, digits, ".", "-", "_", "@" and "+".';

    /**
     * True when $value is safe as the host of an SSH "user@host:path" operand: a
     * host name / IPv4 address, or a BRACKETED IPv6 literal (a %scope included). rsync splits the
     * operand at the first ':' (outside brackets), so a ':' turns the rest into
     * the path - "fe80::1:/x" connects to host "fe80" - and a '/' makes the whole
     * operand a LOCAL path. PURE.
     */
    public static function isSafeSshHost(string $value): bool
    {
        $value = trim($value);
        if (preg_match('/^\[([^\]]+)\]$/D', $value, $m)) {
            return Util::isIpv6Address($m[1]);
        }
        return (bool) preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/D', $value);
    }

    /**
     * True when $value is safe as the user of an SSH "user@host:path" operand:
     * a conventional account or email-style name. rsync and OpenSSH split at the
     * last '@'; ':' and '/' still change rsync's operand interpretation. PURE.
     */
    public static function isSafeSshUser(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.@+-]*$/D', trim($value));
    }

    /**
     * True when a host/username string is safe as the "[user@]host" part of an
     * rsync DAEMON operand "[user@]host::module". STRICTER than isSafeSshToken().
     *
     * rsync's own parse_hostspec() breaks the authority at the FIRST ':' or '/'.
     * A username "a:b" makes "a:b@nas::mod" resolve to an SSH connection to host
     * "a" over the DEFAULT remote shell - no pinned host key, no key, no port. A
     * '/' makes parse_hostspec return NULL and rsync treats the whole operand as
     * a LOCAL path, so a "backup" would quietly copy into a directory on this
     * box. '?', '#', '[' and ']' are rejected too so a URL-ish or
     * IPv6-bracketed paste can never reach the operand. PURE.
     */
    public static function isSafeDaemonToken(string $value): bool
    {
        if (!self::isSafeSshToken($value)) {
            return false;
        }
        return !preg_match('/[:\/?#\[\]]/', trim($value));
    }

    /** True when $host is an IPv6 literal, bracketed or bare. PURE. */
    private static function isIpv6Literal(string $host): bool
    {
        return Util::isIpv6Address(trim(strtolower(trim($host)), '[]'));
    }

    /**
     * True when a key file path is safe to hand to `ssh -i <path>`. It must be an
     * ABSOLUTE path (begins with '/') so it can never be read as an ssh option
     * (a leading '-' would be), and must contain no whitespace, shell
     * metacharacters, NUL/control bytes, or a ".." traversal segment. PURE.
     *
     * We build argv arrays (no shell), but this is defence in depth: a path that
     * begins with '-' or carries metacharacters could otherwise be mis-parsed by
     * OpenSSH itself or surprise a future caller that joins it into a string.
     */
    public static function isSafeKeyFilePath(string $value): bool
    {
        $value = trim($value);
        // Must be a non-empty ABSOLUTE path. An absolute path can never start
        // with '-', so this also closes the option-injection vector.
        if ($value === '' || $value[0] !== '/') {
            return false;
        }
        // Reject NUL / control bytes outright.
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return false;
        }
        // No whitespace or shell metacharacters.
        if (preg_match('/[\s;&|`$()<>"\'\\\\]/', $value)) {
            return false;
        }
        // Reject any ".." path segment (a normalised-path guard; "/root/../etc"
        // style traversal has no legitimate use for a configured key path).
        foreach (explode('/', $value) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        return true;
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
     * the used_by pattern on delete.
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

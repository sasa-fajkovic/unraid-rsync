<?php

declare(strict_types=1);

/**
 * Ssh.php - the SSH transport helper for the Unraid Rsync plugin.
 *
 * Two responsibilities, both shared with Phase 4 (Rsync.php) and the
 * Credentials tab's "Test connection" button:
 *
 *   1. Build the ssh / sshpass invocation as an ARGV ARRAY (never a shell
 *      string). rsync consumes the ssh transport via its `-e` option, whose
 *      VALUE is itself a small command line; we expose both the argv pieces
 *      (for proc_open without a shell) and the joined `-e` string value so
 *      Phase 4 can compose `rsync ... -e "<value>" ...` exactly.
 *
 *   2. Materialise secrets to a tmpfs runtime dir with tight permissions
 *      immediately before use. credentials.json lives on FAT32 /boot (every
 *      file world-readable) and OpenSSH REFUSES a world-readable private key, so
 *      we copy the referenced key to /tmp/unraid.rsync/keys/<token> (dir 700,
 *      key 600), where <token> is a UNIQUE PER-RUN token (newRuntimeToken():
 *      connId + pid + random). Keying by a per-run token - not the connection or
 *      key id - means two concurrent runs, even of the SAME connection, get
 *      separate files, so one run's cleanupRuntime(token) never removes a
 *      key/known_hosts another run is still using. We likewise write the
 *      connection's pinned remoteHostKey to a per-run known_hosts file.
 *      cleanupRuntime(token) removes that run's files again.
 *
 * AUTH METHODS
 *   KEYFILE:
 *     ssh -i <keyFilePath> -o IdentitiesOnly=yes -o BatchMode=yes
 *         -o StrictHostKeyChecking=<mode> -o UserKnownHostsFile=<kh>
 *         -o ConnectTimeout=<n> -p <port>
 *     The key file ALREADY lives on the system (e.g. /root/.ssh/id_ed25519) with
 *     the user's own permissions; OpenSSH reads it directly. We do NOT read,
 *     copy, materialise to tmpfs, or store its contents - we only pass the path.
 *   KEY:
 *     ssh -i <tmpkey> -o IdentitiesOnly=yes -o BatchMode=yes
 *         -o StrictHostKeyChecking=<mode> -o UserKnownHostsFile=<kh>
 *         -o ConnectTimeout=<n> -p <port>
 *   PASSWORD:
 *     sshpass -f <tmp-passfile>   (PREFIX, prepended to the rsync/ssh argv)
 *       wrapping
 *     ssh -o PubkeyAuthentication=no -o PreferredAuthentications=password
 *         -o StrictHostKeyChecking=<mode> -o UserKnownHostsFile=<kh>
 *         -o ConnectTimeout=<n> -p <port>
 *     (NO BatchMode with sshpass - BatchMode disables the password prompt
 *      sshpass is there to answer.)
 *
 * SSHPASS AVAILABILITY (decision: detect-and-degrade)
 *   sshpass is NOT part of Unraid's base OS, and we deliberately DO NOT bundle a
 *   third-party binary we cannot independently build and verify (committing an
 *   unverified artifact + scraped checksum is the supply-chain risk the plan
 *   warns against). Instead we detect it at runtime via `command -v sshpass`:
 *   if present, password auth works; if absent, the Credentials UI and
 *   testConnection()/save surface a clear notice telling the user to install
 *   the NerdTools plugin and add sshpass, or to use key auth. KEY AUTH IS FULLY
 *   FUNCTIONAL REGARDLESS - it is the primary, tested path.
 *
 * TESTABILITY
 *   Every action that touches the live system - running ssh/ssh-keygen/
 *   ssh-keyscan, probing for sshpass, choosing the tmpfs base - goes through a
 *   small, overridable seam (the protected static run* / locate* methods, plus
 *   $runtimeBase / $sshpassPathOverride). Tests subclass Ssh, override those,
 *   and assert on the argv ARRAYS the pure builders return without ever opening
 *   a socket. The exit-code -> failure-mode mapping is likewise a pure function.
 */

require_once __DIR__ . '/Credentials.php';

class Ssh
{
    /**
     * tmpfs base for materialised secrets. RAM-backed, cleared on reboot. The
     * dir basename equals the plugin name to match the rest of the plugin's
     * runtime layout (/tmp/unraid.rsync/...). Overridable for tests.
     *
     * @var string
     */
    public static $runtimeBase = '/tmp/unraid.rsync';

    /**
     * Optional explicit sshpass path. When null, sshpassPath() probes PATH.
     * Tests set this (or set it to '' to simulate "not installed").
     *
     * @var string|null
     */
    public static $sshpassPathOverride = null;

    // ssh exit code that means "ssh itself failed to connect/authenticate"
    // (as opposed to a remote command's own non-zero exit).
    const SSH_EXIT_ERROR = 255;
    // sshpass-specific exit codes (man sshpass):
    const SSHPASS_INVALID_ARGS   = 1;
    const SSHPASS_CONFLICT       = 2;
    const SSHPASS_RUNTIME_ERROR  = 3;
    const SSHPASS_PARSE_ERROR    = 4;
    const SSHPASS_INCORRECT_PASS = 5;   // authentication failed
    const SSHPASS_HOSTKEY_UNKNOWN = 6;  // host public key is unknown
    const SSHPASS_HOSTKEY_CHANGED = 7;  // host public key has changed

    // --- runtime paths ------------------------------------------------------
    //
    // Every materialised secret is keyed by a unique PER-RUN TOKEN (connId +
    // pid + random), NOT by connection id alone. This is what makes concurrent
    // runs safe even when they share the SAME connection (a core feature of the
    // keychain): each run writes to its own keys/<token>, pass/<token> and
    // known_hosts/<token>, and cleanupRuntime(token) only ever unlinks that
    // run's own files - so one run's cleanup can never pull a key/known_hosts
    // out from under another in-flight ssh.

    /** tmpfs dir that holds materialised private keys (mode 700). */
    public static function keysDir(): string
    {
        return rtrim(static::$runtimeBase, '/') . '/keys';
    }

    /**
     * Generate a unique per-run token from a connection id. Includes the pid
     * and a random suffix so two concurrent runs of the same connection never
     * collide. The token is also a safe filename segment.
     */
    public static function newRuntimeToken(string $connId): string
    {
        return self::safeId($connId) . '-' . getmypid() . '-' . bin2hex(random_bytes(6));
    }

    /** Path a run's private key materialises to (mode 600), keyed by run token. */
    public static function keyPath(string $token): string
    {
        return static::keysDir() . '/' . self::safeId($token);
    }

    /** Path a run's known_hosts materialises to, keyed by run token. */
    public static function knownHostsPath(string $token): string
    {
        return rtrim(static::$runtimeBase, '/') . '/known_hosts/' . self::safeId($token);
    }

    /** Path a run's password file materialises to, keyed by run token. */
    public static function passFilePath(string $token): string
    {
        return rtrim(static::$runtimeBase, '/') . '/pass/' . self::safeId($token);
    }

    /**
     * Sanitise an id for use as a filename segment. ids are slug-shaped
     * (k-/c- + [a-z0-9-]) by construction, but defend against traversal anyway:
     * strip anything that isn't a safe filename char.
     */
    private static function safeId(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $id);
        // A pure-dots id ("." / "..") survives the char-class strip but is a
        // traversal segment, so collapse it to a literal. Mirrors
        // ur_safe_job_id's pure-dots rejection (defence-in-depth).
        if ($clean === '' || $clean === null || preg_match('/^\.+$/', $clean)) {
            return 'unknown';
        }
        return $clean;
    }

    // --- sshpass detection (detect-and-degrade) -----------------------------

    /**
     * Resolve the sshpass executable path, or '' when not installed. Honors the
     * test override. Live detection scans $PATH for an executable "sshpass"
     * WITHOUT a shell, via the locateSshpass() seam.
     */
    public static function sshpassPath(): string
    {
        if (static::$sshpassPathOverride !== null) {
            return (string) static::$sshpassPathOverride;
        }
        return static::locateSshpass();
    }

    /** True when password auth is usable on this box (sshpass present). */
    public static function sshpassAvailable(): bool
    {
        return static::sshpassPath() !== '';
    }

    /** The user-facing notice shown when password auth is requested but sshpass is missing. */
    public static function sshpassMissingMessage(): string
    {
        return 'Password authentication requires the "sshpass" command, which is not part of '
            . 'Unraid\'s base OS. Install the NerdTools plugin and enable its sshpass package, '
            . 'or use key authentication (recommended).';
    }

    // --- argv builders (PURE: no I/O, return argv ARRAYS) -------------------

    /**
     * Build the ssh argv array for a connection, given the ALREADY-MATERIALISED
     * tmpfs paths. This is the single source of truth for the ssh option set;
     * both the `-e` value and testConnection() are derived from it.
     *
     * KEYFILE auth uses -i <keyFilePath> (an existing on-system key file, passed
     * verbatim - NOT materialised) + IdentitiesOnly + BatchMode.
     * KEY auth uses -i <tmpKeyPath> (a materialised managed key) + the same opts.
     * PASSWORD auth omits the key/BatchMode and forces password auth.
     *
     * @param array<string,mixed> $conn        a merged connection
     * @param string              $keyPath     the identity-file path: the
     *                                          materialised tmpfs path for KEY,
     *                                          or the connection's keyFilePath
     *                                          (verbatim) for KEYFILE. Empty for
     *                                          PASSWORD.
     * @param string              $knownHosts  materialised known_hosts path
     * @return array<int,string>  the ssh argv (starting with "ssh")
     */
    public static function buildSshArgv(array $conn, string $keyPath, string $knownHosts): array
    {
        $conn = Credentials::mergeConnection($conn);
        $mode    = $conn['strictHostKey'];
        $port    = (int) $conn['port'];
        $timeout = (int) $conn['connectTimeout'];
        $auth    = $conn['authMethod'];

        $argv = ['ssh'];

        if ($auth === 'PASSWORD') {
            // Force interactive password auth; sshpass feeds the prompt. No
            // BatchMode (it would suppress the very prompt we answer).
            $argv[] = '-o';
            $argv[] = 'PubkeyAuthentication=no';
            $argv[] = '-o';
            $argv[] = 'PreferredAuthentications=password';
        } else {
            // KEY / KEYFILE auth: use ONLY the supplied key, non-interactively.
            // For KEYFILE the path is the connection's existing key file (OpenSSH
            // reads it directly); for KEY it's the materialised tmpfs copy.
            $argv[] = '-i';
            $argv[] = $keyPath;
            $argv[] = '-o';
            $argv[] = 'IdentitiesOnly=yes';
            $argv[] = '-o';
            $argv[] = 'BatchMode=yes';
        }

        $argv[] = '-o';
        $argv[] = 'StrictHostKeyChecking=' . $mode;
        $argv[] = '-o';
        $argv[] = 'UserKnownHostsFile=' . $knownHosts;
        // Pin host-key verification to ONLY our per-connection known_hosts file.
        // Without this, ssh would also consult the system-wide
        // GlobalKnownHostsFile (/etc/ssh/ssh_known_hosts), so StrictHostKeyChecking=yes
        // could succeed against a system entry we never pinned - breaking the UI
        // promise that "yes" requires the connection's own pinned host key.
        $argv[] = '-o';
        $argv[] = 'GlobalKnownHostsFile=/dev/null';
        $argv[] = '-o';
        $argv[] = 'ConnectTimeout=' . $timeout;
        $argv[] = '-p';
        $argv[] = (string) $port;

        return $argv;
    }

    /**
     * The sshpass PREFIX argv for PASSWORD auth, reading the password from a
     * tmpfs file (-f, never the command line / env so it can't leak via ps).
     * Returns [] for KEY auth or when sshpass is unavailable.
     *
     * @param array<string,mixed> $conn
     * @return array<int,string>
     */
    public static function buildSshpassPrefix(array $conn, string $passFile): array
    {
        $conn = Credentials::mergeConnection($conn);
        if ($conn['authMethod'] !== 'PASSWORD') {
            return [];
        }
        $bin = static::sshpassPath();
        if ($bin === '') {
            return [];
        }
        return [$bin, '-f', $passFile];
    }

    /**
     * The VALUE of rsync's `-e` option: the ssh command line as a single
     * shell-token string. rsync re-parses this value, so each argv element is
     * individually quoted (escapeshellarg) to survive that re-parse - this is
     * the ONE place a string is produced, and it is produced by quoting an argv
     * array, never by interpolating raw user data.
     *
     * NB: any sshpass prefix is NOT part of the -e value; sshpass wraps the
     * whole rsync process (it is the program rsync runs under), so Phase 4
     * prepends buildSshpassPrefix() to the rsync argv, not to -e.
     *
     * @param array<int,string> $sshArgv from buildSshArgv()
     */
    public static function rsyncDashE(array $sshArgv): string
    {
        $quoted = array_map('escapeshellarg', $sshArgv);
        return implode(' ', $quoted);
    }

    // --- materialisation (tmpfs, tight perms) -------------------------------

    /**
     * Materialise everything a connection needs to run, returning the concrete
     * tmpfs paths + argv pieces Phase 4's Rsync.php composes with. This is the
     * primary entry point for "I have a connectionId, give me what I need to
     * shell out safely."
     *
     * Each call mints a UNIQUE per-run token (connId + pid + random) and keys
     * every materialised file by it, so two concurrent runs - even of the SAME
     * connection - never share a key/pass/known_hosts file. The token is
     * returned as `token`; the caller MUST pass it to cleanupRuntime($token)
     * in a finally so only this run's own files are removed.
     *
     * Steps:
     *   - ensure the tmpfs runtime dirs exist with safe modes (700);
     *   - for KEYFILE auth: use the connection's keyFilePath DIRECTLY as the ssh
     *     identity file. We do NOT read, copy or materialise the key - OpenSSH
     *     reads the file in place. We DO verify it exists and is readable, and
     *     fail with a clear message if not (it is never created here);
     *   - for KEY auth: write the referenced (managed) key's private material to
     *     keys/<token> at mode 600 (OpenSSH refuses world-readable keys);
     *   - for PASSWORD auth: write the de-obfuscated password to a 600 passfile
     *     (only when sshpass is available);
     *   - write the connection's pinned remoteHostKey to a 600 known_hosts file.
     *
     * @param array<string,mixed> $creds loaded credentials structure
     * @param string              $connId
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   token?: string,
     *   conn?: array<string,mixed>,
     *   sshArgv?: array<int,string>,
     *   sshpassPrefix?: array<int,string>,
     *   dashE?: string,
     *   keyPath?: string,
     *   passFile?: string,
     *   knownHosts?: string
     * }
     * @throws RuntimeException on a filesystem failure while materialising.
     */
    public static function materialize(array $creds, string $connId): array
    {
        $conn = Credentials::findConnection($creds, $connId);
        if ($conn === null) {
            return ['ok' => false, 'error' => "Connection not found: $connId"];
        }
        $conn = Credentials::mergeConnection($conn);

        self::ensureRuntimeDirs();

        // Unique per-run token: isolates concurrent runs even of the same conn.
        $token      = static::newRuntimeToken($connId);
        $knownHosts = static::knownHostsPath($token);
        self::writeKnownHosts($knownHosts, (string) $conn['remoteHostKey']);

        $keyPath  = '';
        $passFile = '';

        if ($conn['authMethod'] === 'KEYFILE') {
            // Use the existing on-system key file DIRECTLY: no read, no copy, no
            // tmpfs materialisation. We only check it's usable and pass the path.
            $keyFilePath = (string) $conn['keyFilePath'];
            $check = self::checkKeyFile($keyFilePath);
            if ($check !== '') {
                self::cleanupRuntime($token);
                return ['ok' => false, 'error' => $check];
            }
            // The identity file IS the connection's path; nothing is created in
            // the tmpfs keys dir for KEYFILE, so cleanupRuntime() never touches it.
            $keyPath = $keyFilePath;
        } elseif ($conn['authMethod'] === 'KEY') {
            $key = Credentials::findKey($creds, (string) $conn['keyId']);
            if ($key === null) {
                self::cleanupRuntime($token);
                return ['ok' => false, 'error' => 'Connection references an SSH key that no longer exists.'];
            }
            $priv = (string) ($key['privateKey'] ?? '');
            if (trim($priv) === '') {
                self::cleanupRuntime($token);
                return ['ok' => false, 'error' => 'The referenced SSH key has no private key material.'];
            }
            $keyPath = static::keyPath($token);
            self::writePrivateKey($keyPath, $priv);
        } else { // PASSWORD
            if (!static::sshpassAvailable()) {
                self::cleanupRuntime($token);
                return ['ok' => false, 'error' => static::sshpassMissingMessage()];
            }
            $passFile = self::writePassFile($token, Credentials::deobfuscate((string) $conn['password']));
        }

        $sshArgv       = self::buildSshArgv($conn, $keyPath, $knownHosts);
        $sshpassPrefix = self::buildSshpassPrefix($conn, $passFile);

        return [
            'ok'            => true,
            'token'         => $token,
            'conn'          => $conn,
            'sshArgv'       => $sshArgv,
            'sshpassPrefix' => $sshpassPrefix,
            'dashE'         => self::rsyncDashE($sshArgv),
            'keyPath'       => $keyPath,
            'passFile'      => $passFile,
            'knownHosts'    => $knownHosts,
        ];
    }

    /**
     * Run-time check for a KEYFILE connection's identity file. Returns '' when
     * the file is present and readable, or a CLEAR, user-facing error message
     * otherwise. We never CREATE the file - the user owns its lifecycle.
     *
     * The message calls out the Unraid tmpfs gotcha: /root is RAM-backed and is
     * wiped on reboot, so a key placed there must be re-created on boot (via
     * /boot/config/go or the SSH plugin) to persist for scheduled runs.
     *
     * NB: PURE except for the filesystem stat (is_file/is_readable). It assumes
     * the path was already validated as absolute + injection-safe at save time;
     * we still re-trim defensively.
     */
    public static function checkKeyFile(string $keyFilePath): string
    {
        $keyFilePath = trim($keyFilePath);
        if ($keyFilePath === '') {
            return 'No SSH key file path is configured for this connection.';
        }
        if (!is_file($keyFilePath) || !is_readable($keyFilePath)) {
            return 'SSH key file ' . $keyFilePath . ' not found or unreadable. '
                . 'Note: /root is tmpfs on Unraid and is wiped on reboot, so ensure your key '
                . 'persists (e.g. via /boot/config/go or the SSH plugin).';
        }
        return '';
    }

    /**
     * Remove a run's materialised secrets (best-effort), identified by the
     * per-run token returned from materialize(). Phase 4 calls this in a finally
     * after a run; the Credentials tab's testConnection cleans up its own token.
     *
     * For KEYFILE auth nothing is materialised in the tmpfs keys dir (the run
     * uses the user's existing key file in place), so this never touches it -
     * keyPath($token) is the per-run tmpfs path, never the connection's
     * keyFilePath.
     *
     * Because every file is keyed by the unique run token, this only ever
     * unlinks THIS run's own private key, known_hosts and password file - a
     * concurrent run (even of the same connection) has a different token and is
     * untouched.
     */
    public static function cleanupRuntime(string $token): void
    {
        $paths = [
            static::keyPath($token),
            static::knownHostsPath($token),
            static::passFilePath($token),
        ];
        foreach ($paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    /**
     * Ensure the tmpfs runtime dirs exist with restrictive (700) modes.
     *
     * The runtime base lives under world-writable /tmp, so we defend against a
     * symlink attack: an unprivileged local user could pre-create one of these
     * paths as a SYMLINK (e.g. /tmp/unraid.rsync/keys -> /etc) so that a later
     * file_put_contents() of a secret would follow it and clobber an arbitrary
     * file as the (root) webGui user. We therefore REFUSE any runtime path that
     * already exists as a symlink or as a non-directory, before writing anything.
     */
    private static function ensureRuntimeDirs(): void
    {
        foreach ([
            rtrim(static::$runtimeBase, '/'),
            static::keysDir(),
            rtrim(static::$runtimeBase, '/') . '/known_hosts',
            rtrim(static::$runtimeBase, '/') . '/pass',
        ] as $dir) {
            // Reject a symlink at this path outright (do NOT follow it).
            if (is_link($dir)) {
                throw new RuntimeException("Refusing to use a symlinked runtime dir: $dir");
            }
            if (file_exists($dir) && !is_dir($dir)) {
                throw new RuntimeException("Runtime path exists but is not a directory: $dir");
            }
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("Unable to create runtime dir: $dir");
            }
            // Re-check after creation: a race could have swapped it for a symlink.
            if (is_link($dir)) {
                throw new RuntimeException("Refusing to use a symlinked runtime dir: $dir");
            }
            @chmod($dir, 0700);
        }
    }

    /**
     * Write a secret to a tmpfs file at mode 600 WITHOUT ever following a
     * symlink, even under a TOCTOU race. The runtime base is under
     * world-writable /tmp, so a check-then-write (is_link then file_put_contents)
     * is racy: an attacker could plant a symlink at $path in between. Instead we
     * write the body to a fresh tempnam() file in the SAME directory (tempnam
     * creates with O_EXCL, so it never opens an attacker's file), chmod it 600,
     * then rename() it over the target. rename() replaces the destination name
     * atomically and operates on the symlink itself rather than following it, so
     * the secret can never land on an attacker-chosen target.
     */
    private static function safeWriteSecret(string $path, string $body, string $label): void
    {
        $dir = dirname($path);
        $tmp = @tempnam($dir, '.ur-secret.');
        if ($tmp === false) {
            throw new RuntimeException("Unable to create temp file for $label in: $dir");
        }
        // tempnam created a regular 600-ish file we own; tighten and fill it.
        @chmod($tmp, 0600);
        if (@file_put_contents($tmp, $body) === false) {
            @unlink($tmp);
            throw new RuntimeException("Unable to materialise $label: $path");
        }
        @chmod($tmp, 0600);
        // rename replaces the name atomically and does NOT follow a symlink that
        // may have been planted at $path.
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to place $label at: $path");
        }
    }

    /** Write a private key to tmpfs at mode 600 (OpenSSH requires it). */
    private static function writePrivateKey(string $path, string $privateKey): void
    {
        // Normalise to exactly one trailing newline - OpenSSH is picky about a
        // missing final newline on some key formats.
        self::safeWriteSecret($path, rtrim($privateKey, "\r\n") . "\n", 'private key');
    }

    /** Write a password to a tmpfs file at mode 600 for `sshpass -f`. */
    private static function writePassFile(string $token, string $password): string
    {
        $path = static::passFilePath($token);
        // sshpass -f reads the first line as the password; no trailing newline
        // needed, but a single one is tolerated. Write exactly the password.
        self::safeWriteSecret($path, $password, 'password file');
        return $path;
    }

    /**
     * Write the pinned host key to a per-run known_hosts file (mode 600). An
     * empty pinned value writes an empty file.
     *
     * NOTE: this known_hosts file is per-RUN tmpfs and is deleted by
     * cleanupRuntime() after the run. With StrictHostKeyChecking=accept-new ssh
     * will accept an unknown host key for the duration of THIS run, but because
     * the file does not persist, nothing is pinned for future runs - persistent
     * pinning happens only when the user clicks "Discover host key" and SAVES
     * the resulting remoteHostKey into the connection (which is then written
     * here on every subsequent run). The UI/docs reflect this; accept-new is a
     * convenience, not a durable trust-on-first-use store.
     */
    private static function writeKnownHosts(string $path, string $hostKey): void
    {
        $body = trim($hostKey);
        if ($body !== '') {
            $body .= "\n";
        }
        self::safeWriteSecret($path, $body, 'known_hosts');
    }

    // --- test connection ----------------------------------------------------

    /**
     * Run ssh once against a connection with a trivial remote command (`true`)
     * and classify the outcome. Materialises secrets, runs, cleans up.
     *
     * Returns a structured result the handler renders directly:
     *   ok      bool    - the probe succeeded (authenticated + ran `true`)
     *   message string  - a human message
     *   reason  string  - a machine token: 'ok' | 'auth' | 'hostkey' |
     *                      'unreachable' | 'config' | 'sshpass-missing'
     *
     * Failure-mode distinction (per the plan):
     *   - sshpass exit 5            -> auth failure
     *   - sshpass exit 6/7          -> host-key unknown/changed
     *   - ssh exit 255             -> connect/auth error; we sniff stderr to
     *                                  split auth vs host-key vs unreachable
     *   - exit 0                    -> success
     *   - other                    -> treated as success-ish remote error but
     *                                  reported (the remote `true` shouldn't
     *                                  fail, so anything else is surfaced).
     *
     * @param array<string,mixed> $creds  loaded credentials structure
     * @return array{ok:bool,message:string,reason:string}
     */
    public static function testConnection(array $creds, string $connId): array
    {
        $conn = Credentials::findConnection($creds, $connId);
        if ($conn === null) {
            return ['ok' => false, 'reason' => 'config', 'message' => "Connection not found: $connId"];
        }
        $conn = Credentials::mergeConnection($conn);

        if ($conn['host'] === '' || $conn['username'] === '') {
            return ['ok' => false, 'reason' => 'config', 'message' => 'Host and username are required to test a connection.'];
        }
        if ($conn['authMethod'] === 'PASSWORD' && !static::sshpassAvailable()) {
            return ['ok' => false, 'reason' => 'sshpass-missing', 'message' => static::sshpassMissingMessage()];
        }

        try {
            $mat = self::materialize($creds, $connId);
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'config', 'message' => 'Could not prepare connection: ' . $e->getMessage()];
        }
        if (empty($mat['ok'])) {
            $reason = (strpos((string) ($mat['error'] ?? ''), 'sshpass') !== false) ? 'sshpass-missing' : 'config';
            return ['ok' => false, 'reason' => $reason, 'message' => (string) $mat['error']];
        }

        // Compose the full probe argv: [sshpass-prefix] ssh <opts> -- user@host true
        // The `--` ends ssh option parsing so a host/username starting with '-'
        // can never be read as an ssh option (option-injection guard, on top of
        // the validation guard in Credentials::validateConnection).
        $argv = array_merge(
            $mat['sshpassPrefix'],
            $mat['sshArgv'],
            ['--', $conn['username'] . '@' . $conn['host'], 'true']
        );

        $exitCode = self::SSH_EXIT_ERROR;
        $stderr   = '';
        try {
            // REDACTION NOTE: buildSshArgv() composes this probe WITHOUT ssh's -v
            // (verbose) flag, so the captured stderr we surface via firstLine()
            // below (in classifyProbe/sniffStderr) never echoes the materialised
            // tmpfs key path or any secret — only OpenSSH's plain connect/auth
            // diagnostics. If anyone ever adds -v here, ssh WILL log the identity
            // file path (and more), so the stderr must then be redacted (see
            // Logger::setRedaction) before it is returned to the browser.
            [$exitCode, $stderr] = static::runProbe($argv);
        } finally {
            self::cleanupRuntime((string) $mat['token']);
        }

        return self::classifyProbe($conn, (int) $exitCode, (string) $stderr);
    }

    /**
     * Classify a probe outcome into {ok, reason, message}. PURE - given the auth
     * method, exit code and stderr text, decide what failed. Unit-tested with
     * representative exit codes / stderr without ever running ssh.
     *
     * @param array<string,mixed> $conn merged connection
     * @return array{ok:bool,message:string,reason:string}
     */
    public static function classifyProbe(array $conn, int $exitCode, string $stderr): array
    {
        $isPassword = (($conn['authMethod'] ?? 'KEY') === 'PASSWORD');

        if ($exitCode === 0) {
            return ['ok' => true, 'reason' => 'ok', 'message' => 'Connection succeeded.'];
        }

        // sshpass owns 5/6/7 ONLY for the PASSWORD path (it's the outer process
        // there). For KEY auth those same small codes would be a remote
        // command's own exit and must NOT be read as sshpass semantics.
        if ($isPassword) {
            switch ($exitCode) {
                case self::SSHPASS_INCORRECT_PASS:
                    return ['ok' => false, 'reason' => 'auth', 'message' => 'Authentication failed: incorrect password.'];
                case self::SSHPASS_HOSTKEY_UNKNOWN:
                    return ['ok' => false, 'reason' => 'hostkey', 'message' => 'Host key is unknown. Use "Discover host key" and save, then retry.'];
                case self::SSHPASS_HOSTKEY_CHANGED:
                    return ['ok' => false, 'reason' => 'hostkey', 'message' => 'Host key has CHANGED since it was pinned. Verify the remote host, then re-discover the host key.'];
                case self::SSHPASS_INVALID_ARGS:
                case self::SSHPASS_CONFLICT:
                case self::SSHPASS_RUNTIME_ERROR:
                case self::SSHPASS_PARSE_ERROR:
                    // Fall through to the stderr-sniffing path below; sshpass
                    // ran ssh and the failure is really ssh's.
                    break;
            }
        }

        // Sniff stderr only when the failure is genuinely an ssh-level
        // connect/auth error (exit 255), or - on the PASSWORD path - one of
        // sshpass's own internal-error codes (1-4). sshpass otherwise propagates
        // the WRAPPED ssh / remote-command exit status verbatim, so a non-255
        // password-path exit (e.g. a remote `true` that somehow exits 2) is a
        // real remote exit and must NOT be misreported as a connect/auth
        // failure - that was the bug in keying this branch on `|| $isPassword`.
        $sshpassInternal = $isPassword && in_array($exitCode, [
            self::SSHPASS_INVALID_ARGS,
            self::SSHPASS_CONFLICT,
            self::SSHPASS_RUNTIME_ERROR,
            self::SSHPASS_PARSE_ERROR,
        ], true);

        if ($exitCode === self::SSH_EXIT_ERROR || $sshpassInternal) {
            // ssh failed to connect/authenticate. Split the cause by sniffing
            // its stderr (the most reliable signal we have without a live host).
            $reason = self::sniffStderr($stderr);
            return ['ok' => false, 'reason' => $reason['reason'], 'message' => $reason['message']];
        }

        // Any other exit code: ssh connected and the remote `true` returned
        // non-zero (shouldn't happen for `true`). Surface it rather than calling
        // it OK - but it is NOT a connect/auth failure.
        return [
            'ok'      => false,
            'reason'  => 'unreachable',
            'message' => 'Connection test returned an unexpected exit code (' . $exitCode . ').'
                . ($stderr !== '' ? ' ' . self::firstLine($stderr) : ''),
        ];
    }

    /**
     * Inspect ssh stderr to distinguish auth failure vs host-key failure vs
     * unreachable. PURE. Returns {reason, message}.
     *
     * @return array{reason:string,message:string}
     */
    public static function sniffStderr(string $stderr): array
    {
        $s = strtolower($stderr);

        // Host-key problems first - they're the most specific.
        if (
            strpos($s, 'host key verification failed') !== false
            || strpos($s, 'remote host identification has changed') !== false
            || strpos($s, 'no matching host key') !== false
            || strpos($s, 'host key for') !== false
        ) {
            return [
                'reason'  => 'hostkey',
                'message' => 'Host key verification failed. Use "Discover host key" and save the connection, then retry.'
                    . ($stderr !== '' ? ' (' . self::firstLine($stderr) . ')' : ''),
            ];
        }

        // Authentication failures.
        if (
            strpos($s, 'permission denied') !== false
            || strpos($s, 'authentication failed') !== false
            || strpos($s, 'too many authentication failures') !== false
            || strpos($s, 'no supported authentication') !== false
        ) {
            return [
                'reason'  => 'auth',
                'message' => 'Authentication failed. Check the username and key/password.'
                    . ($stderr !== '' ? ' (' . self::firstLine($stderr) . ')' : ''),
            ];
        }

        // Reachability / DNS / timeout.
        if (
            strpos($s, 'connection timed out') !== false
            || strpos($s, 'connection refused') !== false
            || strpos($s, 'could not resolve') !== false
            || strpos($s, 'name or service not known') !== false
            || strpos($s, 'no route to host') !== false
            || strpos($s, 'network is unreachable') !== false
            || strpos($s, 'operation timed out') !== false
        ) {
            return [
                'reason'  => 'unreachable',
                'message' => 'Could not reach the host. Check the host, port and network.'
                    . ($stderr !== '' ? ' (' . self::firstLine($stderr) . ')' : ''),
            ];
        }

        // Unknown ssh failure - report it as unreachable-ish with the detail.
        return [
            'reason'  => 'unreachable',
            'message' => 'Connection failed.' . ($stderr !== '' ? ' ' . self::firstLine($stderr) : ''),
        ];
    }

    /** First non-empty line of a (possibly multi-line) stderr blob, trimmed. */
    private static function firstLine(string $text): string
    {
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }
        return '';
    }

    // --- live-system seams (overridden in tests) ----------------------------

    /**
     * Locate sshpass WITHOUT invoking a shell. Scans the directories on $PATH
     * (plus the common sbin/usr locations) for an executable file named
     * "sshpass" and returns its absolute path, or '' when not found. This avoids
     * shell_exec / a shell builtin entirely, matching the no-shell design used
     * for every other invocation. Overridable in tests.
     */
    protected static function locateSshpass(): string
    {
        $pathEnv = getenv('PATH');
        $dirs = ($pathEnv !== false && $pathEnv !== '')
            ? explode(PATH_SEPARATOR, $pathEnv)
            : [];
        // Defensive fallbacks for Unraid/Slackware where PATH may be minimal in
        // the webGui context.
        foreach (['/usr/bin', '/bin', '/usr/local/bin', '/usr/sbin', '/sbin'] as $extra) {
            if (!in_array($extra, $dirs, true)) {
                $dirs[] = $extra;
            }
        }
        foreach ($dirs as $dir) {
            // Ignore empty and NON-ABSOLUTE PATH entries (e.g. "." or a relative
            // dir): a relative entry could let an attacker-controlled directory
            // supply a rogue "sshpass". Only trust absolute directories.
            if ($dir === '' || $dir[0] !== '/') {
                continue;
            }
            $candidate = rtrim($dir, '/') . '/sshpass';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Run the ssh probe argv (no shell) and return [exitCode, stderr]. Live
     * implementation uses proc_open with the argv ARRAY so nothing is parsed by
     * a shell. Overridable in tests so the classification logic is exercised
     * without opening a socket.
     *
     * @param array<int,string> $argv
     * @return array{0:int,1:string}
     */
    protected static function runProbe(array $argv): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        // Pass the argv ARRAY form so PHP execs directly without /bin/sh.
        $proc = @proc_open($argv, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return [self::SSH_EXIT_ERROR, 'Failed to start ssh.'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        // stdout from a `true` probe is empty; keep stderr for classification.
        unset($stdout);
        return [(int) $code, is_string($stderr) ? $stderr : ''];
    }
}

<?php
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
 *      we copy the referenced key to /tmp/unraid.rsync/keys/<connId> (dir 700,
 *      key 600 - keyed by CONNECTION id, not key id, so each connection has its
 *      own copy and concurrent runs sharing a key never clean up each other's)
 *      and write the connection's pinned remoteHostKey to a per-connection
 *      known_hosts file. cleanupRuntime() removes them again.
 *
 * AUTH METHODS
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

    /** tmpfs dir that holds materialised private keys (mode 700). */
    public static function keysDir(): string
    {
        return rtrim(static::$runtimeBase, '/') . '/keys';
    }

    /**
     * Path a connection's private key materialises to (mode 600). The file is
     * keyed by CONNECTION id, not key id, so each connection gets its OWN copy
     * of the (shared) key material on tmpfs. That makes cleanup isolated: a
     * connection's cleanupRuntime() only ever unlinks its own file, so two
     * concurrent runs that reference the same SSH key can never unlink each
     * other's materialised key out from under an in-flight ssh.
     */
    public static function keyPath(string $connId): string
    {
        return static::keysDir() . '/' . self::safeId($connId);
    }

    /** Path the per-connection known_hosts materialises to. */
    public static function knownHostsPath(string $connId): string
    {
        return rtrim(static::$runtimeBase, '/') . '/known_hosts/' . self::safeId($connId);
    }

    /**
     * Sanitise an id for use as a filename segment. ids are slug-shaped
     * (k-/c- + [a-z0-9-]) by construction, but defend against traversal anyway:
     * strip anything that isn't a safe filename char.
     */
    private static function safeId(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $id);
        return ($clean === '' || $clean === null) ? 'unknown' : $clean;
    }

    // --- sshpass detection (detect-and-degrade) -----------------------------

    /**
     * Resolve the sshpass executable path, or '' when not installed. Honors the
     * test override. Live detection uses `command -v sshpass` via the run seam.
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
     * KEY auth uses -i <tmpKeyPath> + IdentitiesOnly + BatchMode.
     * PASSWORD auth omits the key/BatchMode and forces password auth.
     *
     * @param array<string,mixed> $conn        a merged connection
     * @param string              $tmpKeyPath  materialised key path (KEY auth)
     * @param string              $knownHosts  materialised known_hosts path
     * @return array<int,string>  the ssh argv (starting with "ssh")
     */
    public static function buildSshArgv(array $conn, string $tmpKeyPath, string $knownHosts): array
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
            // KEY auth: use ONLY the supplied key, non-interactively.
            $argv[] = '-i';
            $argv[] = $tmpKeyPath;
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
     * Steps:
     *   - ensure the tmpfs runtime dirs exist with safe modes (700);
     *   - for KEY auth: write the referenced key's private material to
     *     keys/<connId> at mode 600 (OpenSSH refuses world-readable keys; keyed
     *     by connection id so concurrent runs sharing the key don't collide);
     *   - for PASSWORD auth: write the de-obfuscated password to a 600 passfile
     *     (only when sshpass is available);
     *   - write the connection's pinned remoteHostKey to a 600 known_hosts file
     *     (empty file when none pinned - accept-new will then learn it).
     *
     * @param array<string,mixed> $creds loaded credentials structure
     * @param string              $connId
     * @return array{
     *   ok: bool,
     *   error?: string,
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

        $knownHosts = static::knownHostsPath($connId);
        self::writeKnownHosts($knownHosts, (string) $conn['remoteHostKey']);

        $keyPath  = '';
        $passFile = '';

        if ($conn['authMethod'] === 'KEY') {
            $key = Credentials::findKey($creds, (string) $conn['keyId']);
            if ($key === null) {
                return ['ok' => false, 'error' => 'Connection references an SSH key that no longer exists.'];
            }
            $priv = (string) ($key['privateKey'] ?? '');
            if (trim($priv) === '') {
                return ['ok' => false, 'error' => 'The referenced SSH key has no private key material.'];
            }
            // Keyed by CONNECTION id (not key id): each connection gets its own
            // copy so concurrent runs sharing one SSH key never clean up each
            // other's materialised key.
            $keyPath = static::keyPath($connId);
            self::writePrivateKey($keyPath, $priv);
        } else { // PASSWORD
            if (!static::sshpassAvailable()) {
                return ['ok' => false, 'error' => static::sshpassMissingMessage()];
            }
            $passFile = self::writePassFile($connId, Credentials::deobfuscate((string) $conn['password']));
        }

        $sshArgv       = self::buildSshArgv($conn, $keyPath, $knownHosts);
        $sshpassPrefix = self::buildSshpassPrefix($conn, $passFile);

        return [
            'ok'            => true,
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
     * Remove materialised secrets for a connection (best-effort). Phase 4 calls
     * this in a finally after a run; the Credentials tab's testConnection also
     * cleans up its own materialisation.
     *
     * Everything a connection materialises - its private key, known_hosts and
     * password file - is keyed by CONNECTION id, so this only ever unlinks THIS
     * connection's own files. Two concurrent runs that reference the same SSH
     * key each have their own copy and clean up independently. (The second
     * argument is retained for backward compatibility and is no longer needed to
     * locate the key file.)
     */
    public static function cleanupRuntime(string $connId, string $keyId = ''): void
    {
        unset($keyId); // key file is keyed by connId; kept for BC of the signature
        $paths = [
            static::keyPath($connId),
            static::knownHostsPath($connId),
            rtrim(static::$runtimeBase, '/') . '/pass/' . self::safeId($connId),
        ];
        foreach ($paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    /** Ensure the tmpfs runtime dirs exist with restrictive (700) modes. */
    private static function ensureRuntimeDirs(): void
    {
        foreach ([
            rtrim(static::$runtimeBase, '/'),
            static::keysDir(),
            rtrim(static::$runtimeBase, '/') . '/known_hosts',
            rtrim(static::$runtimeBase, '/') . '/pass',
        ] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("Unable to create runtime dir: $dir");
            }
            @chmod($dir, 0700);
        }
    }

    /** Write a private key to tmpfs at mode 600 (OpenSSH requires it). */
    private static function writePrivateKey(string $path, string $privateKey): void
    {
        // Normalise to exactly one trailing newline - OpenSSH is picky about a
        // missing final newline on some key formats.
        $body = rtrim($privateKey, "\r\n") . "\n";
        if (@file_put_contents($path, $body) === false) {
            throw new RuntimeException("Unable to materialise private key: $path");
        }
        @chmod($path, 0600);
    }

    /** Write a password to a tmpfs file at mode 600 for `sshpass -f`. */
    private static function writePassFile(string $connId, string $password): string
    {
        $path = rtrim(static::$runtimeBase, '/') . '/pass/' . self::safeId($connId);
        // sshpass -f reads the first line as the password; no trailing newline
        // needed, but a single one is tolerated. Write exactly the password.
        if (@file_put_contents($path, $password) === false) {
            throw new RuntimeException("Unable to materialise password file: $path");
        }
        @chmod($path, 0600);
        return $path;
    }

    /**
     * Write the pinned host key to a known_hosts file (mode 600). An empty
     * pinned value writes an empty file; with StrictHostKeyChecking=accept-new
     * ssh then learns and pins the key on first connect.
     */
    private static function writeKnownHosts(string $path, string $hostKey): void
    {
        $body = trim($hostKey);
        if ($body !== '') {
            $body .= "\n";
        }
        if (@file_put_contents($path, $body) === false) {
            throw new RuntimeException("Unable to materialise known_hosts: $path");
        }
        @chmod($path, 0600);
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

        // Compose the full probe argv: [sshpass-prefix] ssh <opts> user@host true
        $argv = array_merge(
            $mat['sshpassPrefix'],
            $mat['sshArgv'],
            [$conn['username'] . '@' . $conn['host'], 'true']
        );

        $exitCode = self::SSH_EXIT_ERROR;
        $stderr   = '';
        try {
            [$exitCode, $stderr] = static::runProbe($argv);
        } finally {
            self::cleanupRuntime($connId, (string) $conn['keyId']);
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

        if ($exitCode === self::SSH_EXIT_ERROR || $isPassword) {
            // ssh failed to connect/authenticate. Split the cause by sniffing
            // its stderr (the most reliable signal we have without a live host).
            $reason = self::sniffStderr($stderr);
            return ['ok' => false, 'reason' => $reason['reason'], 'message' => $reason['message']];
        }

        // Any other exit code: ssh connected and the remote `true` returned
        // non-zero (shouldn't happen). Surface it rather than calling it OK.
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
     * Locate sshpass on PATH. Live implementation: `command -v sshpass`.
     * Returns the absolute path or '' when not found. Overridable in tests.
     */
    protected static function locateSshpass(): string
    {
        $out = @shell_exec('command -v sshpass 2>/dev/null');
        $out = is_string($out) ? trim($out) : '';
        return $out;
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

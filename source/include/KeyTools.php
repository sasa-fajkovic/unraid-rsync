<?php
/**
 * KeyTools.php - thin, testable wrappers around ssh-keygen / ssh-keyscan for the
 * Unraid Rsync Credentials tab.
 *
 * This is where the only-at-key-creation-time shell-outs live, kept OUT of
 * Credentials.php (pure persistence) and Ssh.php (transport) so each stays
 * single-purpose. The actual exec of ssh-keygen/ssh-keyscan goes through small
 * overridable seams (runKeygen* / runKeyscan); the PARSING of their output -
 * fingerprint + public key extraction, which is the bit most likely to regress
 * - is pure and unit-tested against representative sample output.
 *
 * SECURITY
 *   - All invocations are argv ARRAYS (proc_open without a shell). No path,
 *     name or key material is ever interpolated into a shell string.
 *   - Generation uses an EMPTY passphrase (-N "") on purpose: jobs run
 *     unattended from cron, like TrueNAS's rsync tasks. The resulting private
 *     key is a secret stored in credentials.json; the UI documents that it
 *     lives on world-readable FAT32 and is materialised to tmpfs 600 before use.
 *   - host-key discovery (ssh-keyscan) validates host/port and never shells.
 */

class KeyTools
{
    /** Supported key types for generation. */
    const KEY_TYPES = ['ed25519', 'rsa'];

    // --- generation ---------------------------------------------------------

    /**
     * Generate a new SSH key pair with an EMPTY passphrase.
     *
     *   ed25519:  ssh-keygen -t ed25519 -N "" -f <tmp>
     *   rsa:      ssh-keygen -t rsa -b 4096 -N "" -f <tmp>
     *
     * Returns { ok, error?, privateKey?, publicKey?, fingerprint? }. The temp
     * files written by ssh-keygen are read back and unlinked; nothing is left
     * on disk.
     *
     * @param string $type    'ed25519' | 'rsa'
     * @param string $comment optional comment baked into the public key
     * @return array{ok:bool,error?:string,privateKey?:string,publicKey?:string,fingerprint?:string}
     */
    public static function generate(string $type = 'ed25519', string $comment = ''): array
    {
        $type = strtolower(trim($type));
        if (!in_array($type, self::KEY_TYPES, true)) {
            return ['ok' => false, 'error' => 'Unsupported key type: ' . $type];
        }

        $dir = self::tempDir();
        if ($dir === '') {
            return ['ok' => false, 'error' => 'Unable to create a temp dir for key generation.'];
        }
        $keyFile = $dir . '/key';

        $argv = ['ssh-keygen', '-t', $type];
        if ($type === 'rsa') {
            $argv[] = '-b';
            $argv[] = '4096';
        }
        $argv[] = '-N';
        $argv[] = '';            // empty passphrase
        $argv[] = '-f';
        $argv[] = $keyFile;
        if ($comment !== '') {
            $argv[] = '-C';
            $argv[] = $comment;
        }

        [$code, , $stderr] = static::runKeygen($argv);
        if ($code !== 0) {
            self::rmTempDir($dir);
            return ['ok' => false, 'error' => 'ssh-keygen failed: ' . self::firstLine($stderr)];
        }

        $private = @file_get_contents($keyFile);
        $public  = @file_get_contents($keyFile . '.pub');
        self::rmTempDir($dir);

        if (!is_string($private) || trim($private) === '' || !is_string($public) || trim($public) === '') {
            return ['ok' => false, 'error' => 'ssh-keygen produced no key material.'];
        }

        $public = trim($public);
        $fp = self::fingerprintFromPublic($public);

        return [
            'ok'          => true,
            'privateKey'  => rtrim($private, "\r\n") . "\n",
            'publicKey'   => $public,
            'fingerprint' => $fp,
        ];
    }

    // --- import -------------------------------------------------------------

    /**
     * Import a pasted key. The user may paste a private key, a public key, or
     * both. We:
     *   - if a private key is given, derive the public key from it
     *     (`ssh-keygen -y -f <tmp>`) and prefer that over any pasted public key
     *     (it's authoritative); a private key with a non-empty passphrase fails
     *     here, which is the desired guardrail (unattended automation needs an
     *     empty passphrase);
     *   - if only a public key is given, validate + fingerprint it;
     *   - compute the fingerprint from the (derived or pasted) public key
     *     (`ssh-keygen -lf <tmp>`).
     *
     * @return array{ok:bool,error?:string,privateKey?:string,publicKey?:string,fingerprint?:string}
     */
    public static function import(string $privateKey = '', string $publicKey = ''): array
    {
        $privateKey = trim($privateKey);
        $publicKey  = trim($publicKey);

        if ($privateKey === '' && $publicKey === '') {
            return ['ok' => false, 'error' => 'Paste a private key, a public key, or both.'];
        }

        $derivedPublic = '';
        if ($privateKey !== '') {
            $derived = self::derivePublicFromPrivate($privateKey);
            if (empty($derived['ok'])) {
                return ['ok' => false, 'error' => (string) ($derived['error'] ?? 'Invalid private key.')];
            }
            $derivedPublic = (string) $derived['publicKey'];
        }

        // The authoritative public key: derived from the private key when we
        // have one, else the pasted public key.
        $effectivePublic = $derivedPublic !== '' ? $derivedPublic : $publicKey;
        if ($effectivePublic === '') {
            return ['ok' => false, 'error' => 'Could not determine a public key from the input.'];
        }

        $fp = self::fingerprintFromPublic($effectivePublic);
        if ($fp === '') {
            return ['ok' => false, 'error' => 'The public key could not be parsed (invalid format).'];
        }

        return [
            'ok'          => true,
            'privateKey'  => $privateKey !== '' ? (rtrim($privateKey, "\r\n") . "\n") : '',
            'publicKey'   => $effectivePublic,
            'fingerprint' => $fp,
        ];
    }

    /**
     * Derive the public key from a private key via `ssh-keygen -y -f <tmp>`.
     * Returns { ok, error?, publicKey? }. A passphrase-protected key fails (the
     * documented requirement: empty passphrase for unattended automation).
     *
     * @return array{ok:bool,error?:string,publicKey?:string}
     */
    public static function derivePublicFromPrivate(string $privateKey): array
    {
        $dir = self::tempDir();
        if ($dir === '') {
            return ['ok' => false, 'error' => 'Unable to create a temp dir.'];
        }
        $keyFile = $dir . '/key';
        if (@file_put_contents($keyFile, rtrim($privateKey, "\r\n") . "\n") === false) {
            self::rmTempDir($dir);
            return ['ok' => false, 'error' => 'Unable to write the key for validation.'];
        }
        @chmod($keyFile, 0600);

        // -P "" supplies an empty passphrase non-interactively; a key that
        // actually needs a passphrase fails rather than hanging on a prompt.
        [$code, $stdout, $stderr] = static::runKeygen(['ssh-keygen', '-y', '-P', '', '-f', $keyFile]);
        self::rmTempDir($dir);

        if ($code !== 0 || !is_string($stdout) || trim($stdout) === '') {
            $msg = self::firstLine($stderr);
            if (stripos($msg, 'passphrase') !== false || stripos($msg, 'incorrect') !== false) {
                return ['ok' => false, 'error' => 'The private key is passphrase-protected. Provide a key with an empty passphrase (required for unattended runs).'];
            }
            return ['ok' => false, 'error' => 'Invalid private key' . ($msg !== '' ? ': ' . $msg : '.')];
        }

        return ['ok' => true, 'publicKey' => trim($stdout)];
    }

    /**
     * Compute a SHA256 fingerprint for a public key line via
     * `ssh-keygen -lf <tmp>` and parse the "SHA256:..." token out of its
     * output. Returns '' on any failure. The PARSING is pure; the exec is
     * behind the seam.
     */
    public static function fingerprintFromPublic(string $publicKey): string
    {
        $publicKey = trim($publicKey);
        if ($publicKey === '') {
            return '';
        }
        $dir = self::tempDir();
        if ($dir === '') {
            return '';
        }
        $pubFile = $dir . '/key.pub';
        if (@file_put_contents($pubFile, $publicKey . "\n") === false) {
            self::rmTempDir($dir);
            return '';
        }
        [$code, $stdout, ] = static::runKeygen(['ssh-keygen', '-lf', $pubFile]);
        self::rmTempDir($dir);
        if ($code !== 0 || !is_string($stdout)) {
            return '';
        }
        return self::parseFingerprint($stdout);
    }

    /**
     * Parse the SHA256 fingerprint token out of `ssh-keygen -lf` output. PURE.
     * Example input:
     *   "256 SHA256:abc123... user@host (ED25519)"
     * Returns "SHA256:abc123..." or '' if not found.
     */
    public static function parseFingerprint(string $keygenOutput): string
    {
        if (preg_match('/\b(SHA256:[A-Za-z0-9+\/=]+)/', $keygenOutput, $m)) {
            return $m[1];
        }
        // Older ssh-keygen may emit an MD5 hex fingerprint (aa:bb:...).
        if (preg_match('/\b((?:MD5:)?(?:[0-9a-f]{2}:){15}[0-9a-f]{2})\b/i', $keygenOutput, $m)) {
            return $m[1];
        }
        return '';
    }

    // --- host-key discovery -------------------------------------------------

    /**
     * Discover a host's public key via
     *   ssh-keyscan -p <port> -T <timeout> <host>
     * Returns { ok, error?, hostKey? }. The hostKey is the raw ssh-keyscan
     * output (one or more "host keytype base64" lines), ready to pin into a
     * connection's remoteHostKey and materialise to known_hosts. Comment lines
     * (starting with '#') are stripped.
     *
     * @return array{ok:bool,error?:string,hostKey?:string}
     */
    public static function discoverHostKey(string $host, int $port = 22, int $timeout = 10): array
    {
        $host = trim($host);
        if ($host === '') {
            return ['ok' => false, 'error' => 'Host is required.'];
        }
        // Reject anything that isn't a plausible hostname/IP, and in particular
        // a leading '-' (option-injection into ssh-keyscan).
        if (!self::isValidHost($host)) {
            return ['ok' => false, 'error' => 'Invalid host: ' . $host];
        }
        if ($port < 1 || $port > 65535) {
            return ['ok' => false, 'error' => 'Invalid port.'];
        }
        if ($timeout < 1 || $timeout > 600) {
            $timeout = 10;
        }

        [$code, $stdout, $stderr] = static::runKeyscan([
            'ssh-keyscan', '-p', (string) $port, '-T', (string) $timeout, '--', $host,
        ]);

        $hostKey = self::filterKeyscanOutput(is_string($stdout) ? $stdout : '');
        if ($hostKey === '') {
            $msg = self::firstLine(is_string($stderr) ? $stderr : '');
            return [
                'ok'    => false,
                'error' => 'No host key returned. The host may be unreachable or not running SSH'
                    . ($msg !== '' ? ' (' . $msg . ')' : '') . '.',
            ];
        }
        // ssh-keyscan returns 0 even when it printed keys; only flag a hard
        // failure when we got no usable keys (handled above).
        unset($code);
        return ['ok' => true, 'hostKey' => $hostKey];
    }

    /**
     * Strip comment/blank lines from ssh-keyscan output, leaving only host-key
     * lines. PURE.
     */
    public static function filterKeyscanOutput(string $output): string
    {
        $lines = preg_split('/\r?\n/', $output) ?: [];
        $kept  = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            $kept[] = $trimmed;
        }
        return implode("\n", $kept);
    }

    /**
     * Validate a hostname or IP literal for safe use as an ssh-keyscan argument.
     * Accepts DNS names (incl. Tailscale-style fully-qualified names), IPv4, and
     * bracketed/bare IPv6; rejects whitespace, shell metacharacters, and a
     * leading '-'. PURE.
     */
    public static function isValidHost(string $host): bool
    {
        $host = trim($host);
        if ($host === '' || $host[0] === '-') {
            return false;
        }
        // No whitespace or shell metacharacters.
        if (preg_match('/[\s;&|`$()<>"\'\\\\]/', $host)) {
            return false;
        }
        // Strip IPv6 brackets for the literal check.
        $bare = (strlen($host) > 1 && $host[0] === '[' && substr($host, -1) === ']')
            ? substr($host, 1, -1)
            : $host;
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        // Hostname: labels of [A-Za-z0-9-], dot-separated, no leading/trailing
        // dot or hyphen on a label.
        return (bool) preg_match(
            '/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/',
            $host
        );
    }

    // --- helpers ------------------------------------------------------------

    /** First non-empty trimmed line of a blob. */
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

    /** Create a private 0700 temp dir, or '' on failure. */
    private static function tempDir(): string
    {
        $base = sys_get_temp_dir() . '/ur-keygen-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!@mkdir($base, 0700, true) && !is_dir($base)) {
            return '';
        }
        @chmod($base, 0700);
        return $base;
    }

    /** Recursively remove a temp dir (best-effort). */
    private static function rmTempDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::rmTempDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // --- live-system seams (overridden in tests) ----------------------------

    /**
     * Run ssh-keygen with the given argv ARRAY (no shell). Returns
     * [exitCode, stdout, stderr]. Overridable in tests so the parsing/flow is
     * exercised against canned ssh-keygen output without ssh-keygen installed.
     *
     * @param array<int,string> $argv
     * @return array{0:int,1:string,2:string}
     */
    protected static function runKeygen(array $argv): array
    {
        return self::runArgv($argv);
    }

    /**
     * Run ssh-keyscan with the given argv ARRAY (no shell). Same contract /
     * override seam as runKeygen.
     *
     * @param array<int,string> $argv
     * @return array{0:int,1:string,2:string}
     */
    protected static function runKeyscan(array $argv): array
    {
        return self::runArgv($argv);
    }

    /**
     * proc_open an argv ARRAY without a shell, capturing stdout+stderr.
     *
     * @param array<int,string> $argv
     * @return array{0:int,1:string,2:string}
     */
    private static function runArgv(array $argv): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open($argv, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return [127, '', 'Failed to start ' . ($argv[0] ?? 'command') . '.'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [(int) $code, is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
    }
}

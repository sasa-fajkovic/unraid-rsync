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

    /**
     * Hard upper bound (seconds) on host-key discovery. ssh-keyscan's own -T
     * per-host wait is clamped to this, AND the PHP side enforces a wall-clock
     * deadline of this many seconds so a stalled/hanging child can never make
     * the request hang past it: ssh-keyscan runs DETACHED (orphaned to init) and
     * is only polled, so the php-fpm worker is never the parent that must wait on
     * it and always returns at the deadline (see runKeyscan/runDetached).
     * Overridable for tests via the UR_KEYSCAN_TIMEOUT_MAX constant (a small
     * value lets a test assert the wall-clock cap fires without itself hanging).
     */
    const DISCOVER_TIMEOUT_MAX = 30;

    /** A small grace added to the ssh-keyscan -T value to get the PHP wall-clock
     * deadline, so we let ssh-keyscan time out and report cleanly on its own
     * before we forcibly kill it; still clamped so the total never exceeds the
     * hard cap meaningfully. */
    const DISCOVER_TIMEOUT_GRACE = 2;

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
     * The hard upper bound (seconds) on host-key discovery, read from the
     * UR_KEYSCAN_TIMEOUT_MAX override when defined (so a test can shrink it to a
     * sub-second value), else DISCOVER_TIMEOUT_MAX. The override may only SHRINK
     * the cap; it can never raise it above the hard DISCOVER_TIMEOUT_MAX bound,
     * so the result is always clamped to the range [1, DISCOVER_TIMEOUT_MAX]
     * (keeping it in step with the UI's fixed 30s expectation).
     */
    public static function discoverTimeoutMax(): int
    {
        $max = (defined('UR_KEYSCAN_TIMEOUT_MAX') && (int) UR_KEYSCAN_TIMEOUT_MAX > 0)
            ? (int) UR_KEYSCAN_TIMEOUT_MAX
            : self::DISCOVER_TIMEOUT_MAX;
        return max(1, min($max, self::DISCOVER_TIMEOUT_MAX));
    }

    /**
     * Discover a host's public key via
     *   ssh-keyscan -p <port> -T <timeout> <host>
     * Returns { ok, error?, timedOut?, hostKey? }. The hostKey is the raw
     * ssh-keyscan output (one or more "host keytype base64" lines), ready to pin
     * into a connection's remoteHostKey and materialise to known_hosts. Comment
     * lines (starting with '#') are stripped.
     *
     * TIME-BOUNDED (max DISCOVER_TIMEOUT_MAX seconds, 30 by default):
     *   - ssh-keyscan's -T is clamped to min($timeout, max) so it bounds its own
     *     per-host wait; but we DO NOT rely on that alone.
     *   - the PHP side enforces a wall-clock deadline (the -T value + a small
     *     grace, still clamped at the hard cap) by running ssh-keyscan DETACHED
     *     (orphaned to init) and polling for its output: the php-fpm worker is
     *     never the parent that has to wait on the child, so it ALWAYS returns at
     *     the deadline even if ssh-keyscan ignores -T, stalls mid-read, or wedges
     *     in an unkillable syscall. On timeout the detached process group is
     *     SIGKILL'd best-effort and init reaps it. See runKeyscan/runDetached.
     *
     * On a wall-clock timeout the result carries timedOut=true and a clear
     * message; the handler maps that to a 504.
     *
     * @return array{ok:bool,error?:string,timedOut?:bool,hostKey?:string}
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

        // Clamp the ssh-keyscan -T per-host wait to the hard cap, and derive the
        // PHP wall-clock deadline from it. A small grace lets ssh-keyscan report
        // its own timeout cleanly before we forcibly kill it, but the grace can
        // never exceed the cap (so an overridden tiny cap stays tiny in tests),
        // and the deadline never exceeds the hard cap meaningfully.
        $max          = static::discoverTimeoutMax();
        $keyscanT     = max(1, min($timeout, $max));
        $grace        = (float) min(self::DISCOVER_TIMEOUT_GRACE, $max);
        $wallDeadline = (float) min($max + $grace, $keyscanT + $grace);

        [$code, $stdout, $stderr, $timedOut] = static::runKeyscan(
            ['ssh-keyscan', '-p', (string) $port, '-T', (string) $keyscanT, '--', $host],
            $wallDeadline
        );

        if ($timedOut) {
            return [
                'ok'       => false,
                'timedOut' => true,
                'error'    => 'Host key discovery timed out after ' . $max
                    . 's — check the host/port is reachable.',
            ];
        }

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
     * [exitCode, stdout, stderr, timedOut]. Overridable in tests so the
     * parsing/flow is exercised against canned ssh-keygen output without
     * ssh-keygen installed. ssh-keygen runs are not time-bounded (they are
     * local, fast, and already deadlock-safe via /dev/null stdin); the 4th
     * element is always false here.
     *
     * @param array<int,string> $argv
     * @return array{0:int,1:string,2:string,3:bool}
     */
    protected static function runKeygen(array $argv): array
    {
        return self::runArgv($argv);
    }

    /**
     * Run ssh-keyscan with the given argv ARRAY. Same override seam as runKeygen,
     * but TIME-BOUNDED: $deadlineSec is the wall-clock budget. A null deadline
     * means unbounded (delegates to the proc_open path).
     *
     * CRITICAL (the production wedge): when a deadline is given we DO NOT run
     * ssh-keyscan as a tracked proc_open child of the php-fpm worker. ssh-keyscan
     * against an unreachable host can wedge in an uninterruptible (D-state)
     * syscall that even SIGKILL can't end promptly; if the worker were its parent
     * it would have to waitpid() it eventually - in proc_close() OR in PHP's
     * process-resource destructor at request shutdown - and that wait would hang
     * the worker (and leave a zombie). Instead, runDetached() launches ssh-keyscan
     * DETACHED (setsid, backgrounded, orphaned to init) writing to temp files, and
     * the worker only POLLS those files within the deadline. The worker is never
     * the reaping parent, so it can ALWAYS return at the deadline regardless of
     * what ssh-keyscan does; init reaps the orphan once its syscall unblocks.
     *
     * @param array<int,string> $argv
     * @return array{0:int,1:string,2:string,3:bool} [code, stdout, stderr, timedOut]
     */
    protected static function runKeyscan(array $argv, ?float $deadlineSec = null): array
    {
        if ($deadlineSec === null) {
            return self::runArgv($argv);
        }
        return self::runDetached($argv, $deadlineSec);
    }

    /**
     * Launch a command DETACHED from the php-fpm worker and poll for its output
     * within a wall-clock deadline, so the worker is NEVER the process that must
     * waitpid() a (possibly unkillable) child - the only way to guarantee the
     * request returns at the deadline no matter how the child misbehaves.
     *
     * HOW IT STAYS NON-BLOCKING:
     *   - The actual command runs as `setsid sh -c 'exec <cmd> >out 2>err; echo $? >rc' &`
     *     so it becomes a session leader in its own process group, with its output
     *     redirected to temp files. The trailing `&` makes the launching shell
     *     return instantly; that launcher is the only thing proc_open/exec reaps
     *     (it exits immediately), so ssh-keyscan itself is orphaned to init.
     *   - We then POLL the rc/out files for up to $deadlineSec. On completion we
     *     read out/err and return. On timeout we SIGKILL the detached process
     *     GROUP (best-effort) and return timedOut=true WITHOUT waiting on it.
     *
     * SECURITY: this is a sanctioned shell use (like ur_launch_runner and the
     * Notify exec): EVERY argv element is escapeshellarg()'d, and the host/port
     * have already been validated by discoverHostKey before we get here. No
     * user-controlled value is interpolated unquoted.
     *
     * @param array<int,string> $argv
     * @param float              $deadlineSec wall-clock budget
     * @return array{0:int,1:string,2:string,3:bool} [code, stdout, stderr, timedOut]
     */
    private static function runDetached(array $argv, float $deadlineSec): array
    {
        if (empty($argv)) {
            return [127, '', 'No command to run.', false];
        }

        $dir = self::tempDir();
        if ($dir === '') {
            return [127, '', 'Unable to create a temp dir for host-key discovery.', false];
        }
        $outFile = $dir . '/out';
        $errFile = $dir . '/err';
        $rcFile  = $dir . '/rc';   // written last, signals completion + exit code
        $pgFile  = $dir . '/pgid'; // the detached session's pgid, for a timeout kill

        // Quote every argument; nothing here is interpolated unquoted.
        $quoted = implode(' ', array_map('escapeshellarg', $argv));

        // Prefer setsid so the child is its own session/process-group leader
        // (detached, and group-killable on timeout). If setsid is absent the
        // command still runs detached via the backgrounding shell; the timeout
        // kill then falls back to the recorded child pid.
        $setsid = '';
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $cand) {
            if (@is_executable($cand)) {
                $setsid = $cand . ' ';
                break;
            }
        }

        // The inner shell: record its own pid (which, under setsid, is the
        // session/process-group leader so a timeout can group-kill the whole
        // tree), run the command capturing stdout/stderr to files, then write the
        // command's exit code to the rc file LAST (its presence signals
        // completion to the poller). We do NOT `exec` the command - we must stay
        // alive to capture $? and write rc afterwards. The command is a child in
        // the same process group, so the negative-pgid SIGKILL on timeout still
        // reaches it.
        $inner = 'echo $$ > ' . escapeshellarg($pgFile) . '; '
               . $quoted . ' > ' . escapeshellarg($outFile)
               . ' 2> ' . escapeshellarg($errFile) . '; '
               . 'echo $? > ' . escapeshellarg($rcFile);
        // Outer: detach (setsid), run the inner shell, and background so the
        // launcher returns immediately. Redirect the launcher's own streams to
        // /dev/null so exec() doesn't block on them.
        $full = $setsid . 'sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &';

        $output = [];
        $launchCode = 0;
        @exec($full, $output, $launchCode);
        if ($launchCode !== 0) {
            self::rmTempDir($dir);
            return [127, '', 'Failed to start ' . $argv[0] . '.', false];
        }

        // Poll for completion (the rc file appearing) within the deadline.
        $endAt    = microtime(true) + max(0.0, $deadlineSec);
        $timedOut = true;
        while (microtime(true) < $endAt) {
            if (is_file($rcFile)) {
                $timedOut = false;
                break;
            }
            usleep(100000); // 100ms
        }

        $stdout = is_file($outFile) ? (string) @file_get_contents($outFile) : '';
        $stderr = is_file($errFile) ? (string) @file_get_contents($errFile) : '';

        if ($timedOut) {
            // Best-effort: SIGKILL the detached process GROUP so the whole
            // ssh-keyscan tree dies. We do NOT wait on it - it is orphaned to
            // init, which reaps it once its syscall unblocks. The worker returns
            // now regardless.
            self::killDetachedGroup($pgFile);
            // Read whatever partial stdout/stderr was flushed before the kill.
            $stdout = is_file($outFile) ? (string) @file_get_contents($outFile) : $stdout;
            $stderr = is_file($errFile) ? (string) @file_get_contents($errFile) : $stderr;
            // The temp dir is left for init's child to finish writing into and is
            // swept opportunistically by scheduleTempDirSweep() on a later call -
            // removing it now could race the still-running detached child.
            self::scheduleTempDirSweep();
            return [124, $stdout, $stderr, true]; // 124 == GNU timeout convention
        }

        $rc = is_file($rcFile) ? (int) trim((string) @file_get_contents($rcFile)) : 0;
        self::rmTempDir($dir);
        return [$rc, $stdout, $stderr, false];
    }

    /**
     * Best-effort SIGKILL of a detached session's process group, read from the
     * pgid file runDetached() wrote (the inner shell's own pid). Under setsid the
     * inner shell is the session/process-group leader, so signalling the NEGATIVE
     * pgid hits the whole group - the shell AND the ssh-keyscan child it spawned.
     * Never throws and never waits.
     */
    private static function killDetachedGroup(string $pgFile): void
    {
        if (!@is_file($pgFile) || !function_exists('posix_kill')) {
            return;
        }
        $pgid = (int) trim((string) @file_get_contents($pgFile));
        if ($pgid <= 1) {
            return; // never signal pid 0/1 or the whole session
        }
        $sig = defined('SIGKILL') ? SIGKILL : 9;
        // The group leader's pgid == its pid (it called setsid/became leader).
        if (!@posix_kill(-$pgid, $sig)) {
            @posix_kill($pgid, $sig); // fall back to the bare pid
        }
    }

    /**
     * Opportunistically remove leftover discovery temp dirs from PRIOR timed-out
     * runs (whose detached child we did not wait on, so we couldn't safely delete
     * them at the time). Only sweeps dirs older than a generous grace so we never
     * race a still-writing detached child. Best-effort and bounded.
     */
    private static function scheduleTempDirSweep(): void
    {
        $base = sys_get_temp_dir();
        $grace = self::DISCOVER_TIMEOUT_MAX + 60; // well past any in-flight child
        foreach (@glob($base . '/ur-keygen-*') ?: [] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $mtime = @filemtime($dir);
            if ($mtime !== false && (time() - $mtime) > $grace) {
                self::rmTempDir($dir);
            }
        }
    }

    /**
     * proc_open an argv ARRAY without a shell, capturing stdout+stderr. Used for
     * the LOCAL, FAST, deadlock-safe ssh-keygen runs (and the unbounded
     * ssh-keyscan path). NOT time-bounded: the time-bounded ssh-keyscan path goes
     * through runDetached() instead, precisely because the php-fpm worker must
     * never be the parent that has to waitpid() a possibly-unkillable child (that
     * is the production wedge - see runKeyscan/runDetached). ssh-keygen's runs are
     * local, fast, and exit on their own, so a tracked proc_open child is safe
     * here.
     *
     * DEADLOCK-SAFE: stdin is /dev/null (so ssh-keygen never blocks waiting for
     * an overwrite-prompt / passphrase answer), and stdout+stderr are drained
     * CONCURRENTLY with a non-blocking stream_select loop. Reading one pipe to
     * EOF before the other (the old stream_get_contents($pipes[1]) then
     * stream_get_contents($pipes[2]) sequence) can DEADLOCK: a process that fills
     * the ~64 KiB stderr pipe buffer while we are still blocked reading stdout
     * hangs forever (and vice versa) - the "stuck on Generating…" symptom. The
     * concurrent drain bounds memory and never blocks on the wrong pipe.
     *
     * @param array<int,string> $argv
     * @return array{0:int,1:string,2:string,3:bool} [code, stdout, stderr, timedOut]
     *         (the 4th element is always false here; kept for a uniform tuple)
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
            return [127, '', 'Failed to start ' . ($argv[0] ?? 'command') . '.', false];
        }

        // Drain stdout (fd 1) and stderr (fd 2) concurrently, non-blocking.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buf  = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        while (!empty($open)) {
            $read   = array_values($open);
            $write  = null;
            $except = null;
            $n = @stream_select($read, $write, $except, 1, 0);
            if ($n === false) {
                break; // interrupted/error - stop draining and reap below
            }
            if ($n === 0) {
                continue; // tick; keep draining
            }
            foreach ($open as $fd => $stream) {
                if (!in_array($stream, $read, true)) {
                    continue;
                }
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$fd]);
                    }
                    continue;
                }
                $buf[$fd] .= $chunk;
            }
        }
        // Close any pipe stream_select left open (e.g. on a select error).
        foreach ($open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $code = proc_close($proc);
        return [(int) $code, $buf[1], $buf[2], false];
    }
}

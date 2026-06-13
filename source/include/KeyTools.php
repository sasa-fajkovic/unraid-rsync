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
     * the request hang past it (the child is proc_terminate'd, then SIGKILL'd).
     * Overridable for tests via the UR_KEYSCAN_TIMEOUT_MAX constant (a small
     * value lets a test assert the wall-clock kill fires without itself hanging).
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
     *     grace, still clamped at the hard cap): if the child is still running at
     *     the deadline it is proc_terminate'd, then SIGKILL'd. The request can
     *     therefore never hang past ~30s even if ssh-keyscan ignores -T or the
     *     network stalls mid-read.
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
     * Run ssh-keyscan with the given argv ARRAY (no shell). Same override seam
     * as runKeygen, but TIME-BOUNDED: $deadlineSec is the wall-clock budget;
     * when the child is still running at the deadline runArgv terminates it
     * (proc_terminate, then SIGKILL) and returns timedOut=true (the 4th tuple
     * element). A null deadline means unbounded.
     *
     * @param array<int,string> $argv
     * @return array{0:int,1:string,2:string,3:bool} [code, stdout, stderr, timedOut]
     */
    protected static function runKeyscan(array $argv, ?float $deadlineSec = null): array
    {
        return self::runArgv($argv, $deadlineSec);
    }

    /**
     * proc_open an argv ARRAY without a shell, capturing stdout+stderr.
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
     * TIME-BOUNDED (optional): when $deadlineSec is non-null the drain loop also
     * enforces an overall wall-clock deadline. ssh-keyscan can hang well past its
     * own -T (DNS stalls, a half-open TCP connection that never sends a banner),
     * which would otherwise hold the whole request open; the deadline guarantees
     * the request returns. On expiry the child is proc_terminate'd (SIGTERM) and,
     * if it has not exited within a short grace, SIGKILL'd, then reaped - so no
     * zombie is left and the pipes are always closed. The partial output drained
     * so far is returned with timedOut=true. A null deadline is unbounded
     * (ssh-keygen's local, fast runs).
     *
     * @param array<int,string> $argv
     * @param float|null         $deadlineSec wall-clock budget, or null = unbounded
     * @return array{0:int,1:string,2:string,3:bool} [code, stdout, stderr, timedOut]
     */
    private static function runArgv(array $argv, ?float $deadlineSec = null): array
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

        $endAt = ($deadlineSec !== null) ? (microtime(true) + max(0.0, $deadlineSec)) : null;

        // Drain stdout (fd 1) and stderr (fd 2) concurrently, non-blocking.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $buf      = [1 => '', 2 => ''];
        $open     = [1 => $pipes[1], 2 => $pipes[2]];
        $timedOut = false;
        while (!empty($open)) {
            // Bound each select wait so the deadline is honoured even when the
            // child is silent (produces no output, no EOF) - the case a fixed
            // 1s tick alone would still catch, but computing the remaining budget
            // makes us return promptly at the deadline rather than up to 1s late.
            $waitSec = 1;
            if ($endAt !== null) {
                $remaining = $endAt - microtime(true);
                if ($remaining <= 0) {
                    $timedOut = true;
                    break;
                }
                $waitSec = ($remaining < 1) ? $remaining : 1;
            }

            $read   = array_values($open);
            $write  = null;
            $except = null;
            // stream_select takes whole seconds + microseconds; split $waitSec.
            $tvSec  = (int) $waitSec;
            $tvUsec = (int) (($waitSec - $tvSec) * 1000000);
            $n = @stream_select($read, $write, $except, $tvSec, $tvUsec);
            if ($n === false) {
                break; // interrupted/error - stop draining and reap below
            }
            if ($n === 0) {
                continue; // timeout tick; re-check the deadline at loop top
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
        // Close any pipe stream_select left open (e.g. on a select error or a
        // timeout that broke out with pipes still open).
        foreach ($open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($timedOut) {
            // The child is (probably) still running and would otherwise hold the
            // request open. Terminate it (SIGTERM -> SIGKILL) and reap it so no
            // zombie remains - but NEVER block the request waiting on a child the
            // kernel can't kill promptly.
            //
            // The trap: PHP's proc_close() ALWAYS performs a blocking waitpid()
            // on the child. SIGKILL is asynchronous - a child stuck in an
            // uninterruptible (D-state) syscall (a half-open TCP connect, a DNS
            // lookup that never returns - exactly the "host unreachable" case
            // here) does not die until that syscall returns, which can be far
            // past our cap. Calling proc_close() on it would then hang the whole
            // php-fpm worker indefinitely, serialising every other request behind
            // it (the production wedge). So we only proc_close() when terminateProc
            // has CONFIRMED the child is gone; otherwise we abandon the resource
            // (pipes already closed above) and return immediately. The kernel
            // reaps the SIGKILL'd child once its syscall unblocks; the worker is
            // freed now rather than held hostage.
            $reaped = self::terminateProc($proc);
            if ($reaped) {
                proc_close($proc);
            }
            // else: deliberately leak the proc resource rather than block. PHP's
            // request shutdown / the OS will reap the (already SIGKILL'd) child.
            return [124, $buf[1], $buf[2], true]; // 124 == GNU timeout's convention
        }

        $code = proc_close($proc);
        return [(int) $code, $buf[1], $buf[2], false];
    }

    /**
     * Terminate a still-running child from proc_open: SIGTERM first, and if it
     * does not exit within a short grace, SIGKILL. Best-effort and BOUNDED - it
     * polls proc_get_status for up to ~1s before the hard kill (so a well-behaved
     * child that exits on SIGTERM is reaped cleanly), then a further short window
     * after SIGKILL. The total wall-clock spent here is capped at ~1.5s so a
     * caller can never be blocked here past that.
     *
     * Returns TRUE when the child is CONFIRMED no longer running (so the caller
     * may safely proc_close() it without blocking), FALSE when it is still alive
     * after SIGKILL (a D-state child the kernel hasn't been able to kill yet) -
     * in which case the caller MUST NOT proc_close() it (that would block on
     * waitpid) and should abandon the resource instead. Does NOT itself reap
     * (proc_close) - that stays the caller's decision, gated on this result.
     *
     * @param resource $proc
     * @return bool true == child confirmed gone (safe to proc_close)
     */
    private static function terminateProc($proc): bool
    {
        if (!is_resource($proc)) {
            return true; // nothing to reap
        }
        $status = proc_get_status($proc);
        if (empty($status['running'])) {
            return true; // already exited
        }

        // SIGTERM (proc_terminate's default) - polite stop.
        @proc_terminate($proc, defined('SIGTERM') ? SIGTERM : 15);

        // Give it up to ~1s to exit, polling in short slices.
        $killBy = microtime(true) + 1.0;
        while (microtime(true) < $killBy) {
            $status = proc_get_status($proc);
            if (empty($status['running'])) {
                return true;
            }
            usleep(50000); // 50ms
        }

        // Still alive - escalate to SIGKILL.
        @proc_terminate($proc, defined('SIGKILL') ? SIGKILL : 9);

        // Poll briefly for the SIGKILL to take effect. A killable child dies
        // almost immediately; a child wedged in an uninterruptible syscall will
        // NOT, and we must not block on it - bail after a short grace and report
        // it as not-yet-reaped so the caller skips the blocking proc_close().
        $confirmBy = microtime(true) + 0.5;
        while (microtime(true) < $confirmBy) {
            $status = proc_get_status($proc);
            if (empty($status['running'])) {
                return true;
            }
            usleep(50000); // 50ms
        }

        $status = proc_get_status($proc);
        return empty($status['running']);
    }
}

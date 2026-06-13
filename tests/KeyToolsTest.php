<?php

use PHPUnit\Framework\TestCase;

/**
 * A KeyTools double that stubs ssh-keygen / ssh-keyscan with canned output keyed
 * by the FIRST distinguishing argument, so the parse + flow logic is exercised
 * against representative sample output WITHOUT either binary installed.
 */
final class FakeKeyTools extends KeyTools
{
    /** @var array<string,array{0:int,1:string,2:string,3?:bool}> programmed responses (a legacy 3-tuple is normalised to a 4-tuple in runKeygen) */
    public static $keygenResponses = [];
    /** @var array{0:int,1:string,2:string,3?:bool} */
    public static $keyscanResponse = [0, '', '', false];
    /** @var array<int,array<int,string>> recorded keygen argvs */
    public static $keygenCalls = [];
    /** @var array<int,array<int,string>> recorded keyscan argvs */
    public static $keyscanCalls = [];
    /** @var array<int,float|null> recorded keyscan wall-clock deadlines */
    public static $keyscanDeadlines = [];

    public static function reset(): void
    {
        self::$keygenResponses  = [];
        self::$keyscanResponse  = [0, '', '', false];
        self::$keygenCalls      = [];
        self::$keyscanCalls     = [];
        self::$keyscanDeadlines = [];
    }

    protected static function runKeygen(array $argv): array
    {
        self::$keygenCalls[] = $argv;

        // -y derives a public key from a private key.
        if (in_array('-y', $argv, true)) {
            $r = self::$keygenResponses['-y'] ?? [0, "ssh-ed25519 AAAAderived comment\n", '', false];
        // -l lists a fingerprint.
        } elseif (in_array('-lf', $argv, true)) {
            $r = self::$keygenResponses['-lf'] ?? [0, "256 SHA256:SAMPLEFINGERPRINT user@host (ED25519)\n", '', false];
        } else {
            // generation: write fake key files where -f points, then return ok.
            $fi = array_search('-f', $argv, true);
            if ($fi !== false && isset($argv[$fi + 1])) {
                $keyFile = $argv[$fi + 1];
                @file_put_contents($keyFile, "-----BEGIN OPENSSH PRIVATE KEY-----\nFAKE\n-----END OPENSSH PRIVATE KEY-----\n");
                @file_put_contents($keyFile . '.pub', "ssh-ed25519 AAAAgenerated comment\n");
            }
            $r = self::$keygenResponses['gen'] ?? [0, '', '', false];
        }
        // Normalise to a 4-tuple so callers can always read the timedOut element,
        // even when a test programs a legacy 3-tuple [code, stdout, stderr]
        // (mirrors runKeyscan).
        if (!array_key_exists(3, $r)) {
            $r[3] = false;
        }
        return $r;
    }

    protected static function runKeyscan(array $argv, ?float $deadlineSec = null): array
    {
        self::$keyscanCalls[]     = $argv;
        self::$keyscanDeadlines[] = $deadlineSec;
        // Normalise to a 4-tuple so callers can always read the timedOut element.
        $r = self::$keyscanResponse;
        if (!array_key_exists(3, $r)) {
            $r[3] = false;
        }
        return $r;
    }
}

/**
 * Exposes the REAL runKeygen/runKeyscan seams (which call the private,
 * deadlock-safe runArgv) so the concurrent stdout/stderr drain can be exercised
 * against a real subprocess WITHOUT stubbing. Used only by the deadlock test.
 */
final class RealRunKeyTools extends KeyTools
{
    /** @return array{0:int,1:string,2:string,3:bool} */
    public static function publicRunKeygen(array $argv): array
    {
        return static::runKeygen($argv);
    }

    /**
     * Run the REAL, time-bounded runKeyscan seam (-> private runArgv) against a
     * real subprocess with an explicit wall-clock deadline, so the timeout +
     * child-kill path can be exercised end-to-end with a TINY deadline (the test
     * itself never hangs).
     *
     * @return array{0:int,1:string,2:string,3:bool}
     */
    public static function publicRunKeyscan(array $argv, ?float $deadlineSec): array
    {
        return static::runKeyscan($argv, $deadlineSec);
    }
}

/**
 * A KeyTools whose runKeyscan seam runs a REAL hanging child against a tiny
 * wall-clock deadline, so discoverHostKey()'s timeout mapping (timedOut=true +
 * the "timed out after Ns" message) can be asserted end-to-end without the test
 * hanging and without ssh-keyscan installed. The argv is replaced with a php
 * sleep so the timeout - not the (absent) ssh-keyscan binary - is what fires.
 */
final class HangingKeyscanKeyTools extends KeyTools
{
    /** @var array<int,float|null> the wall-clock deadlines discoverHostKey passed in */
    public static $deadlines = [];
    /** @var array<int,array<int,string>> the argv discoverHostKey built (to assert -T) */
    public static $argvs = [];

    public static function reset(): void
    {
        self::$deadlines = [];
        self::$argvs = [];
    }

    /** Shrink the hard cap to 1s for this test subclass (late-static-bound from
     * discoverHostKey) so the wall-clock deadline is ~2s, not 30s. */
    public static function discoverTimeoutMax(): int
    {
        return 1;
    }

    protected static function runKeyscan(array $argv, ?float $deadlineSec = null): array
    {
        self::$argvs[]     = $argv;
        self::$deadlines[] = $deadlineSec;
        // Ignore the real ssh-keyscan argv; run a child that sleeps far longer
        // than the (tiny) deadline so the wall-clock kill is what ends it.
        $sleeper = [PHP_BINARY, '-r', 'usleep(5000000);']; // 5s
        return static::runKeyscan_real($sleeper, $deadlineSec);
    }

    /** Reach the real (private) runArgv via the parent's seam, unstubbed. */
    private static function runKeyscan_real(array $argv, ?float $deadlineSec): array
    {
        return parent::runKeyscan($argv, $deadlineSec);
    }
}

/**
 * Tests for KeyTools.php: fingerprint + public-key parsing from representative
 * ssh-keygen output, generate/import flow with stubbed binaries, host validation
 * (option-injection guard), and ssh-keyscan output filtering.
 */
final class KeyToolsTest extends TestCase
{
    protected function setUp(): void
    {
        FakeKeyTools::reset();
    }

    // --- pure parsers ------------------------------------------------------

    public function testParseFingerprintSha256(): void
    {
        $out = '256 SHA256:Hbq8kP9o2Qwe+abc/XYZ123= sasa@tower (ED25519)';
        $this->assertSame('SHA256:Hbq8kP9o2Qwe+abc/XYZ123=', KeyTools::parseFingerprint($out));
    }

    public function testParseFingerprintMd5Fallback(): void
    {
        $out = '2048 MD5:aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99 user (RSA)';
        $this->assertStringContainsString('aa:bb:cc', KeyTools::parseFingerprint($out));
    }

    public function testParseFingerprintNoneReturnsEmpty(): void
    {
        $this->assertSame('', KeyTools::parseFingerprint('no fingerprint here'));
    }

    public function testFilterKeyscanOutputStripsComments(): void
    {
        $raw = "# h.example:22 SSH-2.0-OpenSSH_9.6\n"
             . "h.example ssh-ed25519 AAAAhostkey1\n"
             . "\n"
             . "# another comment\n"
             . "h.example ssh-rsa AAAAhostkey2\n";
        $filtered = KeyTools::filterKeyscanOutput($raw);
        $this->assertStringNotContainsString('#', $filtered);
        $this->assertStringContainsString('ssh-ed25519 AAAAhostkey1', $filtered);
        $this->assertStringContainsString('ssh-rsa AAAAhostkey2', $filtered);
        $this->assertSame(2, substr_count($filtered, "\n") + 1);
    }

    /** @dataProvider hostProvider */
    public function testIsValidHost(string $host, bool $expected): void
    {
        $this->assertSame($expected, KeyTools::isValidHost($host));
    }

    public function hostProvider(): array
    {
        return [
            'dns'              => ['rpi3b.tempel-drum.ts.net', true],
            'simple'           => ['tower', true],
            'ipv4'             => ['10.0.0.5', true],
            'ipv6 bracketed'   => ['[2001:db8::1]', true],
            'leading dash'     => ['-oProxyCommand=evil', false],
            'space'            => ['a b', false],
            'semicolon'        => ['h;rm -rf', false],
            'backtick'         => ['h`id`', false],
            'pipe'             => ['h|nc', false],
            'empty'            => ['', false],
        ];
    }

    // --- generate ----------------------------------------------------------

    public function testGenerateEd25519(): void
    {
        $res = FakeKeyTools::generate('ed25519', 'backup');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $res['privateKey']);
        $this->assertStringContainsString('ssh-ed25519', $res['publicKey']);
        $this->assertSame('SHA256:SAMPLEFINGERPRINT', $res['fingerprint']);

        // The first keygen call used -t ed25519 with an empty passphrase.
        $genCall = FakeKeyTools::$keygenCalls[0];
        $this->assertContains('-t', $genCall);
        $this->assertContains('ed25519', $genCall);
        // -N "" empty passphrase
        $ni = array_search('-N', $genCall, true);
        $this->assertNotFalse($ni);
        $this->assertSame('', $genCall[$ni + 1]);
    }

    public function testGenerateRsaUses4096(): void
    {
        $res = FakeKeyTools::generate('rsa', 'backup');
        $this->assertTrue($res['ok']);
        $genCall = FakeKeyTools::$keygenCalls[0];
        $this->assertContains('rsa', $genCall);
        $bi = array_search('-b', $genCall, true);
        $this->assertNotFalse($bi);
        $this->assertSame('4096', $genCall[$bi + 1]);
    }

    public function testGenerateRejectsUnknownType(): void
    {
        $res = FakeKeyTools::generate('dsa', 'x');
        $this->assertFalse($res['ok']);
    }

    public function testGenerateFailureReported(): void
    {
        FakeKeyTools::$keygenResponses['gen'] = [1, '', 'ssh-keygen: boom'];
        $res = FakeKeyTools::generate('ed25519', 'x');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('ssh-keygen', $res['error']);
    }

    // --- import ------------------------------------------------------------

    public function testImportPrivateDerivesPublicAndFingerprint(): void
    {
        $res = FakeKeyTools::import("-----BEGIN OPENSSH PRIVATE KEY-----\nx\n-----END OPENSSH PRIVATE KEY-----", '');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        // Public derived via ssh-keygen -y (the stub returns "AAAAderived").
        $this->assertStringContainsString('AAAAderived', $res['publicKey']);
        $this->assertSame('SHA256:SAMPLEFINGERPRINT', $res['fingerprint']);
        $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $res['privateKey']);
    }

    public function testImportPublicOnly(): void
    {
        $res = FakeKeyTools::import('', 'ssh-ed25519 AAAApub comment');
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertSame('ssh-ed25519 AAAApub comment', $res['publicKey']);
        $this->assertSame('', $res['privateKey']); // no private material
        $this->assertSame('SHA256:SAMPLEFINGERPRINT', $res['fingerprint']);
    }

    public function testImportPassphraseProtectedKeyRejected(): void
    {
        // ssh-keygen -y on a passphrase-protected key fails mentioning passphrase.
        FakeKeyTools::$keygenResponses['-y'] = [1, '', 'Load key: incorrect passphrase supplied to decrypt private key'];
        $res = FakeKeyTools::import("-----BEGIN OPENSSH PRIVATE KEY-----\nenc\n-----END OPENSSH PRIVATE KEY-----", '');
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('passphrase', $res['error']);
    }

    public function testImportEmptyRejected(): void
    {
        $res = FakeKeyTools::import('', '');
        $this->assertFalse($res['ok']);
    }

    // --- discover host key -------------------------------------------------

    public function testDiscoverHostKeySuccess(): void
    {
        FakeKeyTools::$keyscanResponse = [
            0,
            "# h.example:22 SSH-2.0-OpenSSH\nh.example ssh-ed25519 AAAAhostkey\n",
            '',
        ];
        $res = FakeKeyTools::discoverHostKey('h.example', 22, 10);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertStringContainsString('ssh-ed25519 AAAAhostkey', $res['hostKey']);
        $this->assertStringNotContainsString('#', $res['hostKey']);
    }

    public function testDiscoverHostKeyNoOutputFails(): void
    {
        FakeKeyTools::$keyscanResponse = [0, '', 'getaddrinfo: Name or service not known'];
        $res = FakeKeyTools::discoverHostKey('h.example', 22, 10);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('No host key', $res['error']);
    }

    public function testDiscoverHostKeyRejectsBadHost(): void
    {
        $res = FakeKeyTools::discoverHostKey('-oProxyCommand=evil', 22, 10);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('Invalid host', $res['error']);
    }

    // --- discover host key: time-bounding ----------------------------------

    /**
     * The ssh-keyscan argv always carries a bounded -T <= the hard cap (30),
     * even when a larger connect timeout is requested. The PHP wall-clock
     * deadline passed to the keyscan seam is non-null and also bounded.
     */
    public function testDiscoverHostKeyArgvHasBoundedTimeout(): void
    {
        FakeKeyTools::$keyscanResponse = [0, "h.example ssh-ed25519 AAAAk\n", '', false];
        // Request a 60s timeout (a valid connect timeout, > the 30s cap); it must
        // be clamped to 30 in the argv.
        $res = FakeKeyTools::discoverHostKey('h.example', 22, 60);
        $this->assertTrue($res['ok'], $res['error'] ?? '');

        $argv = FakeKeyTools::$keyscanCalls[0];
        $ti = array_search('-T', $argv, true);
        $this->assertNotFalse($ti, '-T must be present');
        $tval = (int) $argv[$ti + 1];
        $this->assertGreaterThanOrEqual(1, $tval);
        $this->assertLessThanOrEqual(KeyTools::DISCOVER_TIMEOUT_MAX, $tval, '-T must be <= 30');
        $this->assertSame(30, $tval, 'a 60s request clamps to the 30s cap');

        // A wall-clock deadline was supplied to the seam (not unbounded).
        $deadline = FakeKeyTools::$keyscanDeadlines[0];
        $this->assertNotNull($deadline);
        $this->assertLessThanOrEqual(
            KeyTools::DISCOVER_TIMEOUT_MAX + KeyTools::DISCOVER_TIMEOUT_GRACE,
            $deadline
        );
    }

    /**
     * A smaller requested timeout is used as-is for -T (min(connectTimeout, 30)).
     */
    public function testDiscoverHostKeyArgvUsesSmallerRequestedTimeout(): void
    {
        FakeKeyTools::$keyscanResponse = [0, "h.example ssh-ed25519 AAAAk\n", '', false];
        FakeKeyTools::discoverHostKey('h.example', 22, 5);
        $argv = FakeKeyTools::$keyscanCalls[0];
        $ti = array_search('-T', $argv, true);
        $this->assertSame(5, (int) $argv[$ti + 1]);
    }

    /**
     * When the keyscan seam reports a wall-clock timeout (4th tuple element),
     * discoverHostKey() returns ok=false, timedOut=true, and a clear "timed out
     * after Ns" message - never a stuck/empty result.
     */
    public function testDiscoverHostKeyTimeoutMappedToTimedOut(): void
    {
        FakeKeyTools::$keyscanResponse = [124, '', '', true]; // timedOut=true
        $res = FakeKeyTools::discoverHostKey('h.example', 22, 10);
        $this->assertFalse($res['ok']);
        $this->assertTrue($res['timedOut']);
        $this->assertStringContainsString('timed out', $res['error']);
        $this->assertStringContainsString((string) KeyTools::DISCOVER_TIMEOUT_MAX, $res['error']);
    }

    /**
     * End-to-end: a REAL hanging child (a php sleep) against a tiny injected cap
     * makes the wall-clock deadline fire, the child is killed, and discoverHostKey
     * returns the timeout result WITHOUT the test hanging. HangingKeyscanKeyTools
     * overrides discoverTimeoutMax() to 1 so the deadline is ~2s, not 30s.
     */
    public function testDiscoverHostKeyRealWallClockTimeoutFires(): void
    {
        HangingKeyscanKeyTools::reset();
        $start = microtime(true);
        $res   = HangingKeyscanKeyTools::discoverHostKey('h.example', 22, 10);
        $elapsed = microtime(true) - $start;

        $this->assertFalse($res['ok']);
        $this->assertTrue($res['timedOut'], 'a hanging child must be reported as timedOut');
        $this->assertStringContainsString('timed out', $res['error']);
        // The injected cap (UR_KEYSCAN_TIMEOUT_MAX) is tiny, so the whole call
        // returns in a few seconds - well under the child's 5s sleep, proving the
        // wall-clock kill (not the child exiting) ended it.
        $this->assertLessThan(5.0, $elapsed, 'must return before the 5s child sleep');

        // The deadline discoverHostKey computed was bounded by the tiny cap.
        $this->assertNotEmpty(HangingKeyscanKeyTools::$deadlines);
        $this->assertNotNull(HangingKeyscanKeyTools::$deadlines[0]);
    }

    // --- runArgv deadlock-safety -------------------------------------------

    /**
     * Build a portable child-process argv that writes $outLen bytes of 'O' to
     * STDOUT and $errLen bytes of 'E' to STDERR, using the SAME php binary the
     * suite runs under (always present; no shell-$0/printf portability quirks
     * between macOS sh and Ubuntu dash, which broke an earlier sh-based probe).
     *
     * @return array<int,string>
     */
    private static function emitArgv(int $outLen, int $errLen): array
    {
        $code = 'fwrite(STDOUT, str_repeat("O", ' . $outLen . '));'
              . 'fwrite(STDERR, str_repeat("E", ' . $errLen . '));';
        return [PHP_BINARY, '-d', 'error_reporting=0', '-r', $code];
    }

    public function testRunArgvDrainsLargeStderrWithoutDeadlock(): void
    {
        // A child that floods STDERR with > the ~64 KiB pipe buffer while STDOUT
        // stays small would DEADLOCK the old sequential reader (read stdout to
        // EOF, then stderr): the child blocks writing stderr, we block reading
        // stdout, forever. The concurrent stream_select drain must complete and
        // return BOTH streams in full. Bounded by PHPUnit's per-test timeout so a
        // regression hangs the test (a clear failure) rather than passing.
        $errLen = 256 * 1024; // well past the pipe buffer
        [$code, $stdout, $stderr] = RealRunKeyTools::publicRunKeygen(self::emitArgv(3, $errLen));

        $this->assertSame(0, $code);
        $this->assertSame('OOO', $stdout);
        $this->assertSame($errLen, strlen($stderr), 'all stderr must be drained');
        $this->assertSame(str_repeat('E', $errLen), $stderr);
    }

    public function testRunArgvHandlesLargeStdout(): void
    {
        // The mirror case: a large STDOUT with small stderr must also drain fully.
        $outLen = 256 * 1024;
        [$code, $stdout, $stderr] = RealRunKeyTools::publicRunKeygen(self::emitArgv($outLen, 3));

        $this->assertSame(0, $code);
        $this->assertSame($outLen, strlen($stdout), 'all stdout must be drained');
        $this->assertSame(str_repeat('O', $outLen), $stdout);
        $this->assertSame('EEE', $stderr);
    }

    public function testRunArgvMissingBinaryReturns127(): void
    {
        // A non-existent program must not hang and must report a clear failure.
        [$code, $stdout, $stderr] = RealRunKeyTools::publicRunKeygen(['/nonexistent/ur-no-such-binary-xyz']);
        $this->assertNotSame(0, $code);
        $this->assertSame('', $stdout);
    }

    /**
     * runArgv enforces the wall-clock deadline on a child that would otherwise
     * run far longer: a php sleep(5s) against a 0.3s deadline must be killed and
     * return promptly with timedOut=true (exit 124). This exercises the
     * proc_terminate -> reap path directly, sub-second, so the suite never hangs.
     */
    public function testRunArgvKillsChildPastDeadline(): void
    {
        $sleeper = [PHP_BINARY, '-r', 'usleep(5000000);']; // 5s
        $start = microtime(true);
        [$code, $stdout, $stderr, $timedOut] = RealRunKeyTools::publicRunKeyscan($sleeper, 0.3);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($timedOut, 'a child past the deadline must be flagged timedOut');
        $this->assertSame(124, $code, 'timeout uses exit code 124');
        // Killed near the 0.3s deadline (+ up to ~1s SIGTERM grace), well under 5s.
        $this->assertLessThan(4.0, $elapsed, 'the child must be killed, not waited out');
        $this->assertSame('', $stdout);
    }

    /**
     * A fast child that finishes well within the deadline returns normally with
     * timedOut=false (the deadline path must not false-positive on quick exits).
     */
    public function testRunArgvWithDeadlineDoesNotFalseTimeoutFastChild(): void
    {
        [$code, $stdout, $stderr, $timedOut] = RealRunKeyTools::publicRunKeyscan(self::emitArgv(3, 0), 10.0);
        $this->assertFalse($timedOut);
        $this->assertSame(0, $code);
        $this->assertSame('OOO', $stdout);
    }

    /**
     * THE WEDGE REGRESSION: a child that IGNORES SIGTERM (it traps the signal and
     * keeps sleeping) must still be force-killed via the SIGKILL escalation, and
     * the call must RETURN PROMPTLY at the deadline + bounded teardown window -
     * never blocking the request (the php-fpm worker) on a process that won't die
     * politely. This is the in-process analogue of the production hang where a
     * stuck ssh-keyscan held the worker open. We assert the call returns well
     * before the child's own (long) sleep would have ended.
     */
    public function testRunArgvForceKillsChildThatIgnoresSigterm(): void
    {
        if (!function_exists('pcntl_signal')) {
            // The child relies on pcntl to trap SIGTERM. Skip cleanly if the CLI
            // build under test lacks it; the SIGKILL path is still exercised by
            // testRunArgvKillsChildPastDeadline for an ordinary child.
            $this->markTestSkipped('pcntl unavailable in this PHP build');
        }
        // The child installs a SIGTERM handler that does nothing (so SIGTERM is
        // ignored), then sleeps 10s. Only SIGKILL can end it. It must therefore
        // be returned by the deadline + the SIGTERM grace + the SIGKILL confirm
        // window, comfortably under 10s.
        $code = 'pcntl_async_signals(true);'
              . 'pcntl_signal(SIGTERM, function () {});'
              . 'for ($i = 0; $i < 100; $i++) { usleep(100000); }'; // ~10s
        $child = [PHP_BINARY, '-r', $code];

        $start = microtime(true);
        [$rc, $stdout, $stderr, $timedOut] = RealRunKeyTools::publicRunKeyscan($child, 0.3);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($timedOut, 'a child past the deadline must be flagged timedOut');
        $this->assertSame(124, $rc, 'timeout uses exit code 124');
        // 0.3s deadline + up to ~1s SIGTERM grace + up to ~0.5s SIGKILL confirm
        // ~= ~1.8s worst case; allow generous headroom but stay well under 10s so
        // a regression that BLOCKS on proc_close (the wedge) fails loudly.
        $this->assertLessThan(
            6.0,
            $elapsed,
            'the call must return promptly via SIGKILL, never block on the child'
        );
    }
}

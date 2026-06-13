<?php

use PHPUnit\Framework\TestCase;

/**
 * A KeyTools double that stubs ssh-keygen / ssh-keyscan with canned output keyed
 * by the FIRST distinguishing argument, so the parse + flow logic is exercised
 * against representative sample output WITHOUT either binary installed.
 */
final class FakeKeyTools extends KeyTools
{
    /** @var array<string,array{0:int,1:string,2:string}> programmed responses */
    public static $keygenResponses = [];
    /** @var array{0:int,1:string,2:string} */
    public static $keyscanResponse = [0, '', ''];
    /** @var array<int,array<int,string>> recorded keygen argvs */
    public static $keygenCalls = [];

    public static function reset(): void
    {
        self::$keygenResponses = [];
        self::$keyscanResponse = [0, '', ''];
        self::$keygenCalls = [];
    }

    protected static function runKeygen(array $argv): array
    {
        self::$keygenCalls[] = $argv;

        // -y derives a public key from a private key.
        if (in_array('-y', $argv, true)) {
            return self::$keygenResponses['-y'] ?? [0, "ssh-ed25519 AAAAderived comment\n", ''];
        }
        // -l lists a fingerprint.
        if (in_array('-lf', $argv, true)) {
            return self::$keygenResponses['-lf'] ?? [0, "256 SHA256:SAMPLEFINGERPRINT user@host (ED25519)\n", ''];
        }
        // generation: write fake key files where -f points, then return ok.
        $fi = array_search('-f', $argv, true);
        if ($fi !== false && isset($argv[$fi + 1])) {
            $keyFile = $argv[$fi + 1];
            @file_put_contents($keyFile, "-----BEGIN OPENSSH PRIVATE KEY-----\nFAKE\n-----END OPENSSH PRIVATE KEY-----\n");
            @file_put_contents($keyFile . '.pub', "ssh-ed25519 AAAAgenerated comment\n");
        }
        return self::$keygenResponses['gen'] ?? [0, '', ''];
    }

    protected static function runKeyscan(array $argv): array
    {
        return self::$keyscanResponse;
    }
}

/**
 * Exposes the REAL runKeygen/runKeyscan seams (which call the private,
 * deadlock-safe runArgv) so the concurrent stdout/stderr drain can be exercised
 * against a real subprocess WITHOUT stubbing. Used only by the deadlock test.
 */
final class RealRunKeyTools extends KeyTools
{
    /** @return array{0:int,1:string,2:string} */
    public static function publicRunKeygen(array $argv): array
    {
        return static::runKeygen($argv);
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
}

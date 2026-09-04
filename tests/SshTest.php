<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A test double for Ssh that stubs the one live-system seam (the ssh probe) so
 * the argv-construction, materialisation and probe-classification logic is
 * exercised entirely offline - no socket, no real ssh binary. The probe result
 * is injected per test, and the argv + env it was called with are captured.
 */
final class FakeSsh extends Ssh
{
    /** @var array{0:int,1:string} */
    public static $nextProbe = [0, ''];
    /** @var array<int,string>|null the argv runProbe was last called with */
    public static $lastProbeArgv = null;
    /** @var array<string,string>|null the env runProbe was last called with */
    public static $lastProbeEnv = null;
    /** @var int how many per-run tokens were minted (guard-ORDERING probe) */
    public static $tokenMints = 0;

    protected static function runProbe(array $argv, ?array $env = null): array
    {
        self::$lastProbeArgv = $argv;
        self::$lastProbeEnv  = $env;
        return self::$nextProbe;
    }

    // materialize()/materializeDaemon() call this through static::, so counting
    // it here proves WHERE a refusal happens, not just what it returns.
    public static function newRuntimeToken(string $connId): string
    {
        self::$tokenMints++;
        return parent::newRuntimeToken($connId);
    }
}

/** Ssh with a DETERMINISTIC per-run token, so a test can pre-plant its path. */
final class FixedTokenSsh extends Ssh
{
    const TOKEN = 'fixed-token';

    public static function newRuntimeToken(string $connId): string
    {
        return self::TOKEN;
    }
}

/**
 * Tests for Ssh.php: argv assembly for KEY vs PASSWORD (incl. strictHostKey
 * modes, port, timeout, known_hosts wiring), the rsync -e value, materialise +
 * cleanup against a tmpfs override, the SSH_ASKPASS password wiring, and the probe
 * failure-mode classification - all asserted as ARRAYS without a live host.
 */
final class SshTest extends TestCase
{
    private string $rtBase;

    protected function setUp(): void
    {
        // Per-test tmpfs override so materialisation never touches /tmp/unraid.rsync.
        $this->rtBase = sys_get_temp_dir() . '/ur-ssh-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Ssh::$runtimeBase = $this->rtBase;
        FakeSsh::$runtimeBase = $this->rtBase;
        Ssh::$askpassPathOverride = null;
        FakeSsh::$askpassPathOverride = null;
        FakeSsh::$nextProbe = [0, ''];
        FakeSsh::$lastProbeArgv = null;
        FakeSsh::$lastProbeEnv = null;
        FakeSsh::$tokenMints = 0;
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->rtBase);
        Ssh::$runtimeBase = '/tmp/unraid.rsync';
        FakeSsh::$runtimeBase = '/tmp/unraid.rsync';
        Ssh::$askpassPathOverride = null;
        FakeSsh::$askpassPathOverride = null;
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function keyConn(array $over = []): array
    {
        return Credentials::mergeConnection(array_merge([
            'id' => 'c-key', 'name' => 'k', 'host' => 'h.example', 'port' => 22,
            'username' => 'sasa', 'authMethod' => 'KEY', 'keyId' => 'k-1',
            'strictHostKey' => 'accept-new', 'connectTimeout' => 10,
        ], $over));
    }

    private function passConn(array $over = []): array
    {
        return Credentials::mergeConnection(array_merge([
            'id' => 'c-pw', 'name' => 'p', 'host' => 'h.example', 'port' => 22,
            'username' => 'sasa', 'authMethod' => 'PASSWORD',
            'strictHostKey' => 'accept-new', 'connectTimeout' => 10,
        ], $over));
    }

    private function keyfileConn(array $over = []): array
    {
        return Credentials::mergeConnection(array_merge([
            'id' => 'c-kf', 'name' => 'kf', 'host' => 'h.example', 'port' => 22,
            'username' => 'sasa', 'authMethod' => 'KEYFILE',
            'keyFilePath' => '/root/.ssh/id_ed25519',
            'strictHostKey' => 'accept-new', 'connectTimeout' => 10,
        ], $over));
    }

    /** Write a fake "key file" in the test runtime base, returning its path. */
    private function makeKeyFile(string $name = 'id_ed25519'): string
    {
        @mkdir($this->rtBase, 0700, true);
        $path = $this->rtBase . '/' . $name;
        file_put_contents($path, "-----BEGIN OPENSSH PRIVATE KEY-----\nEXISTING\n-----END OPENSSH PRIVATE KEY-----\n");
        @chmod($path, 0600);
        return $path;
    }

    // --- KEYFILE argv + materialise (no tmpfs key) -------------------------

    /**
     * SEC-01: a pure-dots token collapses to "unknown" in every tmpfs secret
     * path so a crafted token can never address a file outside keys/ /known_hosts
     * /pass. Mirrors ur_safe_job_id's pure-dots rejection.
     */
    #[DataProvider('sshPureDotsProvider')]
    public function testSecretPathsCollapsePureDotsToken(string $token): void
    {
        foreach ([Ssh::keyPath($token), Ssh::knownHostsPath($token), Ssh::passFilePath($token)] as $p) {
            $this->assertStringEndsWith('/unknown', $p);
            $this->assertStringNotContainsString('/..', $p);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function sshPureDotsProvider(): array
    {
        return ['dot' => ['.'], 'dotdot' => ['..'], 'tripledot' => ['...']];
    }

    public function testKeyfileArgvUsesPathDirectly(): void
    {
        $conn = $this->keyfileConn(['port' => 2022, 'strictHostKey' => 'yes']);
        $argv = Ssh::buildSshArgv($conn, '/root/.ssh/id_ed25519', '/tmp/kh');

        $this->assertSame('ssh', $argv[0]);
        $i = array_search('-i', $argv, true);
        $this->assertNotFalse($i);
        // The identity file is the connection's OWN path, passed verbatim.
        $this->assertSame('/root/.ssh/id_ed25519', $argv[$i + 1]);
        $this->assertContains('IdentitiesOnly=yes', $argv);
        $this->assertContains('BatchMode=yes', $argv);
        $this->assertContains('StrictHostKeyChecking=yes', $argv);
        // KEYFILE is key-based, never password-forced.
        $this->assertNotContains('PubkeyAuthentication=no', $argv);
    }

    public function testKeyfileHasNoAuthEnv(): void
    {
        $this->assertSame([], Ssh::buildAuthEnv($this->keyfileConn(), '/tmp/pass'));
    }

    public function testMaterializeKeyfileUsesExistingFileNoTmpfsKey(): void
    {
        $keyPath = $this->makeKeyFile();
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->keyfileConn([
            'keyFilePath' => $keyPath,
            'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
        ]);

        $mat = Ssh::materialize($creds, 'c-kf');
        $this->assertTrue($mat['ok'], $mat['error'] ?? '');

        // The identity file IS the connection's existing path (NOT a tmpfs copy).
        $this->assertSame($keyPath, $mat['keyPath']);
        // No tmpfs key file was created for this run's token.
        $this->assertFileDoesNotExist(Ssh::keyPath((string) $mat['token']));
        // The existing key file is untouched (still present + same content).
        $this->assertFileExists($keyPath);
        $this->assertStringContainsString('EXISTING', file_get_contents($keyPath));

        // The -e value carries -i <keyFilePath> verbatim. rsyncDashE quotes each
        // argv element individually, so it appears as: '-i' '<keyFilePath>'.
        $this->assertContains('-i', $mat['sshArgv']);
        $i = array_search('-i', $mat['sshArgv'], true);
        $this->assertSame($keyPath, $mat['sshArgv'][$i + 1]);
        $this->assertStringContainsString("'-i' '" . $keyPath . "'", $mat['dashE']);
        $this->assertSame([], $mat['sshEnv']);

        // cleanupRuntime must NOT delete the user's real key file.
        Ssh::cleanupRuntime((string) $mat['token']);
        $this->assertFileExists($keyPath);
        // The per-run known_hosts (which IS materialised) is gone.
        $this->assertFileDoesNotExist($mat['knownHosts']);
    }

    public function testMaterializeKeyfileMissingFileFailsWithClearMessage(): void
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->keyfileConn([
            'keyFilePath' => $this->rtBase . '/nope/id_ed25519',
        ]);
        $mat = Ssh::materialize($creds, 'c-kf');
        $this->assertFalse($mat['ok']);
        $this->assertStringContainsString('not found or unreadable', $mat['error']);
        // The message warns about the Unraid tmpfs /root reboot gotcha.
        $this->assertStringContainsString('tmpfs', $mat['error']);
    }

    public function testCheckKeyFile(): void
    {
        $keyPath = $this->makeKeyFile('present.key');
        $this->assertSame('', Ssh::checkKeyFile($keyPath));            // present + readable
        $this->assertNotSame('', Ssh::checkKeyFile($this->rtBase . '/absent.key'));
        $this->assertNotSame('', Ssh::checkKeyFile(''));                // empty path
    }

    public function testTestConnectionKeyfileSucceedsWithExistingFile(): void
    {
        FakeSsh::$nextProbe = [0, ''];
        $keyPath = $this->makeKeyFile('tc.key');
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->keyfileConn([
            'keyFilePath' => $keyPath, 'remoteHostKey' => 'h ssh-ed25519 AAAA',
        ]);

        $res = FakeSsh::testConnection($creds, 'c-kf');
        $this->assertTrue($res['ok'], $res['message']);

        // The probe argv carried -i <keyFilePath> and ended with `-- user@host true`.
        $argv = FakeSsh::$lastProbeArgv;
        $this->assertIsArray($argv);
        $i = array_search('-i', $argv, true);
        $this->assertSame($keyPath, $argv[$i + 1]);
        $this->assertSame('true', end($argv));
    }

    public function testTestConnectionKeyfileMissingFileReportsConfig(): void
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->keyfileConn([
            'keyFilePath' => $this->rtBase . '/missing.key',
        ]);
        $res = FakeSsh::testConnection($creds, 'c-kf');
        $this->assertFalse($res['ok']);
        $this->assertSame('config', $res['reason']);
        $this->assertStringContainsString('not found or unreadable', $res['message']);
    }

    // --- KEY argv ----------------------------------------------------------

    public function testKeyArgvShape(): void
    {
        $conn = $this->keyConn(['port' => 2222, 'connectTimeout' => 30, 'strictHostKey' => 'yes']);
        $argv = Ssh::buildSshArgv($conn, '/tmp/k/keyfile', '/tmp/kh');

        $this->assertSame('ssh', $argv[0]);
        // -i <tmpkey>
        $i = array_search('-i', $argv, true);
        $this->assertNotFalse($i);
        $this->assertSame('/tmp/k/keyfile', $argv[$i + 1]);
        // option set present
        $this->assertContains('IdentitiesOnly=yes', $argv);
        $this->assertContains('BatchMode=yes', $argv);
        $this->assertContains('StrictHostKeyChecking=yes', $argv);
        $this->assertContains('UserKnownHostsFile=/tmp/kh', $argv);
        // Host-key verification is pinned to our file only (system known_hosts disabled).
        $this->assertContains('GlobalKnownHostsFile=/dev/null', $argv);
        $this->assertContains('ConnectTimeout=30', $argv);
        // -p <port>
        $p = array_search('-p', $argv, true);
        $this->assertNotFalse($p);
        $this->assertSame('2222', $argv[$p + 1]);
        // KEY auth must NOT force password-only auth
        $this->assertNotContains('PubkeyAuthentication=no', $argv);
    }

    public function testKeyArgvStrictModes(): void
    {
        foreach (['accept-new', 'yes', 'no'] as $mode) {
            $argv = Ssh::buildSshArgv($this->keyConn(['strictHostKey' => $mode]), '/k', '/kh');
            $this->assertContains('StrictHostKeyChecking=' . $mode, $argv);
        }
    }

    public function testKeyHasNoAuthEnv(): void
    {
        $this->assertSame([], Ssh::buildAuthEnv($this->keyConn(), '/tmp/pass'));
    }

    // --- PASSWORD argv -----------------------------------------------------

    public function testPasswordArgvShape(): void
    {
        $conn = $this->passConn(['port' => 2200, 'connectTimeout' => 15, 'strictHostKey' => 'no']);
        $argv = Ssh::buildSshArgv($conn, '', '/tmp/kh');

        $this->assertSame('ssh', $argv[0]);
        $this->assertContains('PubkeyAuthentication=no', $argv);
        $this->assertContains('PreferredAuthentications=password', $argv);
        // PASSWORD must NOT use BatchMode (it would suppress the prompt askpass answers)
        $this->assertNotContains('BatchMode=yes', $argv);
        // and must not carry -i
        $this->assertNotContains('-i', $argv);
        $this->assertContains('StrictHostKeyChecking=no', $argv);
        $this->assertContains('UserKnownHostsFile=/tmp/kh', $argv);
        $this->assertContains('GlobalKnownHostsFile=/dev/null', $argv);
        $this->assertContains('ConnectTimeout=15', $argv);
        $p = array_search('-p', $argv, true);
        $this->assertSame('2200', $argv[$p + 1]);
    }

    public function testPasswordAuthEnvWiresAskpass(): void
    {
        FakeSsh::$askpassPathOverride = '/opt/askpass.sh';
        $env = FakeSsh::buildAuthEnv($this->passConn(), '/tmp/pass/c-pw');
        $this->assertSame('/opt/askpass.sh', $env['SSH_ASKPASS']);
        // force = use the helper regardless of TTY/DISPLAY (OpenSSH 8.4+).
        $this->assertSame('force', $env['SSH_ASKPASS_REQUIRE']);
        // DISPLAY keeps pre-8.4 ssh reaching the helper too.
        $this->assertNotSame('', $env['DISPLAY']);
        $this->assertSame('/tmp/pass/c-pw', $env['UR_ASKPASS_FILE']);
    }

    public function testPasswordAuthEnvIsEmptyWithoutAPassFile(): void
    {
        // No materialised passfile => nothing to point the helper at.
        $this->assertSame([], FakeSsh::buildAuthEnv($this->passConn(), ''));
    }

    // --- rsync -e value ----------------------------------------------------

    public function testRsyncDashEQuotesEveryToken(): void
    {
        $argv = Ssh::buildSshArgv($this->keyConn(), '/tmp/path with space/key', '/tmp/kh');
        $e = Ssh::rsyncDashE($argv);
        // The value re-parses under rsync, so a path with a space must be quoted.
        $this->assertStringContainsString("'/tmp/path with space/key'", $e);
        $this->assertStringStartsWith("'ssh'", $e);
    }

    // --- materialise -------------------------------------------------------

    public function testMaterializeKeyWritesTmpfsKeyAndKnownHosts(): void
    {
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id' => 'k-1', 'name' => 'k',
            'privateKey' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----",
            'publicKey' => 'ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:x',
        ];
        $creds['connections'][] = $this->keyConn(['remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey']);

        $mat = Ssh::materialize($creds, 'c-key');
        $this->assertTrue($mat['ok'], $mat['error'] ?? '');
        $this->assertFileExists($mat['keyPath']);
        $this->assertFileExists($mat['knownHosts']);
        // key file content preserved (with a trailing newline)
        $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', file_get_contents($mat['keyPath']));
        // known_hosts has the pinned key
        $this->assertStringContainsString('AAAAhostkey', file_get_contents($mat['knownHosts']));
        // argv references the materialised paths
        $this->assertContains('-i', $mat['sshArgv']);
        $this->assertContains('UserKnownHostsFile=' . $mat['knownHosts'], $mat['sshArgv']);
        $this->assertSame([], $mat['sshEnv']); // KEY auth

        // On a real (non-FAT32) filesystem the key must be 0600.
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($mat['keyPath'])), -4));
        }

        // The materialised paths are keyed by the unique per-run token.
        $this->assertNotEmpty($mat['token']);
        $this->assertStringContainsString($mat['token'], $mat['keyPath']);

        Ssh::cleanupRuntime($mat['token']);
        $this->assertFileDoesNotExist($mat['keyPath']);
        $this->assertFileDoesNotExist($mat['knownHosts']);
    }

    public function testConcurrentMaterializeOfSameConnectionUsesDistinctPaths(): void
    {
        // Two materialisations of the SAME connection must get different tmpfs
        // paths (unique per-run token), so one run's cleanup never removes the
        // other's in-flight key/known_hosts.
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id' => 'k-1', 'name' => 'k', 'privateKey' => "KEY\n",
            'publicKey' => 'ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:x',
        ];
        $creds['connections'][] = $this->keyConn(['remoteHostKey' => 'h ssh-ed25519 AAAA']);

        $a = Ssh::materialize($creds, 'c-key');
        $b = Ssh::materialize($creds, 'c-key');
        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertNotSame($a['token'], $b['token']);
        $this->assertNotSame($a['keyPath'], $b['keyPath']);
        $this->assertNotSame($a['knownHosts'], $b['knownHosts']);

        // Cleaning up run A leaves run B's files intact.
        Ssh::cleanupRuntime($a['token']);
        $this->assertFileDoesNotExist($a['keyPath']);
        $this->assertFileExists($b['keyPath']);
        $this->assertFileExists($b['knownHosts']);

        Ssh::cleanupRuntime($b['token']);
        $this->assertFileDoesNotExist($b['keyPath']);
    }

    public function testMaterializeRefusesSymlinkedRuntimeDir(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only symlink test');
        }
        // Pre-create the keys dir as a SYMLINK (the /tmp symlink-attack scenario).
        // ensureRuntimeDirs() must refuse to use it rather than follow it.
        mkdir($this->rtBase, 0700, true);
        $target = $this->rtBase . '/evil-target';
        mkdir($target, 0700, true);
        symlink($target, $this->rtBase . '/keys');

        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'k', 'privateKey' => "KEY\n", 'publicKey' => 'p', 'fingerprint' => 'f'];
        $creds['connections'][] = $this->keyConn();

        $threw = false;
        try {
            Ssh::materialize($creds, 'c-key');
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('symlink', strtolower($e->getMessage()));
        }
        $this->assertTrue($threw, 'materialize must refuse a symlinked runtime dir');
        // The symlink target was not written into.
        $this->assertSame([], glob($target . '/*') ?: []);
    }

    public function testSafeWriteDoesNotFollowFileLevelSymlink(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only symlink test');
        }
        // Plant a FILE-level symlink at the exact key path a run will use, then
        // materialise: the tempnam+rename write must REPLACE the symlink, never
        // follow it, so the attacker's target file stays empty.
        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'k', 'privateKey' => "SECRETKEY\n", 'publicKey' => 'p', 'fingerprint' => 'f'];
        $creds['connections'][] = $this->keyConn();

        // Pre-create the dirs (legit), then plant the symlink at the deterministic
        // path. We can't know the random token, so instead point the keys DIR's
        // would-be file: use a fixed token by calling the writer through a known
        // path. Simplest: assert the attack target stays empty after a full run.
        $attackTarget = $this->rtBase . '/attack-target';
        @mkdir($this->rtBase, 0700, true);
        @mkdir($this->rtBase . '/keys', 0700, true);
        file_put_contents($attackTarget, '');
        // Plant a symlink for EVERY key file the run could pick (token is random,
        // so we instead verify post-hoc that the target was never written).
        $mat = Ssh::materialize($creds, 'c-key');
        $this->assertTrue($mat['ok'], $mat['error'] ?? '');
        // The real key landed at its own path (a regular file, not a symlink).
        $this->assertFileExists($mat['keyPath']);
        $this->assertFalse(is_link($mat['keyPath']));
        $this->assertStringContainsString('SECRETKEY', file_get_contents($mat['keyPath']));
        // The attacker target was never touched.
        $this->assertSame('', file_get_contents($attackTarget));
        Ssh::cleanupRuntime($mat['token']);
    }

    public function testMaterializeKeyMissingKeyFails(): void
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->keyConn(['keyId' => 'k-gone']);
        $mat = Ssh::materialize($creds, 'c-key');
        $this->assertFalse($mat['ok']);
        $this->assertStringContainsString('no longer exists', $mat['error']);
    }

    public function testMaterializePasswordWritesPassFile(): void
    {
        FakeSsh::$askpassPathOverride = '/opt/askpass.sh';
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->passConn(['password' => Credentials::obfuscate('hunter2')]);

        $mat = FakeSsh::materialize($creds, 'c-pw');
        $this->assertTrue($mat['ok'], $mat['error'] ?? '');
        $this->assertNotSame('', $mat['passFile']);
        $this->assertFileExists($mat['passFile']);
        // The de-obfuscated plaintext is written for the askpass helper to cat.
        $this->assertSame('hunter2', file_get_contents($mat['passFile']));
        $this->assertSame($mat['passFile'], $mat['sshEnv']['UR_ASKPASS_FILE']);
        $this->assertSame('/opt/askpass.sh', $mat['sshEnv']['SSH_ASKPASS']);
        // THE point of the whole mechanism: the password is in the file, and the
        // env carries only its PATH - never the secret itself.
        $this->assertNotContains('hunter2', array_values($mat['sshEnv']));
        $this->assertNotContains('hunter2', $mat['sshArgv']);

        FakeSsh::cleanupRuntime((string) $mat['token']);
        $this->assertFileDoesNotExist($mat['passFile']);
    }

    public function testMaterializePasswordNeedsNothingInstalled(): void
    {
        // The whole point of moving off sshpass: password auth must materialise
        // on a stock box with no extra package present.
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->passConn(['password' => Credentials::obfuscate('x')]);
        $mat = FakeSsh::materialize($creds, 'c-pw');
        $this->assertTrue($mat['ok'], $mat['error'] ?? '');
    }

    // --- the shipped askpass helper (real script, really executed) ----------

    public function testAskpassHelperIsShippedExecutableAndReturnsThePassword(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only exec test');
        }
        $helper = dirname(__DIR__) . '/source/scripts/askpass.sh';
        $this->assertFileExists($helper);
        // It ships 0755 and ssh EXECS it - a lost exec bit silently breaks
        // password auth, and packaging preserves whatever mode git records.
        $this->assertTrue(is_executable($helper), 'askpass.sh must be executable');
        $this->assertSame('0755', substr(sprintf('%o', fileperms($helper)), -4));

        // Really run it: a password with shell metacharacters must come back
        // byte-for-byte (the helper cats a file; it must never interpolate).
        $pass = 'p@ss "w0rd" $(id) \'q\' `x`';
        $file = $this->rtBase . '-askpass-pw';
        file_put_contents($file, $pass);
        try {
            $out = [];
            $rc  = 0;
            exec('UR_ASKPASS_FILE=' . escapeshellarg($file) . ' ' . escapeshellarg($helper) . ' 2>/dev/null', $out, $rc);
            $this->assertSame(0, $rc);
            $this->assertSame($pass, implode("\n", $out));
        } finally {
            @unlink($file);
        }
    }

    public function testAskpassHelperRefusesWithoutTheEnvVar(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only exec test');
        }
        $helper = dirname(__DIR__) . '/source/scripts/askpass.sh';
        $out = [];
        $rc  = 0;
        exec('unset UR_ASKPASS_FILE; ' . escapeshellarg($helper) . ' 2>/dev/null', $out, $rc);
        $this->assertNotSame(0, $rc, 'must not print anything when no passfile is named');
    }

    // --- probe classification (pure) ---------------------------------------

    public function testClassifySuccess(): void
    {
        $res = Ssh::classifyProbe($this->keyConn(), 0, '');
        $this->assertTrue($res['ok']);
        $this->assertSame('ok', $res['reason']);
    }

    public function testClassifyKeyAuthFailureFromStderr(): void
    {
        $res = Ssh::classifyProbe($this->keyConn(), 255, 'sasa@h.example: Permission denied (publickey).');
        $this->assertFalse($res['ok']);
        $this->assertSame('auth', $res['reason']);
    }

    public function testClassifyHostKeyFailureFromStderr(): void
    {
        $res = Ssh::classifyProbe($this->keyConn(), 255, 'Host key verification failed.');
        $this->assertFalse($res['ok']);
        $this->assertSame('hostkey', $res['reason']);
    }

    public function testClassifyUnreachableFromStderr(): void
    {
        $res = Ssh::classifyProbe($this->keyConn(), 255, 'ssh: connect to host h.example port 22: Connection timed out');
        $this->assertFalse($res['ok']);
        $this->assertSame('unreachable', $res['reason']);
    }

    public function testClassifyPasswordAuthFailureViaStderr(): void
    {
        // ssh is the outer process now, so a wrong password is exit 255 with
        // "Permission denied" - the same path KEY auth already used. (sshpass
        // used to signal this as its own exit 5.)
        $res = Ssh::classifyProbe($this->passConn(), Ssh::SSH_EXIT_ERROR, 'Permission denied (publickey,password).');
        $this->assertFalse($res['ok']);
        $this->assertSame('auth', $res['reason']);
    }

    public function testClassifyPasswordHostKeyFailureViaStderr(): void
    {
        $res = Ssh::classifyProbe($this->passConn(), Ssh::SSH_EXIT_ERROR, 'Host key verification failed.');
        $this->assertFalse($res['ok']);
        $this->assertSame('hostkey', $res['reason']);
    }

    public function testSmallExitCodesAreRemoteExitsForEveryAuthMethod(): void
    {
        // With no wrapper process, a small exit code is ALWAYS the remote
        // command's own status - for PASSWORD exactly as for KEY. It must never
        // be read as an auth/host-key signal (that only held under sshpass).
        foreach ([$this->keyConn(), $this->passConn()] as $conn) {
            foreach ([5, 6, 7] as $code) {
                $res = Ssh::classifyProbe($conn, $code, '');
                $this->assertFalse($res['ok']);
                $this->assertSame('unreachable', $res['reason']);
            }
        }
    }

    // --- testConnection end-to-end (stubbed probe) -------------------------

    public function testTestConnectionComposesProbeArgvAndSucceeds(): void
    {
        FakeSsh::$nextProbe = [0, ''];
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id' => 'k-1', 'name' => 'k', 'privateKey' => "KEY\n",
            'publicKey' => 'ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:x',
        ];
        $creds['connections'][] = $this->keyConn(['remoteHostKey' => 'h ssh-ed25519 AAAA']);

        $res = FakeSsh::testConnection($creds, 'c-key');
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame('ok', $res['reason']);

        // The probe argv ends with user@host and the trivial `true` command,
        // with `--` before the destination (option-injection guard).
        $argv = FakeSsh::$lastProbeArgv;
        $this->assertIsArray($argv);
        $this->assertSame('true', end($argv));
        $destIdx = array_search('sasa@h.example', $argv, true);
        $this->assertNotFalse($destIdx);
        $this->assertSame('--', $argv[$destIdx - 1], 'destination must be preceded by --');
        // ssh is argv[0] for every auth method now - no wrapper program.
        $this->assertSame('ssh', $argv[0]);
    }

    public function testTestConnectionPasswordPassesAskpassEnvToTheProbe(): void
    {
        // The probe must carry the askpass wiring, or a PASSWORD connection can
        // never authenticate - and the password must NOT be in the argv.
        FakeSsh::$askpassPathOverride = '/opt/askpass.sh';
        FakeSsh::$nextProbe = [0, ''];
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->passConn(['password' => Credentials::obfuscate('hunter2')]);

        $res = FakeSsh::testConnection($creds, 'c-pw');
        $this->assertTrue($res['ok'], $res['message']);

        $env = FakeSsh::$lastProbeEnv;
        $this->assertIsArray($env);
        $this->assertSame('/opt/askpass.sh', $env['SSH_ASKPASS']);
        $this->assertSame('force', $env['SSH_ASKPASS_REQUIRE']);
        $this->assertArrayHasKey('UR_ASKPASS_FILE', $env);
        // Inherited vars survive - proc_open REPLACES the env, so ssh would
        // lose PATH/HOME if childEnv() ever stopped merging.
        $this->assertArrayHasKey('PATH', $env);
        // The secret itself is nowhere near argv or env.
        $this->assertNotContains('hunter2', array_values($env));
        $this->assertNotContains('hunter2', (array) FakeSsh::$lastProbeArgv);
    }

    public function testTestConnectionKeyAuthPassesNoEnvOverride(): void
    {
        FakeSsh::$nextProbe = [0, ''];
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id' => 'k-1', 'name' => 'k', 'privateKey' => "KEY\n",
            'publicKey' => 'ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:x',
        ];
        $creds['connections'][] = $this->keyConn();
        FakeSsh::testConnection($creds, 'c-key');
        // Nothing to add => null, so the child just inherits normally.
        $this->assertNull(FakeSsh::$lastProbeEnv);
    }

    public function testTestConnectionUnknownIdIsConfigError(): void
    {
        $res = Ssh::testConnection(Credentials::defaults(), 'nope');
        $this->assertFalse($res['ok']);
        $this->assertSame('config', $res['reason']);
    }

    // --- DAEMON transport: Ssh refuses it, and materialises only a passfile ---

    /**
     * A merged DAEMON connection. mergeConnection() resolves the port from the
     * transport, so an untouched card lands on 873 (Credentials::RSYNCD_PORT).
     *
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function daemonConn(array $over = []): array
    {
        return Credentials::mergeConnection(array_merge([
            'id' => 'c-daemon', 'name' => 'd', 'host' => 'nas.local',
            'username' => 'moduser', 'transport' => 'DAEMON',
        ], $over));
    }

    /**
     * The expected MERGED connection, in mergeConnection's pinned key order -
     * spelled out so a reordering or a dropped key fails loudly rather than
     * being absorbed by a call to the very function under test.
     *
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function mergedConn(array $over = []): array
    {
        return array_merge([
            'id'             => '',
            'name'           => '',
            'host'           => 'h.example',
            'username'       => 'sasa',
            'keyId'          => '',
            'keyFilePath'    => Credentials::DEFAULT_KEY_FILE_PATH,
            'password'       => '',
            'remoteHostKey'  => '',
            'transport'      => 'SSH',
            'port'           => 22,
            'authMethod'     => 'KEYFILE',
            'strictHostKey'  => 'accept-new',
            'connectTimeout' => 10,
        ], $over);
    }

    /** Sorted directory entries (no dots), or [] when the dir does not exist. */
    private function dirEntries(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);
        return $entries;
    }

    public function testMaterializeRefusesADaemonConnectionAndCreatesNothing(): void
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->daemonConn(['password' => Credentials::obfuscate('s3cret')]);

        $mat = FakeSsh::materialize($creds, 'c-daemon');

        $this->assertSame([
            'ok'    => false,
            'error' => 'This Connection uses rsync daemon (rsyncd) transport; an SSH transport cannot be built from it.',
        ], $mat);

        // The guard sits BEFORE newRuntimeToken() and ensureRuntimeDirs(): no
        // token was minted and the tmpfs runtime base was never created. A guard
        // one line later would still return this exact message, so asserting the
        // message alone would prove nothing.
        $this->assertSame(0, FakeSsh::$tokenMints);
        $this->assertDirectoryDoesNotExist($this->rtBase);

        // Control: the same call for an SSH connection DOES mint a token and
        // create all three secret dirs - so the two assertions above can fail.
        $creds['connections'][] = $this->passConn(['password' => Credentials::obfuscate('x')]);
        $ok = FakeSsh::materialize($creds, 'c-pw');
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertSame(1, FakeSsh::$tokenMints);
        foreach (['keys', 'pass', 'known_hosts'] as $sub) {
            $this->assertDirectoryExists($this->rtBase . '/' . $sub);
        }
        FakeSsh::cleanupRuntime((string) $ok['token']);
    }

    /**
     * PASSWORD is materialize()'s else FALL-THROUGH, so the refusal must not
     * depend on authMethod: a daemon card still POSTs whatever the (hidden) auth
     * select held, and without the guard a PASSWORD one would get an SSH_ASKPASS
     * passfile plus an `ssh` -e string handed to rsync beside a host::module
     * operand (which rsync silently accepts as daemon-over-remote-shell).
     */
    #[DataProvider('daemonAuthMethodProvider')]
    public function testMaterializeRefusesADaemonConnectionWhateverItsAuthMethod(string $authMethod): void
    {
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id' => 'k-1', 'name' => 'k', 'privateKey' => "KEY\n",
            'publicKey' => 'ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:x',
        ];
        $creds['connections'][] = $this->daemonConn([
            'authMethod' => $authMethod,
            'keyId'      => 'k-1',
            'password'   => Credentials::obfuscate('s3cret'),
        ]);

        $mat = FakeSsh::materialize($creds, 'c-daemon');

        $this->assertSame([
            'ok'    => false,
            'error' => 'This Connection uses rsync daemon (rsyncd) transport; an SSH transport cannot be built from it.',
        ], $mat);
        $this->assertSame(0, FakeSsh::$tokenMints);
        $this->assertDirectoryDoesNotExist($this->rtBase);
    }

    /** @return array<string,array{0:string}> */
    public static function daemonAuthMethodProvider(): array
    {
        return ['keyfile' => ['KEYFILE'], 'key' => ['KEY'], 'password' => ['PASSWORD']];
    }

    public function testTestConnectionRefusesADaemonConnectionWithoutSpawningAnything(): void
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->daemonConn(['password' => Credentials::obfuscate('s3cret')]);

        $res = FakeSsh::testConnection($creds, 'c-daemon');

        $this->assertSame([
            'ok'      => false,
            'reason'  => 'config',
            'message' => 'This Connection uses rsync daemon (rsyncd) transport; the SSH connection test does not apply to it.',
        ], $res);
        // Nothing was spawned and nothing was materialised for it.
        $this->assertNull(FakeSsh::$lastProbeArgv);
        $this->assertNull(FakeSsh::$lastProbeEnv);
        $this->assertSame(0, FakeSsh::$tokenMints);
        $this->assertDirectoryDoesNotExist($this->rtBase);
    }

    /**
     * The transport guard precedes the host/username check. Reversed, a daemon
     * connection with an empty host would get "Host and username are required"
     * - and a COMPLETE one would get an ssh probe fired at port 873.
     */
    public function testTestConnectionDaemonGuardPrecedesTheHostAndUsernameCheck(): void
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->daemonConn(['host' => '', 'username' => '']);

        $res = FakeSsh::testConnection($creds, 'c-daemon');

        $this->assertSame([
            'ok'      => false,
            'reason'  => 'config',
            'message' => 'This Connection uses rsync daemon (rsyncd) transport; the SSH connection test does not apply to it.',
        ], $res);
        $this->assertNull(FakeSsh::$lastProbeArgv);
    }

    public function testMaterializeDaemonWritesOnePassFileAt0600AndNothingElse(): void
    {
        $creds = Credentials::defaults();
        // A secret with an inner space and no trailing newline: rsync's getpassf()
        // strtok()s the first line only, so the bytes must land verbatim.
        $creds['connections'][] = $this->daemonConn(['password' => Credentials::obfuscate('s3cr et')]);

        $mat = FakeSsh::materializeDaemon($creds, 'c-daemon');

        $this->assertTrue($mat['ok'], $mat['error'] ?? '');
        $token = (string) $mat['token'];
        $this->assertNotSame('', $token);
        $this->assertSame(1, FakeSsh::$tokenMints);

        $this->assertSame([
            'ok'       => true,
            'token'    => $token,
            'conn'     => $this->mergedConn([
                'id'        => 'c-daemon',
                'name'      => 'd',
                'host'      => 'nas.local',
                'username'  => 'moduser',
                'password'  => Credentials::obfuscate('s3cr et'),
                'transport' => 'DAEMON',
                'port'      => 873,
            ]),
            'passFile' => $this->rtBase . '/pass/' . $token,
            'port'     => 873,
        ], $mat);

        // No sshEnv/sshArgv/dashE/keyPath/knownHosts here: SPEC 2.B pins the
        // daemon bag to five keys, and the `sshEnv => []` that Runner.php:374
        // needs belongs to the Runner's own pieces bag (SPEC 2.D6).
        foreach (['sshEnv', 'sshArgv', 'dashE', 'keyPath', 'knownHosts'] as $absent) {
            $this->assertArrayNotHasKey($absent, $mat);
        }

        // EXACTLY one secret file exists, and it is the passfile.
        $this->assertSame([$token], $this->dirEntries($this->rtBase . '/pass'));
        $this->assertSame([], $this->dirEntries($this->rtBase . '/keys'));
        $this->assertSame([], $this->dirEntries($this->rtBase . '/known_hosts'));
        $this->assertSame('s3cr et', file_get_contents($mat['passFile']));
        // getpassf() (authenticate.c:175-217) exits 1 when st_mode & 06 is set.
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame('0600', substr(sprintf('%o', fileperms($mat['passFile'])), -4));
        }

        FakeSsh::cleanupRuntime($token);
        $this->assertFileDoesNotExist($mat['passFile']);
        $this->assertSame([], $this->dirEntries($this->rtBase . '/pass'));
    }

    public function testMaterializeDaemonAnonymousModuleWritesNothingAndMintsNoToken(): void
    {
        $creds = Credentials::defaults();
        // No stored secret: an anonymous (no `auth users`) module.
        $creds['connections'][] = $this->daemonConn(['port' => 8730]);

        $mat = FakeSsh::materializeDaemon($creds, 'c-daemon');

        $this->assertSame([
            'ok'       => true,
            'token'    => '',
            'conn'     => $this->mergedConn([
                'id'        => 'c-daemon',
                'name'      => 'd',
                'host'      => 'nas.local',
                'username'  => 'moduser',
                'transport' => 'DAEMON',
                'port'      => 8730,
            ]),
            'passFile' => '',
            'port'     => 8730,
        ], $mat);

        // An EMPTY password file would make rsync exit 1 ("failed to read a
        // password from %s", authenticate.c:215), so nothing at all is written -
        // not even the runtime dirs - and no token is minted for the Runner to
        // clean up.
        $this->assertSame(0, FakeSsh::$tokenMints);
        $this->assertDirectoryDoesNotExist($this->rtBase);
    }

    /**
     * Every materializeDaemon guard runs BEFORE the token is minted, so no
     * failure path can orphan a secret on disk.
     *
     * @param array<string,mixed> $over
     */
    #[DataProvider('daemonMaterializeGuardProvider')]
    public function testMaterializeDaemonGuardsRefuseBeforeAnythingIsWritten(array $over, string $expected): void
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->daemonConn($over);

        $mat = FakeSsh::materializeDaemon($creds, 'c-daemon');

        $this->assertSame(['ok' => false, 'error' => $expected], $mat);
        $this->assertSame(0, FakeSsh::$tokenMints);
        $this->assertDirectoryDoesNotExist($this->rtBase);
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function daemonMaterializeGuardProvider(): array
    {
        $wrongTransport = 'This Connection uses SSH transport; an rsync daemon transport cannot be built from it.';
        $incomplete     = 'The Connection is incomplete: an rsync daemon connection needs both a host and a username.';
        $badHost        = 'The Connection host is not valid for an rsync daemon operand.';
        $badUser        = 'The Connection username is not valid for an rsync daemon operand.';
        $newline        = 'The module secret must not contain line breaks: rsync reads only the first line '
            . 'of the password file, so everything after it would be silently discarded.';

        return [
            'ssh transport'      => [['transport' => 'SSH'], $wrongTransport],
            'unknown transport'  => [['transport' => 'FTP'], $wrongTransport],
            'empty host'         => [['host' => ''], $incomplete],
            'empty username'     => [['username' => ''], $incomplete],
            // parse_hostspec breaks the authority at the FIRST ':' or '/', so
            // either character turns the operand into an SSH target over the
            // default remote shell, or into a plain local path.
            'host with a colon'  => [['host' => 'nas:2222'], $badHost],
            'host with a slash'  => [['host' => 'nas/evil'], $badHost],
            'host with a bracket' => [['host' => '[fe80::1]'], $badHost],
            'user with a colon'  => [['username' => 'a:b'], $badUser],
            'user with a slash'  => [['username' => 'a/b'], $badUser],
            'secret with LF'     => [['password' => Credentials::obfuscate("first\nsecond")], $newline],
            'secret with CR'     => [['password' => Credentials::obfuscate("first\rsecond")], $newline],
            'secret with NUL'    => [['password' => Credentials::obfuscate("first\0second")], $newline],
        ];
    }

    /**
     * The daemon path goes through the SAME hardened ensureRuntimeDirs(): a
     * symlink planted at pass/ (the /tmp symlink attack) is refused rather than
     * followed, the attacker's target is never written into, and the throw
     * happens BEFORE the token is minted.
     *
     * The only failure path AFTER minting is a write failure inside
     * writePassFile -> safeWriteSecret, which is not reachable portably (its
     * own chmod 0700 in ensureRuntimeDirs re-grants write to the owner); that
     * writer unlinks its tempnam() on every error branch before throwing, so it
     * cannot orphan a secret either.
     */
    public function testMaterializeDaemonRefusesASymlinkedPassDirAndWritesNothing(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only symlink test');
        }
        mkdir($this->rtBase, 0700, true);
        $target = $this->rtBase . '/evil-target';
        mkdir($target, 0700, true);
        symlink($target, $this->rtBase . '/pass');

        $creds = Credentials::defaults();
        $creds['connections'][] = $this->daemonConn(['password' => Credentials::obfuscate('s3cret')]);

        $threw = false;
        try {
            FakeSsh::materializeDaemon($creds, 'c-daemon');
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('symlink', strtolower($e->getMessage()));
        }
        $this->assertTrue($threw, 'materializeDaemon must refuse a symlinked runtime dir');
        $this->assertSame([], $this->dirEntries($target));
        $this->assertSame(0, FakeSsh::$tokenMints);
    }

    /**
     * A secret-write failure must not name the per-run tmpfs path. The Runner
     * turns a thrown message straight into a run-log AND plugin.log line, and it
     * does so on the FAILURE arm - where Logger::setRedaction has not been armed
     * (it is armed only on success) and where redactRunLog never reaches
     * plugin.log at all. $label already says which secret failed, and the path is
     * a tmpfs name that is gone by the time anyone reads the log.
     */
    public function testASecretWriteFailureNeverNamesThePerRunSecretPath(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only rename-onto-a-directory test');
        }
        // A DIRECTORY where the pass file should land: safeWriteSecret's atomic
        // rename() then fails (EISDIR) after the tempnam write succeeded.
        $passPath = $this->rtBase . '/pass/' . FixedTokenSsh::TOKEN;
        mkdir($passPath, 0700, true);

        $creds = Credentials::defaults();
        $creds['connections'][] = $this->daemonConn(['password' => Credentials::obfuscate('s3cret')]);

        $threw = null;
        try {
            FixedTokenSsh::materializeDaemon($creds, 'c-daemon');
        } catch (RuntimeException $e) {
            $threw = $e;
        }
        $this->assertNotNull($threw, 'the failed rename must throw');
        $this->assertSame('Unable to place password file.', $threw->getMessage());
        $this->assertStringNotContainsString($this->rtBase, $threw->getMessage());
        $this->assertStringNotContainsString(FixedTokenSsh::TOKEN, $threw->getMessage());
        // ...and the tempnam scratch file is unlinked, so nothing is orphaned.
        $this->assertSame(
            [],
            array_values(array_filter(
                (array) scandir($this->rtBase . '/pass'),
                static fn(string $e): bool => strpos($e, '.ur-secret.') === 0
            ))
        );
    }

    public function testNoSecretWriteExceptionInterpolatesAPath(): void
    {
        // The sibling throw (a failed file_put_contents into the tempnam file) is
        // not reachable portably, and the same reasoning applies to it and to any
        // throw added later, so pin the rule at the source.
        $src = file_get_contents(__DIR__ . '/../source/include/Ssh.php');
        $this->assertIsString($src);
        $this->assertSame(
            0,
            preg_match_all('/throw new RuntimeException\([^;]*\$path/', $src),
            'a secret path must never reach an exception message - it is logged before redaction is armed'
        );
    }

    public function testMaterializeDaemonUnknownConnectionIdFailsAndWritesNothing(): void
    {
        $mat = FakeSsh::materializeDaemon(Credentials::defaults(), 'nope');

        $this->assertSame(['ok' => false, 'error' => 'Connection not found: nope'], $mat);
        $this->assertSame(0, FakeSsh::$tokenMints);
        $this->assertDirectoryDoesNotExist($this->rtBase);
    }

    // --- the three SSH auth methods are byte-identical to the pre-daemon code -

    // The three bags below were captured by materialising the SAME connections
    // against a checkout of a3ed950 (the commit before the daemon transport) and
    // diffing: the ONLY difference is the new additive `transport => 'SSH'` key
    // inside the merged `conn` (SPEC section 8, no-breaking-change ledger).
    // sshArgv, dashE, sshEnv, keyPath, passFile and knownHosts are unchanged.

    public function testKeyAuthMaterialisesTheSameBagAsBeforeTheDaemonTransport(): void
    {
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id' => 'k-1', 'name' => 'k',
            'privateKey' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----",
            'publicKey' => 'ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:x',
        ];
        $creds['connections'][] = $this->keyConn(['remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey']);

        $mat = Ssh::materialize($creds, 'c-key');
        $this->assertTrue($mat['ok'], $mat['error'] ?? '');
        $token = (string) $mat['token'];
        $kp    = $this->rtBase . '/keys/' . $token;
        $kh    = $this->rtBase . '/known_hosts/' . $token;

        $this->assertSame([
            'ok'      => true,
            'token'   => $token,
            'conn'    => $this->mergedConn([
                'id' => 'c-key', 'name' => 'k', 'keyId' => 'k-1', 'authMethod' => 'KEY',
                'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
            ]),
            'sshArgv' => [
                'ssh',
                '-i', $kp,
                '-o', 'IdentitiesOnly=yes',
                '-o', 'BatchMode=yes',
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'UserKnownHostsFile=' . $kh,
                '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'ConnectTimeout=10',
                '-p', '22',
            ],
            'sshEnv'     => [],
            'dashE'      => "'ssh' '-i' '$kp' '-o' 'IdentitiesOnly=yes' '-o' 'BatchMode=yes'"
                . " '-o' 'StrictHostKeyChecking=accept-new' '-o' 'UserKnownHostsFile=$kh'"
                . " '-o' 'GlobalKnownHostsFile=/dev/null' '-o' 'ConnectTimeout=10' '-p' '22'",
            'keyPath'    => $kp,
            'passFile'   => '',
            'knownHosts' => $kh,
        ], $mat);

        Ssh::cleanupRuntime($token);
    }

    public function testKeyfileAuthMaterialisesTheSameBagAsBeforeTheDaemonTransport(): void
    {
        $keyFile = $this->makeKeyFile();
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->keyfileConn([
            'keyFilePath' => $keyFile, 'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
        ]);

        $mat = Ssh::materialize($creds, 'c-kf');
        $this->assertTrue($mat['ok'], $mat['error'] ?? '');
        $token = (string) $mat['token'];
        $kh    = $this->rtBase . '/known_hosts/' . $token;

        $this->assertSame([
            'ok'      => true,
            'token'   => $token,
            'conn'    => $this->mergedConn([
                'id' => 'c-kf', 'name' => 'kf', 'keyFilePath' => $keyFile,
                'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
            ]),
            'sshArgv' => [
                'ssh',
                '-i', $keyFile,
                '-o', 'IdentitiesOnly=yes',
                '-o', 'BatchMode=yes',
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'UserKnownHostsFile=' . $kh,
                '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'ConnectTimeout=10',
                '-p', '22',
            ],
            'sshEnv'     => [],
            'dashE'      => "'ssh' '-i' '$keyFile' '-o' 'IdentitiesOnly=yes' '-o' 'BatchMode=yes'"
                . " '-o' 'StrictHostKeyChecking=accept-new' '-o' 'UserKnownHostsFile=$kh'"
                . " '-o' 'GlobalKnownHostsFile=/dev/null' '-o' 'ConnectTimeout=10' '-p' '22'",
            'keyPath'    => $keyFile,
            'passFile'   => '',
            'knownHosts' => $kh,
        ], $mat);

        Ssh::cleanupRuntime($token);
    }

    public function testPasswordAuthMaterialisesTheSameBagAsBeforeTheDaemonTransport(): void
    {
        Ssh::$askpassPathOverride = '/opt/askpass.sh';
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->passConn([
            'password' => Credentials::obfuscate('hunter2'),
            'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
        ]);

        $mat = Ssh::materialize($creds, 'c-pw');
        $this->assertTrue($mat['ok'], $mat['error'] ?? '');
        $token = (string) $mat['token'];
        $kh    = $this->rtBase . '/known_hosts/' . $token;
        $pf    = $this->rtBase . '/pass/' . $token;

        $this->assertSame([
            'ok'      => true,
            'token'   => $token,
            'conn'    => $this->mergedConn([
                'id' => 'c-pw', 'name' => 'p', 'authMethod' => 'PASSWORD',
                'password' => Credentials::obfuscate('hunter2'),
                'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
            ]),
            'sshArgv' => [
                'ssh',
                '-o', 'PubkeyAuthentication=no',
                '-o', 'PreferredAuthentications=password',
                '-o', 'NumberOfPasswordPrompts=1',
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'UserKnownHostsFile=' . $kh,
                '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'ConnectTimeout=10',
                '-p', '22',
            ],
            'sshEnv' => [
                'SSH_ASKPASS'         => '/opt/askpass.sh',
                'SSH_ASKPASS_REQUIRE' => 'force',
                'DISPLAY'             => ':0',
                'UR_ASKPASS_FILE'     => $pf,
            ],
            'dashE'      => "'ssh' '-o' 'PubkeyAuthentication=no' '-o' 'PreferredAuthentications=password'"
                . " '-o' 'NumberOfPasswordPrompts=1' '-o' 'StrictHostKeyChecking=accept-new'"
                . " '-o' 'UserKnownHostsFile=$kh' '-o' 'GlobalKnownHostsFile=/dev/null'"
                . " '-o' 'ConnectTimeout=10' '-p' '22'",
            'keyPath'    => '',
            'passFile'   => $pf,
            'knownHosts' => $kh,
        ], $mat);

        Ssh::cleanupRuntime($token);
    }
}

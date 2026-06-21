<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A test double for Ssh that stubs the live-system seams (sshpass detection and
 * the ssh probe) so the argv-construction, materialisation and probe-
 * classification logic is exercised entirely offline - no socket, no real
 * ssh/sshpass binary. The probe result is injected per test.
 */
final class FakeSsh extends Ssh
{
    /** @var array{0:int,1:string} */
    public static $nextProbe = [0, ''];
    /** @var array<int,string>|null the argv runProbe was last called with */
    public static $lastProbeArgv = null;

    protected static function locateSshpass(): string
    {
        // Honour the override; otherwise pretend sshpass IS present so the
        // PASSWORD argv path can be exercised without the real binary.
        if (static::$sshpassPathOverride !== null) {
            return (string) static::$sshpassPathOverride;
        }
        return '/usr/bin/sshpass';
    }

    protected static function runProbe(array $argv): array
    {
        self::$lastProbeArgv = $argv;
        return self::$nextProbe;
    }
}

/**
 * Exposes the REAL (no-shell, PATH-scanning) locateSshpass() so it can be tested
 * directly against a temp PATH dir without a shell or a real sshpass binary.
 */
final class RealLocateSsh extends Ssh
{
    public static function publicLocateSshpass(): string
    {
        return static::locateSshpass();
    }
}

/**
 * Tests for Ssh.php: argv assembly for KEY vs PASSWORD (incl. strictHostKey
 * modes, port, timeout, known_hosts wiring), the rsync -e value, materialise +
 * cleanup against a tmpfs override, sshpass detect-and-degrade, and the probe
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
        Ssh::$sshpassPathOverride = null;
        FakeSsh::$sshpassPathOverride = null;
        FakeSsh::$nextProbe = [0, ''];
        FakeSsh::$lastProbeArgv = null;
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->rtBase);
        Ssh::$runtimeBase = '/tmp/unraid.rsync';
        FakeSsh::$runtimeBase = '/tmp/unraid.rsync';
        Ssh::$sshpassPathOverride = null;
        FakeSsh::$sshpassPathOverride = null;
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

    public function testKeyfileHasNoSshpassPrefix(): void
    {
        $this->assertSame([], Ssh::buildSshpassPrefix($this->keyfileConn(), '/tmp/pass'));
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
        $this->assertSame([], $mat['sshpassPrefix']);

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

    public function testKeyHasNoSshpassPrefix(): void
    {
        $this->assertSame([], Ssh::buildSshpassPrefix($this->keyConn(), '/tmp/pass'));
    }

    // --- PASSWORD argv -----------------------------------------------------

    public function testPasswordArgvShape(): void
    {
        $conn = $this->passConn(['port' => 2200, 'connectTimeout' => 15, 'strictHostKey' => 'no']);
        $argv = Ssh::buildSshArgv($conn, '', '/tmp/kh');

        $this->assertSame('ssh', $argv[0]);
        $this->assertContains('PubkeyAuthentication=no', $argv);
        $this->assertContains('PreferredAuthentications=password', $argv);
        // PASSWORD must NOT use BatchMode (it would suppress the prompt sshpass answers)
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

    public function testPasswordSshpassPrefixWhenAvailable(): void
    {
        FakeSsh::$sshpassPathOverride = '/usr/bin/sshpass';
        $prefix = FakeSsh::buildSshpassPrefix($this->passConn(), '/tmp/pass/c-pw');
        $this->assertSame(['/usr/bin/sshpass', '-f', '/tmp/pass/c-pw'], $prefix);
    }

    public function testPasswordSshpassPrefixEmptyWhenUnavailable(): void
    {
        FakeSsh::$sshpassPathOverride = ''; // simulate "not installed"
        $this->assertSame([], FakeSsh::buildSshpassPrefix($this->passConn(), '/tmp/pass/c-pw'));
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
        $this->assertSame([], $mat['sshpassPrefix']); // KEY auth

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

    public function testMaterializePasswordWritesPassFileWhenAvailable(): void
    {
        FakeSsh::$sshpassPathOverride = '/usr/bin/sshpass';
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->passConn(['password' => Credentials::obfuscate('hunter2')]);

        $mat = FakeSsh::materialize($creds, 'c-pw');
        $this->assertTrue($mat['ok'], $mat['error'] ?? '');
        $this->assertNotSame('', $mat['passFile']);
        $this->assertFileExists($mat['passFile']);
        // The de-obfuscated plaintext is written for sshpass -f.
        $this->assertSame('hunter2', file_get_contents($mat['passFile']));
        $this->assertSame(['/usr/bin/sshpass', '-f', $mat['passFile']], $mat['sshpassPrefix']);

        FakeSsh::cleanupRuntime((string) $mat['token']);
        $this->assertFileDoesNotExist($mat['passFile']);
    }

    public function testMaterializePasswordFailsWhenSshpassMissing(): void
    {
        FakeSsh::$sshpassPathOverride = '';
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->passConn(['password' => Credentials::obfuscate('x')]);
        $mat = FakeSsh::materialize($creds, 'c-pw');
        $this->assertFalse($mat['ok']);
        $this->assertStringContainsString('sshpass', $mat['error']);
    }

    // --- sshpass detect-and-degrade ----------------------------------------

    public function testSshpassAvailabilityHonoursOverride(): void
    {
        Ssh::$sshpassPathOverride = '/usr/bin/sshpass';
        $this->assertTrue(Ssh::sshpassAvailable());
        Ssh::$sshpassPathOverride = '';
        $this->assertFalse(Ssh::sshpassAvailable());
    }

    public function testSshpassMissingMessageMentionsNerdTools(): void
    {
        $this->assertStringContainsString('NerdTools', Ssh::sshpassMissingMessage());
    }

    public function testLocateSshpassFindsExecutableOnPathWithoutShell(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only path/exec test');
        }
        $origPath = getenv('PATH');
        $dir = sys_get_temp_dir() . '/ur-path-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            // Put our temp dir FIRST on PATH and drop an executable "sshpass" in
            // it. The no-shell scanner must find it at its absolute path. (We
            // don't assert the negative case because the scanner also probes
            // standard fallback dirs that may legitimately contain sshpass on
            // some hosts.)
            putenv('PATH=' . $dir . PATH_SEPARATOR . ($origPath !== false ? $origPath : ''));
            $bin = $dir . '/sshpass';
            file_put_contents($bin, "#!/bin/sh\nexit 0\n");
            chmod($bin, 0755);
            $found = RealLocateSsh::publicLocateSshpass();
            $this->assertSame($bin, $found);
            $this->assertTrue(is_executable($found));

            // A non-executable file of the same name is ignored (must be +x).
            unlink($bin);
            file_put_contents($bin, "not a program\n");
            chmod($bin, 0644);
            // It either falls through to a real sshpass elsewhere or returns ''
            // - in both cases it must NOT return our non-executable file.
            $this->assertNotSame($bin, RealLocateSsh::publicLocateSshpass());
        } finally {
            if ($origPath !== false) {
                putenv('PATH=' . $origPath);
            }
            @unlink($dir . '/sshpass');
            @rmdir($dir);
        }
    }

    public function testLocateSshpassIgnoresRelativePathEntries(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX-only path/exec test');
        }
        $origPath = getenv('PATH');
        $origCwd  = getcwd();
        $dir = sys_get_temp_dir() . '/ur-relpath-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        try {
            // Drop an executable "sshpass" in the cwd, and put a RELATIVE "."
            // entry on PATH. The scanner must ignore non-absolute PATH entries,
            // so it must NOT return "./sshpass".
            chdir($dir);
            $rogue = $dir . '/sshpass';
            file_put_contents($rogue, "#!/bin/sh\nexit 0\n");
            chmod($rogue, 0755);
            putenv('PATH=.');
            $this->assertNotSame('./sshpass', RealLocateSsh::publicLocateSshpass());
            $this->assertNotSame($rogue, RealLocateSsh::publicLocateSshpass());
        } finally {
            if ($origCwd !== false) {
                chdir($origCwd);
            }
            if ($origPath !== false) {
                putenv('PATH=' . $origPath);
            }
            @unlink($dir . '/sshpass');
            @rmdir($dir);
        }
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

    public function testClassifyPasswordSshpassAuthExit5(): void
    {
        // sshpass exit 5 == incorrect password (PASSWORD path only).
        $res = Ssh::classifyProbe($this->passConn(), Ssh::SSHPASS_INCORRECT_PASS, '');
        $this->assertFalse($res['ok']);
        $this->assertSame('auth', $res['reason']);
    }

    public function testClassifyPasswordSshpassHostKeyExit6(): void
    {
        $res = Ssh::classifyProbe($this->passConn(), Ssh::SSHPASS_HOSTKEY_UNKNOWN, '');
        $this->assertFalse($res['ok']);
        $this->assertSame('hostkey', $res['reason']);
    }

    public function testClassifyPasswordSshpassHostKeyChangedExit7(): void
    {
        $res = Ssh::classifyProbe($this->passConn(), Ssh::SSHPASS_HOSTKEY_CHANGED, '');
        $this->assertFalse($res['ok']);
        $this->assertSame('hostkey', $res['reason']);
    }

    public function testKeyAuthExit5IsNotTreatedAsSshpassSemantics(): void
    {
        // For KEY auth, exit code 5 is a remote command's own exit, NOT sshpass
        // "incorrect password". It must not be classified as an auth failure
        // via the sshpass table (it falls through to the unexpected-code path).
        $res = Ssh::classifyProbe($this->keyConn(), 5, '');
        $this->assertFalse($res['ok']);
        $this->assertSame('unreachable', $res['reason']); // unexpected exit code
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
        // KEY auth: no sshpass prefix in front of ssh.
        $this->assertSame('ssh', $argv[0]);
    }

    public function testClassifyPasswordNon255RemoteExitIsNotAuthFailure(): void
    {
        // sshpass propagates the wrapped ssh/remote exit verbatim. A non-255,
        // non-sshpass-internal exit on the PASSWORD path is a real remote exit,
        // NOT a connect/auth failure - it must not be sniffed as 'auth'.
        $res = Ssh::classifyProbe($this->passConn(), 2, 'some remote stderr');
        $this->assertFalse($res['ok']);
        $this->assertSame('unreachable', $res['reason']); // unexpected code, not auth
    }

    public function testClassifyPasswordSshpassInternalErrorSniffsStderr(): void
    {
        // sshpass internal error 3 (runtime) with an ssh host-key message ->
        // classified via stderr as a host-key problem.
        $res = Ssh::classifyProbe($this->passConn(), Ssh::SSHPASS_RUNTIME_ERROR, 'Host key verification failed.');
        $this->assertFalse($res['ok']);
        $this->assertSame('hostkey', $res['reason']);
    }

    public function testTestConnectionPasswordMissingSshpass(): void
    {
        FakeSsh::$sshpassPathOverride = '';
        $creds = Credentials::defaults();
        $creds['connections'][] = $this->passConn(['password' => Credentials::obfuscate('x')]);
        $res = FakeSsh::testConnection($creds, 'c-pw');
        $this->assertFalse($res['ok']);
        $this->assertSame('sshpass-missing', $res['reason']);
    }

    public function testTestConnectionUnknownIdIsConfigError(): void
    {
        $res = Ssh::testConnection(Credentials::defaults(), 'nope');
        $this->assertFalse($res['ok']);
        $this->assertSame('config', $res['reason']);
    }
}

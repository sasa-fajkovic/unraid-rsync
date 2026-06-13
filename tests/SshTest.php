<?php

use PHPUnit\Framework\TestCase;

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

        // The probe argv ends with user@host and the trivial `true` command.
        $argv = FakeSsh::$lastProbeArgv;
        $this->assertIsArray($argv);
        $this->assertSame('true', end($argv));
        $this->assertContains('sasa@h.example', $argv);
        // KEY auth: no sshpass prefix in front of ssh.
        $this->assertSame('ssh', $argv[0]);
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

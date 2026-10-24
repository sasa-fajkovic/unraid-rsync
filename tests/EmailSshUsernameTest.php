<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailUsernameSsh extends Ssh
{
    public static ?array $probeArgv = null;
    public static int $tokenMints = 0;

    protected static function runProbe(array $argv, ?array $env = null, int $timeoutSec = 30): array
    {
        self::$probeArgv = $argv;
        return [0, ''];
    }

    public static function newRuntimeToken(string $connId): string
    {
        self::$tokenMints++;
        return parent::newRuntimeToken($connId);
    }
}

final class EmailSshUsernameTest extends TestCase
{
    private string $runtime;

    protected function setUp(): void
    {
        TestSeams::reset();
        $this->runtime = sys_get_temp_dir() . '/ur-email-user-' . bin2hex(random_bytes(8));
        Ssh::$runtimeBase = $this->runtime;
        EmailUsernameSsh::$probeArgv = null;
        EmailUsernameSsh::$tokenMints = 0;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->runtime)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->runtime, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->runtime);
        }
        TestSeams::reset();
    }

    private function connection(string $user, string $host = 'ersync.pcloud.com'): array
    {
        return Credentials::mergeConnection([
            'id' => 'c-email', 'name' => 'Email account', 'host' => $host,
            'username' => $user, 'authMethod' => 'PASSWORD',
            'password' => Credentials::obfuscate('test-secret'),
        ]);
    }

    public static function acceptedUsers(): array
    {
        return [
            'email' => ['backup@example.com'],
            'plus alias' => ['backup+nas@example.com'],
            'plus account' => ['backup+nas'],
            'punctuation' => ['first.last_name-1+nas@sub.example.com'],
            'underscore prefix' => ['_backup@example.com'],
            'last at separator' => ['backup@tenant@example.com'],
            'legacy account' => ['backup-user'],
        ];
    }

    #[DataProvider('acceptedUsers')]
    public function testSaveAndReloadPreserveSafeSshUsernames(string $user): void
    {
        $conn = $this->connection($user);
        $creds = Credentials::defaults();
        $this->assertTrue(Credentials::isSafeSshUser($user));
        $result = Credentials::validateConnection($conn, $creds);
        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['valid']);
        $creds['connections'][] = $conn;
        Credentials::withLock(static function () use ($creds): void {
            Credentials::save($creds);
        });
        try {
            $stored = Credentials::findConnection(Credentials::load(), 'c-email');
            $this->assertSame($user, $stored['username']);
        } finally {
            Credentials::withLock(static function (): void {
                Credentials::save(Credentials::defaults());
            });
        }
    }

    #[DataProvider('acceptedUsers')]
    public function testMaterializationAndProbePreserveUsernameAndEndpoint(string $user): void
    {
        foreach (['ersync.pcloud.com', '[2001:db8::1]', '[fe80::1%eth0]'] as $host) {
            $creds = Credentials::defaults();
            $creds['connections'][] = $this->connection($user, $host);
            $mat = EmailUsernameSsh::materialize($creds, 'c-email');
            $this->assertTrue($mat['ok'], $mat['error'] ?? '');
            try {
                $this->assertSame($user, $mat['conn']['username']);
                $this->assertSame($host, $mat['conn']['host']);
            } finally {
                EmailUsernameSsh::cleanupRuntime($mat['token']);
            }
            $result = EmailUsernameSsh::testConnection($creds, 'c-email');
            $this->assertTrue($result['ok'], $result['message']);
            $argv = EmailUsernameSsh::$probeArgv;
            $this->assertSame(['--', $user . '@' . trim($host, '[]'), 'true'], array_slice($argv, -3));
            $this->assertNotContains('test-secret', $argv);
        }
    }

    #[DataProvider('acceptedUsers')]
    public function testRsyncPushAndPullPreserveRemoteOperand(string $user): void
    {
        foreach (['ersync.pcloud.com', '[fe80::1%eth0]'] as $host) {
            foreach (['PUSH', 'PULL'] as $direction) {
                $pair = Runner::resolvePair(['direction' => $direction],
                    ['local' => '/mnt/user/source/', 'remote' => '/backup/'], 'SSH', $user . '@' . $host);
                $remote = $user . '@' . $host . ':/backup/';
                $expected = $direction === 'PUSH' ? ['/mnt/user/source/', $remote] : [$remote, '/mnt/user/source/'];
                $this->assertSame($expected, array_values($pair));
                $argv = Rsync::buildArgv(Config::defaultRsyncOptions(), 'normal', '/tmp/test.log',
                    $pair['src'], $pair['dest'], ['dashE' => "'ssh'", 'sshEnv' => []]);
                $this->assertSame(array_merge(['--'], $expected), array_slice($argv, -3));
            }
        }
    }

    #[DataProvider('acceptedUsers')]
    public function testSnapshotPreservesUsernameAndNormalizesIpv6(string $user): void
    {
        $seen = [];
        Snapshot::$remoteRunner = static function (array $argv) use (&$seen): array {
            $seen = $argv;
            return ['exit' => 0];
        };
        foreach (['ersync.pcloud.com', '[2001:db8::1]', '2001:db8::1', '[fe80::1%eth0]', 'fe80::1%eth0'] as $host) {
            $result = Snapshot::runRemote(['sshArgv' => ['ssh'], 'sshEnv' => []],
                $user . '@' . $host, 'probe', [], false, 30);
            $this->assertSame(0, $result['exit']);
            $this->assertSame('--', $seen[3]);
            $this->assertSame($user . '@' . trim($host, '[]'), $seen[4]);
            $this->assertCount(6, $seen);
        }
    }

    public static function unsafeUsers(): array
    {
        return [
            'colon' => ['backup@example.com:evil'],
            'slash' => ['backup@example.com/evil'],
            'option' => ['-oProxyCommand=evil'],
            'leading dot' => ['.backup@example.com'],
            'leading plus' => ['+backup@example.com'],
            'leading at' => ['@example.com'],
            'space' => ['backup @example.com'],
            'tab' => ["backup\t@example.com"],
            'newline' => ["backup\n@example.com"],
            'carriage return' => ["backup\r@example.com"],
            'nul' => ["backup\0@example.com"],
            'control' => ["backup\x01@example.com"],
            'delete control' => ["backup\x7f@example.com"],
            'semicolon' => ['backup;id@example.com'],
            'pipe' => ['backup|id@example.com'],
            'ampersand' => ['backup&id@example.com'],
            'substitution' => ['backup$(id)@example.com'],
            'backtick' => ['backup`id`@example.com'],
            'single quote' => ["backup'@example.com"],
            'double quote' => ['backup"@example.com'],
            'backslash' => ['DOMAIN\\backup@example.com'],
            'redirect' => ['backup>file@example.com'],
            'brackets' => ['backup[1]@example.com'],
            'wildcard' => ['backup*@example.com'],
            'percent' => ['backup%1@example.com'],
            'unicode' => ['bäckup@example.com'],
            'empty' => [''],
        ];
    }

    #[DataProvider('unsafeUsers')]
    public function testUnsafeUserFailsSaveAndRuntimeBeforeSecretsOrProbe(string $user): void
    {
        $creds = Credentials::defaults();
        $conn = $this->connection($user);
        $result = Credentials::validateConnection($conn, $creds);
        $this->assertFalse(Credentials::isSafeSshUser($user));
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $creds['connections'][] = $conn;
        $mat = EmailUsernameSsh::materialize($creds, 'c-email');
        if ($user !== '') {
            $this->assertFalse($mat['ok']);
            $this->assertStringContainsString(Credentials::SSH_USER_RULE, $mat['error']);
            $this->assertSame(0, EmailUsernameSsh::$tokenMints);
            $this->assertDirectoryDoesNotExist($this->runtime);
        } else {
            if ($mat['ok']) {
                EmailUsernameSsh::cleanupRuntime($mat['token']);
            }
        }
        $probe = EmailUsernameSsh::testConnection($creds, 'c-email');
        $this->assertFalse($probe['ok']);
        $this->assertNull(EmailUsernameSsh::$probeArgv);
    }

    #[DataProvider('unsafeUsers')]
    public function testSnapshotRejectsUnsafeUserBeforeExecution(string $user): void
    {
        Snapshot::$remoteRunner = function (): array {
            $this->fail('Unsafe destination reached SSH');
        };
        $this->expectException(InvalidArgumentException::class);
        Snapshot::runRemote(['sshArgv' => ['ssh']], $user . '@nas.example', 'probe', [], false, 30);
    }

    public static function unsafeSnapshotDestinations(): array
    {
        return [
            'leading user space' => [' backup@example.com@nas.example'],
            'trailing user space' => ['backup@example.com @nas.example'],
            'leading user tab' => ["\tbackup@example.com@nas.example"],
            'trailing user newline' => ["backup@example.com\n@nas.example"],
            'leading user nul' => ["\0backup@example.com@nas.example"],
            'trailing user nul' => ["backup@example.com\0@nas.example"],
            'leading host space' => ['backup@example.com@ nas.example'],
            'trailing host space' => ['backup@example.com@nas.example '],
            'leading host tab' => ["backup@example.com@\tnas.example"],
            'trailing host newline' => ["backup@example.com@nas.example\n"],
            'leading host nul' => ["backup@example.com@\0nas.example"],
            'trailing host nul' => ["backup@example.com@nas.example\0"],
            'embedded host control' => ["backup@example.com@nas\x01.example"],
            'host port suffix' => ['backup@example.com@nas.example:22'],
            'host slash' => ['backup@example.com@nas.example/path'],
            'host option' => ['backup@example.com@-oProxyCommand=evil'],
            'host open bracket' => ['backup@example.com@[2001:db8::1'],
            'host close bracket' => ['backup@example.com@2001:db8::1]'],
            'host nested brackets' => ['backup@example.com@[[2001:db8::1]]'],
            'host bracketed name' => ['backup@example.com@[nas.example]'],
            'host bracket suffix' => ['backup@example.com@[2001:db8::1]evil'],
            'host substitution' => ['backup@example.com@nas$(id).example'],
            'host backtick' => ['backup@example.com@nas`id`.example'],
            'host semicolon' => ['backup@example.com@nas;id'],
            'empty host' => ['backup@example.com@'],
        ];
    }

    #[DataProvider('unsafeSnapshotDestinations')]
    public function testSnapshotRejectsUnsafeDestinationBeforeExecution(string $destination): void
    {
        Snapshot::$remoteRunner = function (): array {
            $this->fail('Unsafe destination reached SSH');
        };
        $this->expectException(InvalidArgumentException::class);
        Snapshot::runRemote(['sshArgv' => ['ssh']], $destination, 'probe', [], false, 30);
    }

    public function testEmailAllowanceDoesNotRelaxHostOrDaemonValidation(): void
    {
        foreach (['backup@example.com', 'backup+alias'] as $value) {
            $this->assertFalse(Credentials::isSafeSshHost($value));
            $conn = $this->connection('backup', $value);
            $result = Credentials::validateConnection($conn, Credentials::defaults());
            $this->assertContains(Credentials::SSH_HOST_RULE, $result['errors']);
        }
        $this->assertFalse(Credentials::isSafeDaemonToken('backup@example.com'));
        $conn = $this->connection('backup@example.com');
        $conn['transport'] = 'DAEMON';
        $result = Credentials::validateConnection($conn, Credentials::defaults());
        $this->assertFalse($result['valid']);
    }
}

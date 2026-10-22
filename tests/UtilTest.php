<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Util.php: the shared filename-sanitiser and first-line helpers
 * used across Ssh / Logger / RunState / History / Runner / KeyTools / Rsync,
 * plus the atomic writer, the file lock and the private runtime-dir check.
 */
final class UtilTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ur-util-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        Util::$euid             = null;
        RunState::$baseOverride = null;
        Logger::$baseOverride   = null;
        Ssh::$runtimeBase       = '/tmp/unraid.rsync';
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testSafeFileIdStripsUnsafeChars(): void
    {
        $this->assertSame('....etcpasswd', Util::safeFileId('../../etc/passwd'));
    }

    public function testSafeFileIdCollapsesPureDotsToUnknown(): void
    {
        $this->assertSame('unknown', Util::safeFileId('..'));
    }

    public function testSafeFileIdCollapsesEmptyToUnknown(): void
    {
        $this->assertSame('unknown', Util::safeFileId(''));
    }

    public function testFirstLineReturnsFirstNonEmptyTrimmedLine(): void
    {
        $this->assertSame('hello', Util::firstLine("\n  \nhello\nworld\n"));
    }

    public function testFirstLineReturnsEmptyStringForBlankInput(): void
    {
        $this->assertSame('', Util::firstLine("\n \n"));
    }

    public function testIsOnRootFsJudgesThePathByItsFirstExistingAncestor(): void
    {
        // "/" and /mnt are the RAM root (1); /mnt/user is shfs (2), /mnt/disks/usb
        // an empty mountpoint left on the root.
        $devs = ['/' => 1, '/mnt' => 1, '/mnt/user' => 2, '/mnt/user/share' => 2, '/mnt/disks' => 1, '/mnt/disks/usb' => 1];
        $prev = Util::$devIdOf;
        Util::$devIdOf = static fn(string $p): int|false => $devs[$p] ?? false;
        try {
            $this->assertFalse(Util::isOnRootFs('/mnt/user/share/'));
            $this->assertFalse(Util::isOnRootFs('/mnt/user/share/not/yet/created'));
            $this->assertTrue(Util::isOnRootFs('/mnt/disks/usb/backup'), 'unmounted: --mkpath would create it in RAM');
            $this->assertTrue(Util::isOnRootFs('/mnt/disks/usb'));
            $this->assertTrue(Util::isOnRootFs('/mnt/cache/appdata'), '/mnt/cache missing: array stopped');
        } finally {
            Util::$devIdOf = $prev;
        }
    }

    public function testIsOnRootFsTreatsASeparateMntTmpfsAsRam(): void
    {
        // /mnt is its own tmpfs (5); /mnt/personal is a zfs pool (13) unless the
        // pool is not imported and only its empty mountpoint on /mnt is left.
        $devs = ['/' => 1, '/mnt' => 5, '/mnt/personal' => 13, '/boot' => 7];
        $prev = Util::$devIdOf;
        Util::$devIdOf = static function (string $p) use (&$devs): int|false {
            return $devs[$p] ?? false;
        };
        try {
            $this->assertFalse(Util::isOnRootFs('/mnt/personal/nextcloud/'));
            $this->assertFalse(Util::isOnRootFs('/boot/'));
            $devs['/mnt/personal'] = 5;
            $this->assertTrue(Util::isOnRootFs('/mnt/personal/nextcloud/'));
            unset($devs['/mnt/personal']);
            $this->assertTrue(Util::isOnRootFs('/mnt/personal/nextcloud/'), 'no mountpoint at all: /mnt itself');
        } finally {
            Util::$devIdOf = $prev;
        }
    }

    public function testIsOnRootFsUsesStatWithoutTheSeam(): void
    {
        $prev = Util::$devIdOf;
        Util::$devIdOf = null;
        try {
            $this->assertTrue(Util::isOnRootFs('/'));
            $this->assertTrue(Util::isOnRootFs('/no-such-dir-' . bin2hex(random_bytes(4)) . '/x'));
        } finally {
            Util::$devIdOf = $prev;
        }
    }

    public function testIsIpv6AddressAcceptsAZoneIdThatFilterVarRefuses(): void
    {
        foreach (['2001:db8::1', '::1', 'fe80::1%eth0', 'fe80::1%br0.10', 'fe80::1%2'] as $a) {
            $this->assertTrue(Util::isIpv6Address($a), $a);
        }
        foreach (['10.0.0.5', '10.0.0.5%eth0', 'fe80::1%', 'fe80::1%eth0;id', 'fe80::1%eth0@if5', '[fe80::1]', 'nas', ''] as $a) {
            $this->assertFalse(Util::isIpv6Address($a), $a);
        }
    }

    public function testMkdirsCreatesTheMissingLevelsAndChmodsOnlyThose(): void
    {
        chmod($this->dir, 0755);
        Util::mkdirs($this->dir . '/a/b', 0700);
        clearstatcache();
        $this->assertSame(0700, fileperms($this->dir . '/a') & 0777);
        $this->assertSame(0700, fileperms($this->dir . '/a/b') & 0777);
        $this->assertSame(0755, fileperms($this->dir) & 0777, 'an existing level is never chmodded');

        chmod($this->dir . '/a/b', 0755);
        Util::mkdirs($this->dir . '/a/b', 0700);
        clearstatcache();
        $this->assertSame(0755, fileperms($this->dir . '/a/b') & 0777, 'nor is an existing leaf');
    }

    /** A planted link below /mnt would carry a recursive mkdir anywhere. */
    public function testMkdirsRefusesToCreateThroughASymlinkedAncestor(): void
    {
        mkdir($this->dir . '/elsewhere');
        symlink($this->dir . '/elsewhere', $this->dir . '/link');
        try {
            Util::mkdirs($this->dir . '/link/new/leaf', 0700);
            $this->fail('expected a refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('through the symlink ' . $this->dir . '/link', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->dir . '/elsewhere/new');
    }

    public function testWriteAtomicReplacesTheFileAtTheGivenModeAndLeavesNoTemp(): void
    {
        $path = $this->dir . '/f.json';
        file_put_contents($path, 'old');
        Util::writeAtomic($path, 'new', 0600);

        $this->assertSame('new', file_get_contents($path));
        clearstatcache();
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(['f.json'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    public function testWriteAtomicThrowsAndLeavesNoTempWhenTheRenameFails(): void
    {
        $path = $this->dir . '/occupied';
        mkdir($path);
        try {
            Util::writeAtomic($path, 'data', 0644);
            $this->fail('a rename over a directory must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to atomically replace', $e->getMessage());
        }
        $this->assertDirectoryExists($path);
        $this->assertSame(['occupied'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])));
    }

    /** RunState::write keeps the messages it had before it moved onto writeAtomic. */
    public function testWriteAtomicNamesTheFileAndCodesTheFailedStage(): void
    {
        $path = $this->dir . '/occupied';
        mkdir($path);
        try {
            Util::writeAtomic($path, 'data', 0600, false, 'state');
            $this->fail('a rename over a directory must throw');
        } catch (RuntimeException $e) {
            $this->assertSame("Failed to atomically replace state file: $path", $e->getMessage());
            $this->assertSame(Util::WRITE_RENAME, $e->getCode());
        }
        try {
            Util::writeAtomic($this->dir . '/missing/f', 'data', 0600, false, 'state');
            $this->fail('a missing dir must throw');
        } catch (RuntimeException $e) {
            $this->assertSame('Unable to create temp state file in: ' . $this->dir . '/missing', $e->getMessage());
            $this->assertSame(Util::WRITE_TEMP, $e->getCode());
        }
    }

    public function testWriteAtomicRefusesToStageOutsideAnUnwritableTargetDir(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('root writes into a 0500 dir anyway');
        }
        $path = $this->dir . '/f.json';
        file_put_contents($path, 'old');
        chmod($this->dir, 0500);
        try {
            Util::writeAtomic($path, 'new', 0644);
            $this->fail('a temp file outside the target dir must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unable to create temp file in', $e->getMessage());
        } finally {
            chmod($this->dir, 0700);
        }
        $this->assertSame('old', file_get_contents($path));
    }

    public function testWithLockIsExclusiveAcrossFileHandlesAndReentrant(): void
    {
        $lock  = $this->dir . '/x.lock';
        $probe = fopen($lock, 'c');
        $this->assertNotFalse($probe);
        $result = Util::withLock($lock, static function () use ($lock, $probe): array {
            return [
                !flock($probe, LOCK_EX | LOCK_NB),
                Util::withLock($lock, static fn(): string => 'nested ran'),
            ];
        });
        $this->assertSame([true, 'nested ran'], $result);
        $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB), 'released after the outer call');
        fclose($probe);
    }

    public function testWithLockReleasesWhenTheCallbackThrows(): void
    {
        $lock = $this->dir . '/x.lock';
        try {
            Util::withLock($lock, static function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $probe = fopen($lock, 'c');
        $this->assertNotFalse($probe);
        $this->assertTrue(flock($probe, LOCK_EX | LOCK_NB));
        fclose($probe);
    }

    public function testAssertPrivateDirAcceptsADirThisProcessOwns(): void
    {
        Util::assertPrivateDir($this->dir);
        chmod($this->dir, 0755);
        Util::assertPrivateDir($this->dir);
        $this->addToAssertionCount(2);
    }

    public function testAssertPrivateDirComparesTheOwnerWithTheCurrentEuid(): void
    {
        $this->assertSame(posix_geteuid(), fileowner($this->dir), 'the suite created it');
        Util::$euid = posix_geteuid() + 1;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not owned by uid ' . (posix_geteuid() + 1));
        Util::assertPrivateDir($this->dir);
    }

    public function testAssertPrivateDirRefusesAGroupOrWorldWritableDir(): void
    {
        foreach ([0770, 0703] as $mode) {
            chmod($this->dir, $mode);
            try {
                Util::assertPrivateDir($this->dir);
                $this->fail(sprintf('mode %o must be refused', $mode));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('writable', $e->getMessage());
            }
        }
        chmod($this->dir, 0700);
    }

    public function testAssertPrivateDirRefusesASymlinkAndAFile(): void
    {
        symlink($this->dir, $this->dir . '/link');
        touch($this->dir . '/file');
        foreach (['link', 'file'] as $name) {
            try {
                Util::assertPrivateDir($this->dir . '/' . $name);
                $this->fail("$name must be refused");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a real directory', $e->getMessage());
            }
        }
    }

    /**
     * A local user who pre-creates the runtime base under /tmp keeps owning it
     * after root's chmod 0700. Every creator of a runtime dir must refuse it.
     */
    public function testEveryRuntimeDirCreatorRefusesADirAnotherUserOwns(): void
    {
        RunState::$baseOverride = $this->dir . '/rs';
        Logger::$baseOverride   = $this->dir . '/lg';
        Ssh::$runtimeBase       = $this->dir . '/ssh';
        Util::$euid             = posix_geteuid() + 1;

        $callers = [
            'RunState' => static fn() => RunState::write('j', ['pid' => 1]),
            'Logger'   => static fn() => Logger::openRun('j'),
            'Ssh'      => static fn() => Ssh::ensureMarkersDir(),
        ];
        foreach ($callers as $name => $call) {
            try {
                $call();
                $this->fail("$name accepted a runtime dir another uid owns");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not owned by uid', $e->getMessage(), $name);
            }
        }

        Util::$euid = null;
        RunState::write('j', ['pid' => 1]);
        $this->assertFileExists(Logger::openRun('j'));
        $this->assertDirectoryExists(Ssh::ensureMarkersDir());
    }

    public function testEnsurePrivateDirChmodsOnlyALevelItCreated(): void
    {
        $made = $this->dir . '/made';
        $old  = umask(0077);
        try {
            Util::ensurePrivateDir($made, 'state');
        } finally {
            umask($old);
        }
        $this->assertSame(0700, fileperms($made) & 0777);

        chmod($made, 0755);
        Util::ensurePrivateDir($made, 'state');
        $this->assertSame(0755, fileperms($made) & 0777, 'an existing dir is never chmodded');
    }

    public function testEnsurePrivateDirRefusesASymlinkAFileAndAMissingParent(): void
    {
        symlink($this->dir, $this->dir . '/link');
        touch($this->dir . '/file');
        $cases = [
            'link'        => 'Refusing to use a symlinked state dir',
            'file'        => 'State path exists but is not a directory',
            'missing/sub' => 'Unable to create state dir',
        ];
        foreach ($cases as $name => $message) {
            try {
                Util::ensurePrivateDir($this->dir . '/' . $name, 'state');
                $this->fail("$name must be refused");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testEveryRuntimeBaseHonoursUrRuntimeBase(): void
    {
        $base = rtrim(UR_RUNTIME_BASE, '/');
        Ssh::$runtimeBase = null;
        $this->assertSame($base, Util::runtimeBase());
        $this->assertSame($base, RunState::base());
        $this->assertSame($base, Logger::base());
        $this->assertSame($base, Ssh::base());
        $this->assertSame($base . '/keys', Ssh::keysDir());
    }
}

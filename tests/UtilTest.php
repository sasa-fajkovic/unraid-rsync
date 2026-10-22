<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Util.php: the shared filename-sanitiser and first-line helpers
 * used across Ssh / Logger / RunState / History / Runner / KeyTools / Rsync.
 */
final class UtilTest extends TestCase
{
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
}

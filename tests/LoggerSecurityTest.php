<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LoggerSecurityTest extends TestCase
{
    private string $rtBase;
    private ?string $extBase = null;

    protected function setUp(): void
    {
        $this->rtBase = sys_get_temp_dir() . '/ur-logger-security-' . bin2hex(random_bytes(6));
        Logger::$baseOverride = $this->rtBase;
    }

    protected function tearDown(): void
    {
        TestSeams::reset();
        foreach (array_filter([$this->rtBase, $this->extBase]) as $dir) {
            $this->removeTree($dir);
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->removeTree($path . '/' . $name);
            }
        }
        @rmdir($path);
    }

    public function testExternalWritableAncestorIsRefusedWithoutChangingPermissions(): void
    {
        $this->extBase = sys_get_temp_dir() . '/ur-logger-ext-' . bin2hex(random_bytes(4));
        mkdir($this->extBase, 0777);
        chmod($this->extBase, 0777);
        Logger::$logsDirOverride = $this->extBase . '/logs';
        $this->assertFalse(Logger::event('', 'audit', 'blocked'));
        $this->assertDirectoryDoesNotExist($this->extBase . '/logs');
        $this->assertSame(0777, fileperms($this->extBase) & 0777);
    }

    public function testPluginLogSymlinkCannotModifyItsTarget(): void
    {
        $path = Logger::openRun('audit');
        $victim = $this->rtBase . '/victim';
        file_put_contents($victim, 'sentinel');
        symlink($victim, Logger::pluginLogPath());
        $this->assertFalse(Logger::event($path, 'audit', 'blocked'));
        $this->assertSame('sentinel', file_get_contents($victim));
    }

    public function testRunLogSymlinkCannotBeAppendedTruncatedOrRead(): void
    {
        $path = Logger::openRun('audit');
        $victim = $this->rtBase . '/victim';
        file_put_contents($victim, 'sentinel');
        unlink($path);
        symlink($victim, $path);
        $this->assertFalse(Logger::append($path, 'blocked'));
        $failed = false;
        $this->assertFalse(Logger::enforceRunLogCap($path, $failed));
        $this->assertTrue($failed);
        $this->assertNull(Logger::runLogPathById('audit', basename($path)));
        $this->assertSame('sentinel', file_get_contents($victim));
    }

    public function testSymlinkedJobDirectoryCannotEscapeLogRoot(): void
    {
        Logger::openRun('audit');
        $outside = $this->rtBase . '/outside';
        mkdir($outside, 0700);
        file_put_contents($outside . '/run-20261028T000000Z.log', 'sentinel');
        $link = Logger::jobLogDir('escape');
        symlink($outside, $link);
        try {
            $this->assertNull(Logger::runLogPathById('escape', 'run-20261028T000000Z.log'));
            $this->assertSame(0, Logger::pruneRuns('escape', 1));
        } finally {
            unlink($link);
        }
    }

    public function testHardlinkedLogsCannotBeReadOrWritten(): void
    {
        $path = Logger::openRun('audit');
        $victim = $this->rtBase . '/victim';
        file_put_contents($victim, 'sentinel');
        unlink($path);
        link($victim, $path);
        link($victim, Logger::pluginLogPath());
        $this->assertNull(Logger::safeLogReadPath(Logger::pluginLogPath()));
        $this->assertNull(Logger::runLogPathById('audit', basename($path)));
        $this->assertFalse(Logger::event('', 'audit', 'blocked'));
        $this->assertFalse(Logger::append($path, 'blocked'));
        $this->assertSame('sentinel', file_get_contents($victim));
    }

    public function testWritableLogDirectoryCannotBeRead(): void
    {
        $path = Logger::openRun('audit');
        Logger::event($path, 'audit', 'sentinel');
        chmod(Logger::logsDir(), 0777);
        $this->assertNull(Logger::safeLogReadPath(Logger::pluginLogPath()));
        $this->assertNull(Logger::runLogPathById('audit', basename($path)));
        $this->assertSame(0777, fileperms(Logger::logsDir()) & 0777);
    }

    public function testReadValidationDoesNotCreateMissingDirectories(): void
    {
        $this->assertNull(Logger::safeLogReadPath(Logger::pluginLogPath()));
        $this->assertSame(Logger::jobLogDir('audit') . '/run-20261028T000000Z.log',
            Logger::runLogPathById('audit', 'run-20261028T000000Z.log'));
        $this->assertDirectoryDoesNotExist($this->rtBase);
    }

    public function testTrustedStickyAncestorAllowsPrivateChildButNotLogsAtItsRoot(): void
    {
        $this->extBase = sys_get_temp_dir() . '/ur-logger-ext-' . bin2hex(random_bytes(4));
        mkdir($this->extBase, 01777);
        chmod($this->extBase, 01777);
        Logger::$logsDirOverride = $this->extBase . '/logs';
        $this->assertSame('', Logger::logDirRefusal(Logger::logsDir()));
        $path = Logger::openRun('audit');
        $this->assertTrue(Logger::append($path, 'accepted'));
        $this->assertNotNull(Logger::safeLogReadPath($path));
        $this->assertNotSame('', Logger::logDirRefusal($this->extBase));
    }

    public function testSymlinkedLockAndWritableFilesAreRefused(): void
    {
        $path = Logger::openRun('audit');
        $victim = $this->rtBase . '/victim';
        file_put_contents($victim, 'sentinel');
        symlink($victim, Logger::pluginLogPath() . '.lock');
        $this->assertFalse(Logger::event('', 'audit', 'blocked'));
        $this->assertSame('sentinel', file_get_contents($victim));
        chmod($path, 0666);
        $this->assertFalse(Logger::append($path, 'blocked'));
        $this->assertNull(Logger::safeLogReadPath($path));
    }

    public function testOtherOwnersDirectoryAndFileAreRefused(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('Changing ownership requires root.');
        }
        $path = Logger::openRun('audit');
        chown($path, 65534);
        $this->assertFalse(Logger::append($path, 'blocked'));
        $this->assertNull(Logger::safeLogReadPath($path));
        chown(Logger::jobLogDir('audit'), 65534);
        $this->assertNull(Logger::runLogPathById('audit', basename($path)));
    }

    public function testSymlinkedRunCannotBeRedacted(): void
    {
        $path = Logger::openRun('audit');
        $victim = $this->rtBase . '/victim';
        file_put_contents($victim, 'sentinel');
        unlink($path);
        symlink($victim, $path);
        Logger::setRedaction(['sentinel']);
        $this->assertFalse(Logger::redactRunLog($path));
        $this->assertSame('sentinel', file_get_contents($victim));
    }
    public function testMalformedLogDirectoryIsRefused(): void
    {
        $this->assertNotSame('', Logger::logDirRefusal(''));
        $this->assertNotSame('', Logger::logDirRefusal('relative/logs'));
    }
}

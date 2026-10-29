<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
        LogDirectory::$afterEnter = null;
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

    public function testExternalWritableAncestorPreservesPersistentLogsAndPermissions(): void
    {
        $this->extBase = sys_get_temp_dir() . '/ur-logger-ext-' . bin2hex(random_bytes(4));
        mkdir($this->extBase, 0777);
        chmod($this->extBase, 0777);
        Logger::$logsDirOverride = $this->extBase . '/logs';
        $path = Logger::openRun('audit');
        $this->assertTrue(Logger::event($path, 'audit', 'persisted'));
        $this->assertSame(0777, fileperms($this->extBase) & 0777);
        $this->assertSame(0700, fileperms(Logger::logsDir()) & 0777);
        TestSeams::reset();
        Logger::$baseOverride = $this->rtBase;
        Logger::$logsDirOverride = $this->extBase . '/logs';
        $this->assertStringContainsString('persisted', Logger::tail($path));
        $this->assertStringContainsString('persisted', Logger::tail(Logger::pluginLogPath()));
        $this->assertDirectoryDoesNotExist($this->rtBase . '/logs');
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

    public function testStickyAncestorIsUsableAndAdvisedAgainstAsTheRoot(): void
    {
        $this->extBase = sys_get_temp_dir() . '/ur-logger-ext-' . bin2hex(random_bytes(4));
        mkdir($this->extBase, 01777);
        chmod($this->extBase, 01777);
        Logger::$logsDirOverride = $this->extBase . '/logs';
        $this->assertSame('', Logger::logDirRefusal(Logger::logsDir()));
        $path = Logger::openRun('audit');
        $this->assertTrue(Logger::append($path, 'accepted'));
        $this->assertNotNull(Logger::safeLogReadPath($path));
        $this->assertSame('', Logger::logDirRefusal($this->extBase));
        $this->assertNotSame('', Logger::logDirWarning($this->extBase));
        $this->assertSame('', Logger::logDirWarning(Logger::logsDir()));
    }

    public function testSymlinkedLockIsRefusedButPermissiveFilesStillLog(): void
    {
        $path = Logger::openRun('audit');
        $victim = $this->rtBase . '/victim';
        file_put_contents($victim, 'sentinel');
        symlink($victim, Logger::pluginLogPath() . '.lock');
        $this->assertFalse(Logger::event('', 'audit', 'blocked'));
        $this->assertSame('sentinel', file_get_contents($victim));
        chmod($path, 0666);
        $this->assertTrue(Logger::append($path, 'accepted'));
        $this->assertNotNull(Logger::safeLogReadPath($path));
    }

    public function testLockCreatedUnderUmaskZeroKeepsPluginLogWorking(): void
    {
        $mask = umask(0);
        try {
            $this->assertTrue(Logger::event('', 'audit', 'first'));
        } finally {
            umask($mask);
        }
        chmod(Logger::pluginLogPath() . '.lock', 0666);
        $this->assertTrue(Logger::event('', 'audit', 'second'));
        $this->assertStringContainsString('second', Logger::tail(Logger::pluginLogPath()));
    }

    public function testPersistentShareWrittenByOthersKeepsLoggingButNeverFollowsLinks(): void
    {
        $this->extBase = sys_get_temp_dir() . '/ur-logger-ext-' . bin2hex(random_bytes(4));
        mkdir($this->extBase . '/logs', 0777, true);
        chmod($this->extBase . '/logs', 0777);
        Logger::$logsDirOverride = $this->extBase . '/logs';
        $path = Logger::openRun('audit');
        $this->assertTrue(Logger::event($path, 'audit', 'persisted'));
        $this->assertStringContainsString('persisted', Logger::tail($path));
        $this->assertSame(0777, fileperms($this->extBase . '/logs') & 0777);
        $victim = $this->extBase . '/victim';
        file_put_contents($victim, 'sentinel');
        rename(Logger::pluginLogPath(), Logger::pluginLogPath() . '.old');
        symlink($victim, Logger::pluginLogPath());
        $this->assertFalse(Logger::event('', 'audit', 'blocked'));
        $this->assertSame('sentinel', file_get_contents($victim));
    }

    public function testOtherOwnersFileStillLogsButAForeignRuntimeDirIsRefused(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            $this->markTestSkipped('Changing ownership requires root.');
        }
        $path = Logger::openRun('audit');
        chown($path, 65534);
        $this->assertTrue(Logger::append($path, 'accepted'));
        $this->assertNotNull(Logger::safeLogReadPath($path));
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
    public static function pinnedOperations(): array
    {
        return [['append'], ['read'], ['cap'], ['redact'], ['prune']];
    }

    #[DataProvider('pinnedOperations')]
    public function testPinnedOperationsSurviveLogRootReplacement(string $operation): void
    {
        $cwd = getcwd();
        $this->extBase = sys_get_temp_dir() . '/ur-logger-ext-' . bin2hex(random_bytes(4));
        mkdir($this->extBase, 0777);
        chmod($this->extBase, 0777);
        $root = $this->extBase . '/logs';
        $moved = $this->extBase . '/moved';
        $outside = $this->extBase . '/outside';
        Logger::$logsDirOverride = $root;
        $path = Logger::openRun('audit', 1750000000);
        $latest = Logger::openRun('audit', 1750000001);
        $body = $operation === 'cap' ? str_repeat('x', 512) : "original <tag> hide-me\n";
        file_put_contents($path, $body);
        mkdir($outside . '/audit', 0700, true);
        file_put_contents($outside . '/audit/' . basename($path), 'outside sentinel');
        file_put_contents($outside . '/audit/' . basename($latest), 'outside latest sentinel');
        Logger::$maxRunLogBytesOverride = 64;
        Logger::setRedaction(['hide-me']);
        LogDirectory::$afterEnter = static function () use ($root, $moved, $outside): void {
            LogDirectory::$afterEnter = null;
            rename($root, $moved);
            symlink($outside, $root);
        };
        $movedPath = $moved . '/audit/' . basename($path);
        switch ($operation) {
            case 'append':
                $this->assertTrue(Logger::append($path, 'appended'));
                $this->assertStringContainsString('appended', file_get_contents($movedPath));
                break;
            case 'read':
                $this->assertStringContainsString('original &lt;tag&gt;', Logger::tail($path));
                break;
            case 'cap':
                $failed = false;
                $this->assertTrue(Logger::enforceRunLogCap($path, $failed));
                $this->assertFalse($failed);
                $this->assertLessThan(512, filesize($movedPath));
                break;
            case 'redact':
                $this->assertTrue(Logger::redactRunLog($path));
                $this->assertStringNotContainsString('hide-me', file_get_contents($movedPath));
                break;
            case 'prune':
                $this->assertSame(1, Logger::pruneRuns('audit', 1));
                $this->assertFileDoesNotExist($movedPath);
                $this->assertFileExists($moved . '/audit/' . basename($latest));
                break;
        }
        $this->assertSame('outside sentinel', file_get_contents($outside . '/audit/' . basename($path)));
        $this->assertSame('outside latest sentinel', file_get_contents($outside . '/audit/' . basename($latest)));
        $this->assertSame($cwd, getcwd());
    }

    public function testDeletedPinnedLogNeverCreatesAStrayFile(): void
    {
        Logger::withRunLogs(function (): void {
            $path = Logger::openRun('audit');
            unlink($path);
            $this->assertTrue(Logger::append($path, 'still pinned'));
            $this->assertSame([], array_values(array_diff(scandir(dirname($path)) ?: [], ['.', '..'])));
        });
    }
}

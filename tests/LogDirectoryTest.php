<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../source/include/LogDirectory.php';

final class LogDirectoryTest extends TestCase
{
    private string $base;
    private string $originalCwd;

    protected function setUp(): void
    {
        $this->originalCwd = (string) getcwd();
        $this->base = sys_get_temp_dir() . '/ur-log-directory-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0700);
    }

    protected function tearDown(): void
    {
        LogDirectory::$afterEnter = null;
        chdir($this->originalCwd);
        $this->removeTree($this->base);
        TestSeams::reset();
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
        rmdir($path);
    }

    public function testWritableAncestorWithPrivateLeafWorksWithoutChmod(): void
    {
        chmod($this->base, 0777);
        mkdir($this->base . '/logs', 0700);
        $result = LogDirectory::withDirectory($this->base . '/logs', static function (): string {
            file_put_contents('run.log', 'inside');
            return 'result';
        });
        $this->assertSame('result', $result);
        $this->assertSame('inside', file_get_contents($this->base . '/logs/run.log'));
        $this->assertSame(0777, fileperms($this->base) & 0777);
        $this->assertSame($this->originalCwd, getcwd());
    }

    public function testCreationIsPrivateEvenWithPermissiveUmask(): void
    {
        $mask = umask(0000);
        try {
            LogDirectory::withDirectory($this->base . '/new/job', static fn(): int => 7, true, $this->base . '/new');
            $this->assertSame(0000, umask());
        } finally {
            umask($mask);
        }
        $this->assertSame(0700, fileperms($this->base . '/new') & 0777);
        $this->assertSame(0700, fileperms($this->base . '/new/job') & 0777);
    }

    public function testCallbackExceptionRestoresCwd(): void
    {
        try {
            LogDirectory::withDirectory($this->base, static function (): never {
                throw new LogicException('callback failed');
            });
            $this->fail('Expected callback exception.');
        } catch (LogicException $e) {
            $this->assertSame('callback failed', $e->getMessage());
        }
        $this->assertSame($this->originalCwd, getcwd());
    }

    public function testParentSwapAfterPinCannotRedirectWrite(): void
    {
        mkdir($this->base . '/logs', 0700);
        mkdir($this->base . '/outside', 0700);
        file_put_contents($this->base . '/outside/run.log', 'sentinel');
        LogDirectory::$afterEnter = function (): void {
            LogDirectory::$afterEnter = null;
            rename($this->base . '/logs', $this->base . '/moved');
            symlink($this->base . '/outside', $this->base . '/logs');
        };
        LogDirectory::withDirectory($this->base . '/logs', static function (): void {
            file_put_contents('run.log', 'pinned');
        });
        $this->assertSame('pinned', file_get_contents($this->base . '/moved/run.log'));
        $this->assertSame('sentinel', file_get_contents($this->base . '/outside/run.log'));
    }

    public function testNestedCallRestoresRenamedOriginalDirectoryByInode(): void
    {
        if (!is_dir('/proc/self/fd')) {
            $this->markTestSkipped('Pinned restoration after rename requires procfs.');
        }
        mkdir($this->base . '/outer', 0700);
        mkdir($this->base . '/inner', 0700);
        mkdir($this->base . '/outside', 0700);
        LogDirectory::withDirectory($this->base . '/outer', function (): void {
            $original = stat('.');
            LogDirectory::$afterEnter = function (): void {
                LogDirectory::$afterEnter = null;
                rename($this->base . '/outer', $this->base . '/moved');
                symlink($this->base . '/outside', $this->base . '/outer');
            };
            LogDirectory::withDirectory($this->base . '/inner', static fn(): null => null);
            clearstatcache(true, '.');
            $this->assertSame($original['ino'], stat('.')['ino']);
            file_put_contents('after.log', 'restored');
        });
        $this->assertSame('restored', file_get_contents($this->base . '/moved/after.log'));
        $this->assertFileDoesNotExist($this->base . '/outside/after.log');
    }

    public function testSymlinkComponentIsRefused(): void
    {
        mkdir($this->base . '/target', 0700);
        symlink($this->base . '/target', $this->base . '/link');
        $called = false;
        try {
            LogDirectory::withDirectory($this->base . '/link', static function () use (&$called): void {
                $called = true;
            });
            $this->fail('Expected symlink refusal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('symlink', $e->getMessage());
        }
        $this->assertFalse($called);
        $this->assertSame($this->originalCwd, getcwd());
    }

    public function testFifoIsRefusedWithoutBlocking(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('Requires POSIX.');
        }
        posix_mkfifo($this->base . '/fifo', 0600);
        $this->expectException(RuntimeException::class);
        LogDirectory::withDirectory($this->base . '/fifo', static fn(): null => null);
    }

    public function testForeignOwnedFinalDirectoryIsRefused(): void
    {
        if (!function_exists('posix_geteuid')) {
            $this->markTestSkipped('Requires POSIX.');
        }
        Util::$euid = posix_geteuid() + 1;
        $this->expectException(RuntimeException::class);
        LogDirectory::withDirectory($this->base, static fn(): null => null);
    }

    public function testPrivateRootProtectsIntermediateRuntimeLevels(): void
    {
        mkdir($this->base . '/runtime', 0700);
        mkdir($this->base . '/runtime/logs', 0777);
        chmod($this->base . '/runtime/logs', 0777);
        mkdir($this->base . '/runtime/logs/job', 0700);
        $this->expectException(RuntimeException::class);
        LogDirectory::withDirectory($this->base . '/runtime/logs/job', static fn(): null => null, false, $this->base . '/runtime');
    }

    public function testWritableAncestorCanBeValidatedWithoutCreatingMissingPrivateRoot(): void
    {
        chmod($this->base, 0777);
        $this->assertSame(9, LogDirectory::withDirectory($this->base, static fn(): int => 9, false, $this->base . '/future/logs'));
        $this->assertDirectoryDoesNotExist($this->base . '/future');
        $this->assertSame(0777, fileperms($this->base) & 0777);
    }

    public function testInvalidPathsAreRefusedBeforeCallback(): void
    {
        foreach (['', 'relative', '/tmp/../tmp', '/tmp/./logs', '/tmp//logs'] as $path) {
            try {
                LogDirectory::withDirectory($path, static fn(): null => null);
                $this->fail('Expected malformed path refusal.');
            } catch (RuntimeException) {
                $this->assertSame($this->originalCwd, getcwd());
            }
        }
    }

    public function testUnrelatedPrivateRootIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        LogDirectory::withDirectory($this->base, static fn(): null => null, false, '/unrelated');
    }
}

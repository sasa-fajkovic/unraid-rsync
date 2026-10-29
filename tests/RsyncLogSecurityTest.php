<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RsyncLogSecurityTest extends TestCase
{
    private ?string $base = null;

    protected function tearDown(): void
    {
        LogDirectory::$afterEnter = null;
        TestSeams::reset();
        if ($this->base !== null) {
            $this->removeTree($this->base);
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
        rmdir($path);
    }

    public static function logSizes(): array
    {
        return ['normal log' => [false], 'oversized log' => [true]];
    }

    #[DataProvider('logSizes')]
    public function testRealRsyncAndCapturedOutputKeepPinnedFileAfterParentSwap(bool $oversized): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc/self/fd') || !is_executable('/usr/bin/rsync')) {
            $this->markTestSkipped('Requires Linux procfs and /usr/bin/rsync.');
        }
        $cwd = getcwd();
        $this->base = sys_get_temp_dir() . '/ur-rsync-log-security-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0700);
        foreach (['source', 'destination', 'share', 'outside/audit'] as $dir) {
            mkdir($this->base . '/' . $dir, 0700, true);
        }
        chmod($this->base . '/share', 0777);
        file_put_contents($this->base . '/source/payload.txt', 'copied contents');
        $root = $this->base . '/share/logs';
        $moved = $this->base . '/share/moved';
        $outside = $this->base . '/outside';
        Logger::$logsDirOverride = $root;
        $path = Logger::openRun('audit');
        $outsideLog = $outside . '/audit/' . basename($path);
        file_put_contents($outsideLog, 'outside sentinel');
        $wrapper = $this->base . '/rsync-wrapper.sh';
        $script = "#!/bin/sh\nset -eu\n"
            . 'mv -- ' . escapeshellarg($root) . ' ' . escapeshellarg($moved) . "\n"
            . 'ln -s -- ' . escapeshellarg($outside) . ' ' . escapeshellarg($root) . "\n"
            . "printf 'captured-marker\\n'\nsleep 0.1\n";
        if ($oversized) {
            $script .= "printf '%s' " . escapeshellarg(str_repeat('x', 8192)) . " >&3\n"
                . "printf 'cap-trigger\\n'\nsleep 0.1\n";
            Logger::$maxRunLogBytesOverride = 256;
        }
        $script .= 'exec /usr/bin/rsync "$@"' . "\n";
        file_put_contents($wrapper, $script);
        chmod($wrapper, 0700);
        $sink = Logger::sink($path);
        $trimmed = false;
        $code = Rsync::run(
            [$wrapper, '-av', '--log-file=' . $path, '--', $this->base . '/source/', $this->base . '/destination/'],
            static function (string $chunk) use ($sink, $path, $oversized, &$trimmed): void {
                $sink($chunk);
                if ($oversized) {
                    $trimmed = Logger::enforceRunLogCap($path) || $trimmed;
                }
            },
            null,
            10
        );
        $this->assertSame(0, $code);
        $this->assertSame('copied contents', file_get_contents($this->base . '/destination/payload.txt'));
        $this->assertSame('outside sentinel', file_get_contents($outsideLog));
        $body = file_get_contents($moved . '/audit/' . basename($path));
        $this->assertStringContainsString('captured-marker', $body);
        if ($oversized) {
            $this->assertTrue($trimmed);
            $this->assertLessThan(8192, strlen($body));
        } else {
            $this->assertStringContainsString('payload.txt', $body);
        }
        $this->assertSame($cwd, getcwd());
        $this->assertSame(0777, fileperms($this->base . '/share') & 0777);
    }
}

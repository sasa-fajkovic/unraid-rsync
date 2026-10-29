<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RunnerLogPersistenceTest extends TestCase
{
    private ?string $base = null;
    private string $jobId = 'j-retained-log';

    protected function setUp(): void
    {
        TestSeams::reset();
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/proc/self/fd')) {
            $this->markTestSkipped('Retained run-log descriptors require Linux procfs.');
        }
        $this->base = sys_get_temp_dir() . '/ur-runner-log-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0700);
        Config::$mntRoot = $this->base;
        Logger::$baseOverride = $this->base . '/runtime';
        RunState::$baseOverride = $this->base . '/runtime';
        Ssh::$runtimeBase = $this->base . '/runtime';
        Runner::$pidProvider = static fn(): int => 12345;
        Notify::$runner = static fn(): int => 0;
        Rsync::$rsyncPathOverride = PHP_BINARY;
        Config::save(Config::defaults());
    }

    protected function tearDown(): void
    {
        if ($this->base !== null) {
            RunState::clear($this->jobId);
            RunState::clearAbort($this->jobId);
        }
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

    public function testOriginalLogSurvivesTransferThroughTeardownAndClosesAfterRun(): void
    {
        $root = $this->base . '/share/logs';
        $moved = $this->base . '/share/moved';
        $outside = $this->base . '/outside';
        mkdir($this->base . '/share', 0777);
        chmod($this->base . '/share', 0777);
        mkdir($outside . '/' . $this->jobId, 0700, true);
        file_put_contents($outside . '/plugin.log', 'outside plugin sentinel');
        $config = Config::defaults();
        $config['global']['logDir'] = $root;
        $job = Config::defaultJob();
        $job['id'] = $this->jobId;
        $job['name'] = 'Retained log';
        $job['transport'] = 'LOCAL';
        $job['direction'] = 'PUSH';
        $job['pairs'] = [['local' => '/mnt/user/source/', 'remote' => '/mnt/disk1/destination/']];
        $job['postHook'] = 'POST';
        $job['notifyMode'] = 'off';
        $config['jobs'] = [$job];
        Config::save($config);
        $runtimePath = $this->base . '/runtime/pass/test-token';
        $originalLog = '';
        $postStatus = '';
        Rsync::$runner = function (array $argv, callable $output) use ($root, $moved, $outside, $runtimePath, &$originalLog): int {
            foreach ($argv as $arg) {
                if (str_starts_with($arg, '--log-file=')) {
                    $originalLog = substr($arg, strlen('--log-file='));
                }
            }
            $this->assertNotSame('', $originalLog);
            file_put_contents($outside . '/' . $this->jobId . '/' . basename($originalLog), 'outside run sentinel');
            rename($root, $moved);
            symlink($outside, $root);
            Logger::setRedaction([$runtimePath]);
            $raw = Logger::openLog($originalLog, 'ab');
            $this->assertIsResource($raw);
            fwrite($raw, 'raw path ' . $runtimePath . "\n");
            fclose($raw);
            $output('unterminated transfer tail');
            return 0;
        };
        Runner::$hookRunner = static function (string $hook, array $env, callable $output) use (&$postStatus): int {
            $postStatus = (string) ($env['UR_JOB_STATUS'] ?? '');
            $output('post-hook output without newline');
            return 0;
        };
        $cwd = getcwd();
        $result = Runner::run($this->jobId);
        $this->assertSame(Rsync::STATE_SUCCESS, $result['state']);
        $this->assertSame(Rsync::STATE_SUCCESS, $postStatus);
        $movedPath = $moved . '/' . $this->jobId . '/' . basename($originalLog);
        $body = file_get_contents($movedPath);
        $this->assertStringContainsString('unterminated transfer tail', $body);
        $this->assertStringContainsString('post-hook output without newline', $body);
        $this->assertStringContainsString('Run finished: state=SUCCESS', $body);
        $this->assertStringNotContainsString($runtimePath, $body);
        $this->assertStringContainsString('raw path [redacted]', $body);
        $this->assertSame('outside run sentinel', file_get_contents($outside . '/' . $this->jobId . '/' . basename($originalLog)));
        $this->assertSame('outside plugin sentinel', file_get_contents($outside . '/plugin.log'));
        $this->assertSame(Rsync::STATE_SUCCESS, Runner::readSummary($this->jobId)['state']);
        $this->assertSame($root, Config::load()['global']['logDir']);
        $this->assertSame($cwd, getcwd());
        $this->assertFalse(Logger::openLog($originalLog));
        $identity = stat($movedPath);
        $remaining = 0;
        foreach (glob('/proc/self/fd/*') ?: [] as $fd) {
            clearstatcache(true, $fd);
            $st = @stat($fd);
            if ($st !== false && $st['dev'] === $identity['dev'] && $st['ino'] === $identity['ino']) {
                $remaining++;
            }
        }
        $this->assertSame(0, $remaining, 'The completed run must release every retained descriptor.');
    }
}

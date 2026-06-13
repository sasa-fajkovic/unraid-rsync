<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for Logger.php: run-log creation, append/event writers (run log + the
 * rolling plugin log), and - the security-critical bit - that tail() returns
 * HTML-ESCAPED text so a log line with HTML can never be rendered as raw markup
 * (the log-XSS guard). Also covers the bounded-tail truncation behaviour.
 */
final class LoggerTest extends TestCase
{
    private string $rtBase;

    protected function setUp(): void
    {
        $this->rtBase = sys_get_temp_dir() . '/ur-logger-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Logger::$baseOverride = $this->rtBase;
    }

    protected function tearDown(): void
    {
        Logger::$baseOverride = null;
        if (is_dir($this->rtBase)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->rtBase, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->rtBase);
        }
    }

    public function testOpenRunCreatesEmptyLogUnderJobDir(): void
    {
        $path = Logger::openRun('j-music', 1750000000);
        $this->assertFileExists($path);
        $this->assertSame('', file_get_contents($path));
        $this->assertStringContainsString('/logs/j-music/run-', $path);
        $this->assertStringEndsWith('.log', $path);
    }

    public function testAppendAddsTrailingNewline(): void
    {
        $path = Logger::openRun('j-x', 1750000000);
        Logger::append($path, 'hello');
        Logger::append($path, "world\n");
        $this->assertSame("hello\nworld\n", file_get_contents($path));
    }

    public function testEventWritesToBothRunAndPluginLogs(): void
    {
        $path = Logger::openRun('j-x', 1750000000);
        Logger::event($path, 'j-x', 'something happened');
        $run = file_get_contents($path);
        $plugin = file_get_contents(Logger::pluginLogPath());
        $this->assertStringContainsString('something happened', $run);
        $this->assertStringContainsString('something happened', $plugin);
        // The plugin log line carries the job id for cross-job readability.
        $this->assertStringContainsString('[j-x]', $plugin);
    }

    public function testTailEscapesHtml(): void
    {
        $path = Logger::openRun('j-x', 1750000000);
        // A malicious filename/log line containing HTML.
        Logger::append($path, '<script>alert(1)</script> & "quoted" path');
        $tail = Logger::tail($path);
        // The raw tags must NOT be present; their escaped forms must be.
        $this->assertStringNotContainsString('<script>', $tail);
        $this->assertStringContainsString('&lt;script&gt;', $tail);
        $this->assertStringContainsString('&amp;', $tail);
        $this->assertStringContainsString('&quot;', $tail);
    }

    public function testTailMissingFileReturnsEmpty(): void
    {
        $this->assertSame('', Logger::tail($this->rtBase . '/nope.log'));
    }

    public function testTailEmptyFileReturnsEmpty(): void
    {
        $path = Logger::openRun('j-x', 1750000000);
        $this->assertSame('', Logger::tail($path));
    }

    public function testTailTruncatesToBoundAndMarksIt(): void
    {
        $path = Logger::openRun('j-x', 1750000000);
        // Write more than the cap; each line is distinct so we can check the END
        // survived and the START was dropped.
        $lines = '';
        for ($i = 0; $i < 5000; $i++) {
            $lines .= "line-$i-padding-padding-padding\n";
        }
        file_put_contents($path, $lines);
        $tail = Logger::tail($path, 4096);
        $this->assertStringContainsString('earlier output truncated', $tail);
        // The last line is present; an early line is not.
        $this->assertStringContainsString('line-4999-', $tail);
        $this->assertStringNotContainsString('line-0-', $tail);
        // Still escaped output, and bounded.
        $this->assertLessThan(4096 + 200, strlen($tail));
    }

    public function testNewRunLogPathIsUtcStamped(): void
    {
        $path = Logger::newRunLogPath('j-x', 0); // epoch -> 19700101T000000Z
        $this->assertStringContainsString('run-19700101T000000Z.log', $path);
    }
}

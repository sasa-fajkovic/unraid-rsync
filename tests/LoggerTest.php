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
        Logger::$maxRunLogBytesOverride = null;
        Logger::clearRedaction();
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

    public function testTailHandlesInvalidUtf8Bytes(): void
    {
        // rsync output / non-UTF-8 filenames can contain invalid byte sequences.
        // tail() must still return a non-empty, escaped string (ENT_SUBSTITUTE),
        // not '' or a warning.
        $path = Logger::openRun('j-x', 1750000000);
        // 0xFF is never valid UTF-8; mix it with a real tag to confirm escaping.
        file_put_contents($path, "before \xFF\xFE <tag> after\n");
        $tail = Logger::tail($path);
        $this->assertNotSame('', $tail, 'invalid UTF-8 must not blank the tail');
        $this->assertStringContainsString('before', $tail);
        $this->assertStringContainsString('after', $tail);
        $this->assertStringContainsString('&lt;tag&gt;', $tail, 'still HTML-escaped');
        $this->assertStringNotContainsString('<tag>', $tail);
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

    // --- F1: secret-path redaction before bytes reach the log ----------------

    public function testRedactionScrubsSecretPathsFromCapturedOutput(): void
    {
        // The realistic leak: an SSH job at `debug` level makes rsync echo the
        // remote-shell command it execs - the `-e "ssh -i <tmpfs-keypath> ...
        // -p N"` line - into its captured stderr, which the runner streams to the
        // run log. With redaction armed (as the Runner does at materialisation),
        // the tmpfs key/passfile/known_hosts PATHS must NOT reach the log.
        $base    = '/tmp/unraid.rsync';
        $token   = 'c-rpi-12345-deadbeef';
        $keyPath = $base . '/keys/' . $token;
        $passDir = $base . '/pass/' . $token;
        $khPath  = $base . '/known_hosts/' . $token;

        Logger::setRedaction([$keyPath, $passDir, $khPath], $base, $token);

        $path = Logger::openRun('j-ssh', 1750000000);
        $sink = Logger::sink($path);
        // A representative debug-level rsync line exposing the -e command.
        $sink('opening connection using: ssh -i ' . $keyPath
            . ' -o UserKnownHostsFile=' . $khPath . ' -p 22 sasa@rpi rsync --server\n');
        // And an event line goes through the same redacting append() path.
        Logger::event($path, 'j-ssh', 'transport key at ' . $keyPath);

        $log = file_get_contents($path);
        $this->assertStringNotContainsString($keyPath, $log, 'key path must be redacted');
        $this->assertStringNotContainsString($khPath, $log, 'known_hosts path must be redacted');
        $this->assertStringContainsString(Logger::REDACT_PLACEHOLDER, $log);
        // The non-secret parts of the line survive (only the paths are scrubbed).
        $this->assertStringContainsString('opening connection using: ssh -i', $log);
        $this->assertStringContainsString('sasa@rpi', $log);

        // plugin.log (also browser-visible) must be scrubbed too.
        $plugin = file_get_contents(Logger::pluginLogPath());
        $this->assertStringNotContainsString($keyPath, $plugin);
        $this->assertStringContainsString(Logger::REDACT_PLACEHOLDER, $plugin);
    }

    public function testRedactionDefensivelyScrubsPathsUnderPerRunSecretDirs(): void
    {
        // Even a path we did not pass explicitly - e.g. a tempnam scratch file
        // under this run's per-token secret dir - is scrubbed via $redactDirs.
        $base  = '/tmp/unraid.rsync';
        $token = 'c-x-999-abc123';
        Logger::setRedaction([], $base, $token);

        $path  = Logger::openRun('j-ssh2', 1750000000);
        $scratch = $base . '/keys/' . $token . '/.ur-secret.AB12';
        Logger::sink($path)('wrote ' . $scratch . ' then renamed\n');

        $log = file_get_contents($path);
        $this->assertStringNotContainsString($scratch, $log);
        $this->assertStringNotContainsString($base . '/keys/' . $token, $log);
        $this->assertStringContainsString(Logger::REDACT_PLACEHOLDER, $log);
    }

    public function testRedactionNoOpWhenNothingArmed(): void
    {
        Logger::clearRedaction();
        $this->assertSame('plain line', Logger::redact('plain line'));
    }

    // --- F3: per-run-log size cap --------------------------------------------

    public function testRunLogIsCappedAndMarkerWrittenOnce(): void
    {
        // Drive the cap small via the override seam so the test is fast and
        // deterministic (the production default is 16 MiB; see
        // UR_MAX_RUN_LOG_BYTES). Write well past it and assert the file stays
        // bounded with the marker present exactly once.
        $cap = 4096;
        Logger::$maxRunLogBytesOverride = $cap;

        $path = Logger::openRun('j-big', 1750000000);
        $sink = Logger::sink($path);

        // Write well past the cap in chunks (a chatty hook / huge verbose run).
        for ($i = 0; $i < 50; $i++) {
            $sink(str_repeat('A', 512) . "\n"); // 50 * 513 bytes >> 4 KiB cap
        }
        // Further writes after the cap must be dropped (not appended).
        $sink("this must not appear after the cap\n");
        $sink("nor this\n");

        $size   = filesize($path);
        $marker = Logger::TRUNCATE_MARKER_PREFIX;

        // File stays at or below the cap plus the single marker line.
        $this->assertLessThanOrEqual($cap + 64, $size, 'run log must stay bounded by the cap (+ marker)');

        $contents = file_get_contents($path);
        $this->assertStringContainsString($marker, $contents, 'truncation marker present');
        // Marker written EXACTLY once.
        $this->assertSame(1, substr_count($contents, $marker), 'marker written only once');
        // Content fed AFTER the cap was hit is absent.
        $this->assertStringNotContainsString('this must not appear after the cap', $contents);
        $this->assertStringNotContainsString('nor this', $contents);
    }

    public function testPluginLogIsNotSizeCapped(): void
    {
        // The cap applies only to per-run logs; plugin.log is the rolling
        // cross-job log (bounded on READ by tail()), so appendCapped must pass it
        // through. append() creates the dir itself. A 1 MiB line must land in
        // full (the run-log cap must NOT bound plugin.log).
        $big = str_repeat('p', 1024 * 1024); // 1 MiB
        Logger::append(Logger::pluginLogPath(), $big);
        $this->assertGreaterThanOrEqual(1024 * 1024, filesize(Logger::pluginLogPath()));
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for Logger.php: run-log creation, append/event writers (run log + the
 * rolling plugin log), and - the security-critical bit - that tail() returns
 * HTML-ESCAPED text so a log line with HTML can never be rendered as raw markup
 * (the log-XSS guard). Also covers the bounded-tail truncation behaviour.
 */
final class LoggerTest extends TestCase
{
    private string $rtBase;
    private ?string $extBase = null;

    protected function setUp(): void
    {
        $this->rtBase = sys_get_temp_dir() . '/ur-logger-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Logger::$baseOverride = $this->rtBase;
    }

    protected function tearDown(): void
    {
        Logger::$baseOverride = null;
        Logger::$logsDirOverride = null;
        Logger::$maxRunLogBytesOverride = null;
        Logger::clearRedaction();
        foreach (array_filter([$this->rtBase, $this->extBase]) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
    }

    /**
     * With a persistent logs dir configured (Logger::$logsDirOverride), logs are
     * written UNDER that dir - including a not-yet-existing nested path, which is
     * created recursively - and NOT under the RAM base. This is the opt-in
     * "survive a reboot" mode (Config::logDir()); the override decouples Logger
     * from Config so the Runner/handler push the validated path in.
     */
    public function testExternalLogsDirOverrideWritesOutsideRamBase(): void
    {
        $this->extBase = sys_get_temp_dir() . '/ur-logger-ext-' . getmypid() . '-' . bin2hex(random_bytes(4));
        // A nested path that does NOT exist yet, to prove recursive creation.
        $extLogs = $this->extBase . '/appdata/unraid.rsync/logs';
        Logger::$logsDirOverride = $extLogs;

        $this->assertSame($extLogs, Logger::logsDir());

        $path = Logger::openRun('j-photos', 1750000000);
        $this->assertFileExists($path);
        $this->assertStringStartsWith($extLogs . '/j-photos/run-', $path);

        Logger::event($path, 'j-photos', 'hello from external');
        // Both the run log and the rolling plugin.log live under the external dir.
        $this->assertStringContainsString('hello from external', file_get_contents($path));
        $this->assertFileExists($extLogs . '/plugin.log');
        $this->assertStringContainsString('[j-photos]', file_get_contents($extLogs . '/plugin.log'));

        // listRuns / latestRunLogPath resolve against the SAME external dir.
        $runs = Logger::listRuns('j-photos', 10, static fn($j) => null);
        $this->assertCount(1, $runs);
        $this->assertSame(Logger::runIdFromPath($path), $runs[0]['id']);
        // latestRunLogPath realpath-confines, so compare canonicalised paths
        // (on macOS /var -> /private/var would otherwise differ from $path).
        $this->assertSame(realpath($path), realpath(Logger::latestRunLogPath('j-photos')));

        // Nothing leaked into the RAM base.
        $this->assertDirectoryDoesNotExist($this->rtBase . '/logs');
    }

    public function testOpenRunCreatesEmptyLogUnderJobDir(): void
    {
        $path = Logger::openRun('j-music', 1750000000);
        $this->assertFileExists($path);
        $this->assertSame('', file_get_contents($path));
        $this->assertStringContainsString('/logs/j-music/run-', $path);
        $this->assertStringEndsWith('.log', $path);
    }

    /**
     * SEC-01: a pure-dots job id ("." / ".." / "...") survives the char-class
     * strip but is a traversal segment (logsDir()/".." == base()). safeId must
     * collapse it to the literal "unknown" so the run log can never land outside
     * the per-job dir, matching ur_safe_job_id.
     */
    #[DataProvider('pureDotsIdProvider')]
    public function testJobLogDirCollapsesPureDotsId(string $id): void
    {
        $dir = Logger::jobLogDir($id);
        $this->assertSame(Logger::logsDir() . '/unknown', $dir);
        $this->assertStringNotContainsString('/..', $dir);
    }

    /** @return array<string,array{0:string}> */
    public static function pureDotsIdProvider(): array
    {
        return ['dot' => ['.'], 'dotdot' => ['..'], 'tripledot' => ['...']];
    }

    /**
     * SEC-01: the runtime base lives under world-writable /tmp. ensureDir must
     * REFUSE a symlinked base rather than letting mkdir -p follow it and redirect
     * root-written logs out of the sandbox.
     */
    public function testOpenRunRefusesSymlinkedBase(): void
    {
        $target = sys_get_temp_dir() . '/ur-logger-target-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($target, 0700, true);
        $link = sys_get_temp_dir() . '/ur-logger-link-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @symlink($target, $link);
        Logger::$baseOverride = $link;
        try {
            $this->expectException(RuntimeException::class);
            Logger::openRun('j-x', 1750000000);
        } finally {
            @unlink($link);
            @rmdir($target);
            Logger::$baseOverride = $this->rtBase;
        }
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
            . ' -o UserKnownHostsFile=' . $khPath . " -p 22 sasa@rpi rsync --server\n");
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
        Logger::sink($path)('wrote ' . $scratch . " then renamed\n");

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

    public function testEnforceRunLogCapTrimsDirectWritesFromRsyncLogFile(): void
    {
        // rsync writes the run log directly via --log-file, bypassing Logger's
        // sink cap. enforceRunLogCap() bounds the file regardless of writer: a
        // file grown PAST the cap by a direct write (simulated here) is trimmed
        // to the cap (head kept) with the one-time marker appended.
        $cap = 4096;
        Logger::$maxRunLogBytesOverride = $cap;

        $path = Logger::openRun('j-direct', 1750000000);
        // Simulate rsync's direct --log-file writes overshooting the cap.
        file_put_contents($path, str_repeat('R', $cap * 3));
        $this->assertGreaterThan($cap, filesize($path));

        $trimmed = Logger::enforceRunLogCap($path);
        $this->assertTrue($trimmed);

        $size = filesize($path);
        $this->assertLessThanOrEqual($cap + 64, $size, 'direct-write log must be trimmed to the cap (+ marker)');
        $contents = file_get_contents($path);
        $this->assertStringContainsString(Logger::TRUNCATE_MARKER_PREFIX, $contents);
        $this->assertSame(1, substr_count($contents, Logger::TRUNCATE_MARKER_PREFIX), 'marker written only once');

        // A second enforcement on an already-trimmed file is a no-op (under cap).
        $this->assertFalse(Logger::enforceRunLogCap($path));

        // plugin.log is never the target of the cap.
        $this->assertFalse(Logger::enforceRunLogCap(Logger::pluginLogPath()));
    }

    /**
     * Issue #135: rsync writes its own lines into the run log in SYSTEM LOCAL
     * time, so the plugin's own stamps must be local too - a UTC "...Z" stamp
     * put two timezones in one file. The stamp keeps an explicit UTC offset so
     * it stays unambiguous ISO-8601.
     */
    public function testEventStampIsLocalWithOffsetNotUtcZ(): void
    {
        $path = Logger::openRun('j-tz', 1750000000);
        Logger::event($path, 'j-tz', 'timezone check');

        foreach ([file_get_contents($path), file_get_contents(Logger::pluginLogPath())] as $body) {
            $this->assertMatchesRegularExpression(
                '/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\] /m',
                $body
            );
            // The old UTC "...Z" form must not come back.
            $this->assertDoesNotMatchRegularExpression(
                '/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\] /m',
                $body
            );
        }
    }

    /**
     * The stamp must track the process timezone (which Config.php pins to the
     * system zone), not UTC. Same instant, two zones, two different hours.
     */
    public function testEventStampFollowsTheProcessTimezone(): void
    {
        $tz = date_default_timezone_get();
        try {
            date_default_timezone_set('Australia/Sydney');
            $path = Logger::openRun('j-tz-syd', 1750000000);
            Logger::event($path, 'j-tz-syd', 'sydney');
            $syd = file_get_contents($path);
            $this->assertStringContainsString('+10:00', $syd);

            date_default_timezone_set('UTC');
            Logger::event($path, 'j-tz-syd', 'utc');
            $both = file_get_contents($path);
            $this->assertStringContainsString('+00:00', $both);
        } finally {
            date_default_timezone_set($tz);
        }
    }

    /**
     * The run-log FILENAME stays UTC on purpose: it is an identifier that has to
     * sort lexically and never repeat across a DST fold. Guards against a future
     * refactor sweeping it into local time along with the in-file stamps and
     * quietly breaking RUN_FILE_REGEX ordering.
     */
    public function testRunLogFilenameStaysUtcRegardlessOfTimezone(): void
    {
        $tz = date_default_timezone_get();
        try {
            date_default_timezone_set('Australia/Sydney');
            $this->assertStringEndsWith(
                '/run-' . gmdate('Ymd\THis\Z', 1750000000) . '.log',
                Logger::newRunLogPath('j-tz-name', 1750000000)
            );
        } finally {
            date_default_timezone_set($tz);
        }
    }

    // --- redactRunLog: rsync's own --log-file writes bypass sink() -----------

    public function testRedactRunLogScrubsWhatRsyncWroteThroughItsOwnLogFileFd(): void
    {
        // rsync opens --log-file itself (Rsync.php) and names the daemon password
        // file VERBATIM in two of its own errors (authenticate.c:188 / :215).
        // Those lines never pass through Logger::sink, so appending them directly
        // is exactly the scenario: only a pass over the FILE can scrub them.
        $passFile = $this->rtBase . '/pass/c-nas-1234-deadbeefcafe';
        $path     = Logger::openRun('j-daemon', 1750000000);
        Logger::setRedaction([$passFile], $this->rtBase, 'c-nas-1234-deadbeefcafe');

        file_put_contents(
            $path,
            "rsync: ERROR: failed to read a password from $passFile\n",
            FILE_APPEND
        );

        $this->assertTrue(Logger::redactRunLog($path));
        $body = (string) file_get_contents($path);
        $this->assertStringNotContainsString($passFile, $body);
        $this->assertStringContainsString('failed to read a password from [redacted]', $body);
    }

    public function testRedactRunLogIsANoOpWhenNothingIsArmedOrThePathIsNotARunLog(): void
    {
        $passFile = $this->rtBase . '/pass/tok';
        $path     = Logger::openRun('j-daemon', 1750000000);
        file_put_contents($path, "saw $passFile\n", FILE_APPEND);

        // Nothing armed.
        $this->assertFalse(Logger::redactRunLog($path));
        $this->assertStringContainsString($passFile, (string) file_get_contents($path));

        // Armed, but plugin.log is not a run log - and redaction there would
        // rewrite a rolling cross-job file under another run's lock.
        Logger::setRedaction([$passFile], $this->rtBase, 'tok');
        Logger::append(Logger::pluginLogPath(), "saw $passFile");
        $this->assertFalse(Logger::redactRunLog(Logger::pluginLogPath()));

        // Armed and a real run log -> rewritten.
        $this->assertTrue(Logger::redactRunLog($path));
    }

    // --- tail(): the ONLY redaction a reader process gets --------------------

    public function testTailScrubsPerRunSecretPathsWithNothingArmed(): void
    {
        // The live Status/Overview poller tails this file from php-fpm MID-RUN,
        // where setRedaction has never been called and cannot be (different
        // process, no token). Before this, the passfile path rsync writes through
        // its own --log-file fd was browser-visible for the length of a pair, and
        // stayed visible for good if the runner was SIGKILLed mid-pair.
        $path = Logger::openRun('j-daemon', 1750000000);
        file_put_contents($path, implode("\n", [
            'rsync: ERROR: failed to read a password from ' . $this->rtBase . '/pass/c-nas-9-abc',
            'opening connection using: ssh -i ' . $this->rtBase . '/keys/c-nas-9-abc/id -o UserKnownHostsFile='
                . $this->rtBase . '/known_hosts/c-nas-9-abc nas.local rsync --server',
            'sent 100 bytes',
        ]) . "\n", FILE_APPEND);

        Logger::clearRedaction();
        $out = Logger::tail($path);

        $this->assertStringNotContainsString($this->rtBase . '/pass/', $out);
        $this->assertStringNotContainsString($this->rtBase . '/keys/', $out);
        $this->assertStringNotContainsString($this->rtBase . '/known_hosts/', $out);
        $this->assertStringContainsString('failed to read a password from [redacted]', $out);
        // Ordinary log content is untouched.
        $this->assertStringContainsString('sent 100 bytes', $out);
        $this->assertStringContainsString('nas.local rsync --server', $out);
    }

    public function testTailStillEscapesAfterScrubbing(): void
    {
        // The scrub runs BEFORE htmlspecialchars; it must not open a hole in the
        // log-XSS guard.
        $path = Logger::openRun('j-xss', 1750000000);
        file_put_contents($path, '<script>alert(1)</script> ' . $this->rtBase . "/pass/tok\n", FILE_APPEND);

        $out = Logger::tail($path);
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringContainsString('[redacted]', $out);
    }

    // --- progress-redraw collapsing in sink() --------------------------------

    /**
     * Build the shape rsync's --info=progress2 actually writes. Verified byte
     * for byte against rsync 3.5.0: the carriage return LEADS each redraw
     * (`\r<p1>\r<p2>...\r<pN>\n`, exactly one LF in the whole stream, at the
     * very end), and each line is padded with trailing spaces to erase the
     * previous, longer one.
     */
    private function progressRedraw(int $pct): string
    {
        return "\r" . sprintf('%15s %3d%%   2.93MB/s    0:00:12  ', number_format($pct * 419430), $pct);
    }

    public function testSinkCollapsesProgressRedrawsToOneLinePerPercentageStep(): void
    {
        $path = Logger::openRun('j-prog', 1750000000);
        $sink = Logger::sink($path);

        // Every 1% from 0 to 100 - what a real run emits many times a second.
        for ($pct = 0; $pct <= 100; $pct++) {
            $sink($this->progressRedraw($pct));
        }
        Logger::flushSink($path);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path)), 'strlen'));
        // 0% then every +5% step: exactly 21 lines out of 101 redraws in.
        $this->assertCount(21, $lines, 'progress must be throttled, not appended verbatim');
        $this->assertSame(
            range(0, 100, Logger::PROGRESS_MIN_PCT),
            array_map(static fn(string $l): int => (int) (preg_match('/(\d+)%/', $l, $m) ? $m[1] : -1), $lines)
        );
        $this->assertStringContainsString('100%', end($lines), 'the log must end on the final percentage');
        // No bare CR survives into the file, and no trailing redraw padding.
        $this->assertStringNotContainsString("\r", (string) file_get_contents($path));
        foreach ($lines as $line) {
            $this->assertSame(rtrim($line), $line, 'progress padding must be trimmed');
        }
    }

    public function testSinkDropsRedrawsInsideTheThrottleWindow(): void
    {
        $path = Logger::openRun('j-prog2', 1750000000);
        $sink = Logger::sink($path);

        // A redraw is decided one chunk late: its trailing \r could still turn
        // out to be the first half of a \r\n, so it is held until the next byte.
        $sink($this->progressRedraw(1));
        $this->assertSame('', (string) file_get_contents($path), 'a trailing \r waits to be disambiguated');

        // The 1% redraw lands as soon as the next one proves the \r was bare.
        $sink($this->progressRedraw(2));
        $before = (string) file_get_contents($path);
        $this->assertStringContainsString('1%', $before);

        // 2% onward: under +5% and inside the 30s window, nothing more lands.
        foreach ([3, 4, 5] as $pct) {
            $sink($this->progressRedraw($pct));
        }
        $this->assertSame($before, (string) file_get_contents($path), 'sub-step redraws must be dropped');

        // Crossing the step writes exactly one more line (once disambiguated).
        $sink($this->progressRedraw(6));
        $sink($this->progressRedraw(7));
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path)), 'strlen'));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('6%', $lines[1]);
    }

    /**
     * REGRESSION (PR #155 review, found independently by Copilot and a review
     * agent). ProcIO's 8 KiB freads can cut anywhere, including between the \r
     * and the \n of a CRLF line ending. Deciding the \r's meaning on arrival
     * treated it as a bare progress redraw - so the line was handed to the
     * throttle and DROPPED, and its orphaned \n then wrote a blank line.
     */
    public function testSinkHandlesCrlfSplitAcrossTwoChunks(): void
    {
        $path = Logger::openRun('j-crlf', 1750000000);
        $sink = Logger::sink($path);

        $sink("docs/a.txt\r");
        $sink("\ndocs/b.txt\n");
        Logger::flushSink($path);

        $this->assertSame("docs/a.txt\ndocs/b.txt\n", (string) file_get_contents($path));
    }

    public function testSinkDoesNotLoseACrlfLineSplitInsideTheThrottleWindow(): void
    {
        // The nastier shape of the same bug: with the throttle window already
        // open, the misread \r sent a REAL line into throttleProgress(), which
        // dropped it outright - a lost error or itemize line, not a lost redraw.
        $path = Logger::openRun('j-crlf2', 1750000000);
        $sink = Logger::sink($path);

        $sink($this->progressRedraw(10));
        $sink($this->progressRedraw(11));           // opens the throttle window
        $sink("rsync: some error\r");
        $sink("\nnext/file.txt\n");
        Logger::flushSink($path);

        $log = (string) file_get_contents($path);
        $this->assertStringContainsString('rsync: some error', $log, 'a CRLF line must never be throttled away');
        $this->assertStringContainsString('next/file.txt', $log);
        $this->assertStringNotContainsString("\n\n", $log, 'no orphaned blank line');
    }

    public function testSinkKeepsAHeldRedrawAheadOfALaterUnterminatedBlob(): void
    {
        // The SINK_BUF_MAX escape hatch must not write newer bytes BEFORE the
        // older redraw still held back by the throttle.
        $path = Logger::openRun('j-order', 1750000000);
        $sink = Logger::sink($path);

        $sink($this->progressRedraw(10));
        $sink($this->progressRedraw(11));   // 11% is held (under the 5% step)
        $sink(str_repeat('h', Logger::SINK_BUF_MAX + 1));

        $log = (string) file_get_contents($path);
        $this->assertStringContainsString('11%', $log, 'the held redraw must be written, not left behind');
        $this->assertLessThan(
            (int) strpos($log, 'hhhh'),
            (int) strpos($log, '11%'),
            'the held redraw was produced first and must be written first'
        );
    }

    public function testSinkPassesRealLinesThroughVerbatimAndSplitAcrossChunks(): void
    {
        $path = Logger::openRun('j-lines', 1750000000);
        $sink = Logger::sink($path);

        // A per-file listing line, delivered split across two reads the way
        // ProcIO's 8 KiB freads actually cut it.
        $sink("docs/a.txt\ndocs/b");
        $sink(".txt\n");
        // \r\n is a LINE ending, not a progress redraw.
        $sink("sub/c.txt\r\n");
        Logger::flushSink($path);

        $this->assertSame(
            "docs/a.txt\ndocs/b.txt\nsub/c.txt\n",
            (string) file_get_contents($path)
        );
    }

    public function testFlushSinkLandsAnUnterminatedTailAndIsIdempotent(): void
    {
        $path = Logger::openRun('j-flush', 1750000000);
        $sink = Logger::sink($path);

        // rsync's final line, or a hook's last echo, with no trailing newline.
        $sink('sent 41,953,721 bytes  received 146 bytes');
        $this->assertSame('', (string) file_get_contents($path), 'a partial line waits for its newline');

        Logger::flushSink($path);
        $this->assertSame("sent 41,953,721 bytes  received 146 bytes\n", (string) file_get_contents($path));

        Logger::flushSink($path);
        $this->assertSame(
            "sent 41,953,721 bytes  received 146 bytes\n",
            (string) file_get_contents($path),
            'flushSink must be idempotent'
        );
    }

    public function testSinkNeverBuffersAnUnterminatedBlobWithoutBound(): void
    {
        $path = Logger::openRun('j-blob', 1750000000);
        $sink = Logger::sink($path);

        // A child emitting neither \n nor \r must not grow the buffer forever.
        $sink(str_repeat('x', Logger::SINK_BUF_MAX + 10));
        $this->assertGreaterThan(Logger::SINK_BUF_MAX, filesize($path));
    }

    public function testSinkRedactsSecretPathsArrivingInsideAProgressRedraw(): void
    {
        $base    = '/tmp/unraid.rsync';
        $token   = 'c-prog-1-abcdef';
        $keyPath = $base . '/keys/' . $token;
        Logger::setRedaction([$keyPath], $base, $token);

        $path = Logger::openRun('j-prog3', 1750000000);
        $sink = Logger::sink($path);
        // Buffering must not let a secret slip past redact().
        $sink('rsync: could not read ' . $keyPath);
        Logger::flushSink($path);

        $log = (string) file_get_contents($path);
        $this->assertStringNotContainsString($keyPath, $log);
        $this->assertStringContainsString(Logger::REDACT_PLACEHOLDER, $log);
    }

    /**
     * The completion line is the one fragment the throttle must never eat: at
     * 99%-then-100% the step condition (|100-99| < 5) drops it, and a real line
     * arriving next (rsync's summary, or an error) supersedes whatever is held
     * back. So 100% is due once, unconditionally.
     *
     * rsync does print its final redraw two or three times with slightly
     * different rate/ETA figures, so the log can end on two 100% lines; they
     * are genuinely different redraws, not a repeat this code produced.
     */
    public function testSinkAlwaysLandsTheCompletionLine(): void
    {
        $path = Logger::openRun('j-done', 1750000000);
        $sink = Logger::sink($path);

        $sink($this->progressRedraw(0));
        $sink($this->progressRedraw(99));
        $sink($this->progressRedraw(100));
        $sink("sent 41,953,721 bytes  received 146 bytes\n");
        Logger::flushSink($path);

        $log = (string) file_get_contents($path);
        $this->assertStringContainsString('100%', $log, '100% must never be throttled away');
        $this->assertStringContainsString('sent 41,953,721 bytes', $log);
        // 0%, 99%, 100% - and no more than that from four writes.
        $lines = array_values(array_filter(explode("\n", $log), 'strlen'));
        $this->assertLessThanOrEqual(4, count($lines));
    }

    /**
     * progress2's percentage is NOT monotonic: with incremental recursion the
     * denominator grows as the file list is discovered, so the figure drops and
     * can touch a spurious 100% early. Against a high-water mark that killed the
     * 5% rule for the rest of the run - the promise silently degraded to "one
     * line per 30s" on exactly the big-tree jobs this feature is for.
     */
    public function testSinkKeepsSteppingAfterThePercentageGoesBackwards(): void
    {
        $path = Logger::openRun('j-nonmono', 1750000000);
        $sink = Logger::sink($path);

        $sink($this->progressRedraw(100));           // spurious early 100%
        foreach (range(40, 79) as $pct) {            // then the real climb
            $sink($this->progressRedraw($pct));
        }
        Logger::flushSink($path);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path)), 'strlen'));
        // 100, then 40/45/.../75, then the flushed 79: nowhere near one line.
        $this->assertGreaterThanOrEqual(8, count($lines), 'the 5% rule must survive a backwards jump');
    }

    /**
     * The property that makes the state machine trustworthy: WHERE the reads cut
     * must not change what lands in the log. ProcIO's fread size is not a
     * contract, and a 1-byte replay cuts every single \r\n pair, so if any
     * boundary case is mishandled these runs disagree.
     */
    public function testSinkOutputIsIndependentOfChunkBoundaries(): void
    {
        // Bare-\r redraws, a CRLF line, an LF line, and an unterminated tail.
        $stream = $this->progressRedraw(0)
            . $this->progressRedraw(7)
            . "docs/a.txt\r\n"
            . $this->progressRedraw(20)
            . "docs/b.txt\n"
            . $this->progressRedraw(100)
            . 'sent 123 bytes  received 45 bytes';

        $outputs = [];
        foreach ([1, 2, 3, 7, 64, 8192] as $size) {
            $path = Logger::openRun('j-cs' . $size, 1750000000);
            $sink = Logger::sink($path);
            foreach (str_split($stream, $size) as $chunk) {
                $sink($chunk);
            }
            Logger::flushSink($path);
            $outputs[$size] = (string) file_get_contents($path);
        }

        $this->assertCount(1, array_unique($outputs), 'chunk size must not change the log');
        $first = reset($outputs);
        $this->assertStringNotContainsString("\r", $first);
        $this->assertStringNotContainsString("\n\n", $first);
        $this->assertStringContainsString("docs/a.txt\n", $first);
        $this->assertStringContainsString("docs/b.txt\n", $first);
        $this->assertStringContainsString('sent 123 bytes', $first);
    }

    /**
     * LIVE REGRESSION. rsync repeats its final redraw, and the last copy is the
     * one carrying the stream's only \n - so the throttle wrote 100% and then
     * the flush wrote the identical line again, ending EVERY run's log on a
     * duplicate. Worse, rsync's own --log-file fd writes the end-of-run summary
     * in between, so the duplicate landed AFTER the summary. Observed on a real
     * 268 MB Summary run.
     */
    public function testFlushDoesNotRepeatTheProgressLineTheThrottleAlreadyWrote(): void
    {
        $path = Logger::openRun('j-dup', 1750000000);
        $sink = Logger::sink($path);

        $sink($this->progressRedraw(0));
        $sink($this->progressRedraw(100));   // written by the 100%-once rule
        $sink($this->progressRedraw(100));   // byte-identical repeat, held back
        Logger::flushSink($path);

        $log = (string) file_get_contents($path);
        $this->assertSame(1, substr_count($log, '100%'), 'the identical repeat must not be flushed again');
    }

    /**
     * LIVE REGRESSION, second attempt. The first fix for the duplicated final
     * progress line only covered flushSink(), because the test fed the repeat
     * with NO trailing newline. The real stream ends
     * `\r<p>\r<p>\r<p>\n` - rsync repeats the final redraw and the LAST copy
     * carries the stream's only \n - so the duplicate arrives on the REAL-LINE
     * path, not the flush path, and shipped 2026.09.10a still logged two
     * identical 100% lines with rsync's own summary wedged between them.
     * Verified against a live 268 MB Summary run.
     */
    public function testTheNewlineTerminatedRepeatOfTheFinalRedrawIsSuppressed(): void
    {
        $path = Logger::openRun('j-tail-dup', 1750000000);
        $sink = Logger::sink($path);

        $sink($this->progressRedraw(90));
        $sink($this->progressRedraw(95));
        $sink($this->progressRedraw(100));
        // rsync's last copy of the same redraw, newline-terminated this time.
        $sink($this->progressRedraw(100) . "\n");
        Logger::flushSink($path);

        $log = (string) file_get_contents($path);
        $this->assertSame(1, substr_count($log, '100%'), 'the final redraw must be logged once');
        $this->assertStringContainsString('90%', $log);
        $this->assertStringContainsString('95%', $log);
    }

    public function testANewlineTerminatedRedrawThatDiffersStillLands(): void
    {
        // Live paperless run: the two trailing redraws differed in to-chk, and an
        // unthrottled copy differed in rate (7.80 then 7.79 MB/s). Byte-exact
        // matching only, so those still reach the log.
        $path = Logger::openRun('j-tail-diff', 1750000000);
        $sink = Logger::sink($path);

        $sink("\r              0   0%    0.00kB/s    0:00:00 (xfr#0, to-chk=1472/1476)");
        $sink("\r              0   0%    0.00kB/s    0:00:00 (xfr#0, to-chk=0/1476)\n");
        Logger::flushSink($path);

        $log = (string) file_get_contents($path);
        $this->assertStringContainsString('to-chk=1472/1476', $log);
        $this->assertStringContainsString('to-chk=0/1476', $log);
    }

    /**
     * The suppression must not reach past the line immediately after the redraw:
     * `last` is cleared on every real line, so an identical line arriving later
     * in the run still lands.
     */
    public function testSuppressionOnlyAppliesToTheImmediatelyFollowingLine(): void
    {
        $path = Logger::openRun('j-tail-scope', 1750000000);
        $sink = Logger::sink($path);

        $sink($this->progressRedraw(100));
        $sink($this->progressRedraw(100) . "\n");   // suppressed
        $sink("some other line\n");
        $sink(rtrim($this->progressRedraw(100)) . "\n");   // same text, but later
        Logger::flushSink($path);

        $this->assertSame(2, substr_count((string) file_get_contents($path), '100%'));
    }

    public function testFlushStillLandsARepeatThatCarriesNewInformation(): void
    {
        // rsync's repeated final redraws usually differ in rate or ETA (live:
        // 7.80MB/s then 7.79MB/s). That is new information, not an artefact of
        // buffering, so the dedupe must be byte-exact and nothing broader.
        $path = Logger::openRun('j-dup2', 1750000000);
        $sink = Logger::sink($path);

        $sink("\r     41,943,046 100%    7.80MB/s    0:00:05");
        $sink("\r     41,943,046 100%    7.79MB/s    0:00:05");
        Logger::flushSink($path);

        $log = (string) file_get_contents($path);
        $this->assertStringContainsString('7.80MB/s', $log);
        $this->assertStringContainsString('7.79MB/s', $log);
    }

    public function testSinkThrottleStateIsPerSinkNotPerProcess(): void
    {
        // The Runner builds a fresh sink per rsync pair; pair #2 must log its
        // own first progress line rather than inheriting pair #1's percentage.
        $path = Logger::openRun('j-pairs', 1750000000);

        $first = Logger::sink($path);
        $first($this->progressRedraw(90));
        Logger::flushSink($path);

        $second = Logger::sink($path);
        $second($this->progressRedraw(2));
        Logger::flushSink($path);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path)), 'strlen'));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('2%', $lines[1], "a new pair's first redraw must land");
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

<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A RunState test double that stubs the live-system seams (pidAlive / pidCmdline)
 * so the PID-reuse-safe isRunning() logic is exercised without a real process.
 * The two seam results are injected per test.
 */
final class FakeRunState extends RunState
{
    public static $alive = false;
    public static $cmdline = '';

    protected static function pidAlive(int $pid): bool
    {
        return self::$alive;
    }

    protected static function pidCmdline(int $pid): string
    {
        return self::$cmdline;
    }
}

/**
 * Tests for RunState.php: state read/write round-trip, the abort flag, and the
 * PID-reuse-safe isRunning() (alive + cmdline gating, stale-state clearing) -
 * all against a temp runtime base, with the posix/proc seams stubbed.
 */
final class RunStateTest extends TestCase
{
    private string $rtBase;

    protected function setUp(): void
    {
        $this->rtBase = sys_get_temp_dir() . '/ur-runstate-' . getmypid() . '-' . bin2hex(random_bytes(4));
        RunState::$baseOverride = $this->rtBase;
        FakeRunState::$baseOverride = $this->rtBase;
        FakeRunState::$alive = false;
        FakeRunState::$cmdline = '';
    }

    protected function tearDown(): void
    {
        RunState::$baseOverride = null;
        FakeRunState::$baseOverride = null;
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

    public function testWriteReadRoundTrip(): void
    {
        RunState::write('j-music', [
            'pid'        => 4242,
            'running'    => true,
            'dryRun'     => true,
            'startedAt'  => '2026-06-13T03:00:00Z',
            'currentLog' => '/tmp/x/run.log',
        ]);
        $s = RunState::read('j-music');
        $this->assertNotNull($s);
        $this->assertSame(4242, $s['pid']);
        $this->assertTrue($s['running']);
        $this->assertTrue($s['dryRun']);
        $this->assertSame('2026-06-13T03:00:00Z', $s['startedAt']);
        $this->assertSame('/tmp/x/run.log', $s['currentLog']);
        // trigger not supplied -> defaults to 'manual'.
        $this->assertSame('manual', $s['trigger']);
    }

    public function testTriggerRoundTripsAndClampsToClosedSet(): void
    {
        RunState::write('j-sch', ['running' => true, 'trigger' => 'schedule']);
        $this->assertSame('schedule', RunState::read('j-sch')['trigger']);
        // An unrecognised value is clamped to 'manual' on write.
        RunState::write('j-bog', ['running' => true, 'trigger' => 'bogus']);
        $this->assertSame('manual', RunState::read('j-bog')['trigger']);
    }

    public function testReadMissingIsNull(): void
    {
        $this->assertNull(RunState::read('j-nope'));
    }

    /**
     * SEC-01: a pure-dots id collapses to "unknown" across every state path so a
     * crafted id can never address a file outside the state dir.
     */
    #[DataProvider('pureDotsIdProvider')]
    public function testStatePathsCollapsePureDotsId(string $id): void
    {
        foreach ([RunState::statePath($id), RunState::abortPath($id), RunState::lockPath($id)] as $p) {
            $this->assertStringContainsString('/unknown.', $p);
            $this->assertStringNotContainsString('/..', $p);
        }
    }

    /** @return array<string,array{0:string}> */
    public static function pureDotsIdProvider(): array
    {
        return ['dot' => ['.'], 'dotdot' => ['..'], 'tripledot' => ['...']];
    }

    /**
     * SEC-01: ensureDir (triggered by write()) must REFUSE a symlinked runtime
     * base rather than letting mkdir -p follow it out of the /tmp sandbox.
     */
    public function testWriteRefusesSymlinkedBase(): void
    {
        $target = sys_get_temp_dir() . '/ur-runstate-target-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($target, 0700, true);
        $link = sys_get_temp_dir() . '/ur-runstate-link-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @symlink($target, $link);
        RunState::$baseOverride = $link;
        try {
            $this->expectException(RuntimeException::class);
            RunState::write('j-x', ['pid' => 1, 'running' => true]);
        } finally {
            @unlink($link);
            @rmdir($target);
            RunState::$baseOverride = $this->rtBase;
        }
    }

    public function testClearRemovesState(): void
    {
        RunState::write('j-x', ['pid' => 1, 'running' => true]);
        $this->assertNotNull(RunState::read('j-x'));
        RunState::clear('j-x');
        $this->assertNull(RunState::read('j-x'));
    }

    public function testMarkStoppedPreservesLogButFlipsRunning(): void
    {
        RunState::write('j-x', [
            'pid' => 7, 'running' => true, 'currentLog' => '/l/run.log', 'startedAt' => 'T',
        ]);
        RunState::markStopped('j-x');
        $s = RunState::read('j-x');
        $this->assertFalse($s['running']);
        $this->assertSame(0, $s['pid']);
        $this->assertSame('/l/run.log', $s['currentLog'], 'currentLog preserved for the log viewer');
    }

    public function testAbortFlagLifecycle(): void
    {
        $this->assertFalse(RunState::abortRequested('j-x'));
        RunState::requestAbort('j-x');
        $this->assertTrue(RunState::abortRequested('j-x'));
        RunState::clearAbort('j-x');
        $this->assertFalse(RunState::abortRequested('j-x'));
    }

    public function testCmdlineMatchesJob(): void
    {
        $this->assertTrue(RunState::cmdlineMatchesJob("php\0/x/scripts/runner.php\0--job=j-music", 'j-music'));
        $this->assertTrue(RunState::cmdlineMatchesJob('php /x/scripts/runner.php --job=j-music --dry-run', 'j-music'));
        // Trailing --job at the very end of the cmdline (boundary = end-of-string).
        $this->assertTrue(RunState::cmdlineMatchesJob('php runner.php --job=j-music', 'j-music'));
        // --trigger appended AFTER --job (manual + schedule launch forms) must
        // still match: --job=<id> stays followed by whitespace.
        $this->assertTrue(RunState::cmdlineMatchesJob('php runner.php --job=j-music --trigger=manual', 'j-music'));
        $this->assertTrue(RunState::cmdlineMatchesJob("php\0runner.php\0--job=j-music\0--trigger=schedule", 'j-music'));
        // Wrong job id.
        $this->assertFalse(RunState::cmdlineMatchesJob('php runner.php --job=j-other', 'j-music'));
        // Not our runner at all.
        $this->assertFalse(RunState::cmdlineMatchesJob('sleep 9999', 'j-music'));
        $this->assertFalse(RunState::cmdlineMatchesJob('', 'j-music'));
        $this->assertFalse(RunState::cmdlineMatchesJob('php runner.php --job=j-music', ''));
    }

    public function testCmdlineMatchesJobIsNotPrefixMatched(): void
    {
        // job id "j-a" must NOT match a runner of the LONGER id "j-a-b": the
        // token boundary stops a prefix collision (which would mis-report which
        // job is running and break PID-reuse safety).
        $this->assertFalse(
            RunState::cmdlineMatchesJob("php\0runner.php\0--job=j-a-b", 'j-a'),
            '"j-a" must not match "--job=j-a-b"'
        );
        $this->assertFalse(
            RunState::cmdlineMatchesJob('php runner.php --job=j-a-b --dry-run', 'j-a')
        );
        // The longer id still matches itself.
        $this->assertTrue(RunState::cmdlineMatchesJob("php\0runner.php\0--job=j-a-b", 'j-a-b'));
    }

    public function testIsRunningTrueWhenAliveAndCmdlineMatches(): void
    {
        FakeRunState::write('j-music', ['pid' => 999, 'running' => true]);
        FakeRunState::$alive = true;
        FakeRunState::$cmdline = "php\0runner.php\0--job=j-music";
        $this->assertTrue(FakeRunState::isRunning('j-music'));
    }

    public function testIsRunningFalseAndClearsWhenPidDead(): void
    {
        FakeRunState::write('j-music', ['pid' => 999, 'running' => true, 'currentLog' => '/l']);
        FakeRunState::$alive = false; // pid gone
        $this->assertFalse(FakeRunState::isRunning('j-music'));
        // Stale state is cleared (running flipped to false).
        $s = FakeRunState::read('j-music');
        $this->assertNotNull($s);
        $this->assertFalse($s['running']);
    }

    public function testIsRunningFalseAndClearsWhenPidReused(): void
    {
        FakeRunState::write('j-music', ['pid' => 999, 'running' => true]);
        FakeRunState::$alive = true;            // pid alive...
        FakeRunState::$cmdline = 'sleep 100000'; // ...but NOT our runner (reused)
        $this->assertFalse(FakeRunState::isRunning('j-music'));
        $this->assertFalse(FakeRunState::read('j-music')['running']);
    }

    public function testIsRunningFalseWhenNotRunningFlag(): void
    {
        FakeRunState::write('j-music', ['pid' => 999, 'running' => false]);
        FakeRunState::$alive = true;
        FakeRunState::$cmdline = "runner.php\0--job=j-music";
        $this->assertFalse(FakeRunState::isRunning('j-music'));
    }

    public function testIsRunningFalseWhenNoState(): void
    {
        $this->assertFalse(FakeRunState::isRunning('j-never'));
    }

    public function testAcquireLockIsExclusive(): void
    {
        $a = RunState::acquireLock('j-lock');
        $this->assertIsResource($a, 'first lock acquisition succeeds');
        // A second acquisition WITHIN THE SAME PROCESS still contends on the same
        // file; flock is advisory and per-open-file-description, so a second
        // fopen+flock(LOCK_NB) on the locked file must fail.
        $b = RunState::acquireLock('j-lock');
        $this->assertNull($b, 'second concurrent acquisition is refused');
        // After releasing the first, it can be re-acquired.
        RunState::releaseLock($a);
        $c = RunState::acquireLock('j-lock');
        $this->assertIsResource($c, 're-acquire after release');
        RunState::releaseLock($c);
    }

    public function testReleaseLockToleratesNonResource(): void
    {
        // Must be a safe no-op (used on error paths where the lock may be null).
        RunState::releaseLock(null);
        $this->assertTrue(true);
    }
}

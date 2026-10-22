<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The run lifecycle around the transfer: teardown steps that cannot skip one
 * another, which stops count as an Abort (a user's flag for this launch) and
 * which as a failure (a signal nobody asked for), the launch generation that
 * keeps an early Abort and drops a stale one, and the last-run summary model
 * (top level = last execution, lastReal = last non-dry run) including the
 * records a crashed runner and a scheduled hard failure leave.
 */
final class RunnerLifecycleTest extends TestCase
{
    /** @var array<int,string> hook/rsync events in order */
    private array $trace = [];

    /** @var array<int,string> captured notify command lines */
    private array $captured = [];

    private string $fakeBin = '';

    private string $rt = '';

    protected function setUp(): void
    {
        $this->trace    = [];
        $this->captured = [];
        $this->fakeBin  = sys_get_temp_dir() . '/ur-life-notify-' . getmypid() . '-' . bin2hex(random_bytes(4));
        file_put_contents($this->fakeBin, "#!/bin/sh\nexit 0\n");
        chmod($this->fakeBin, 0755);
        Notify::$notifyPath = $this->fakeBin;
        Notify::$runner     = function (string $command): int {
            $this->captured[] = $command;
            return 0;
        };
        Runner::$pidProvider = fn(): int => 12345;
        Runner::$hookRunner  = function (string $hook, array $env): int {
            $this->trace[] = 'hook:' . $hook . ':' . ($env['UR_JOB_STATUS'] ?? '');
            return 0;
        };
        Rsync::$rsyncPathOverride = PHP_BINARY;
        Rsync::$runner = function (array $argv, $onOutput): int {
            $this->trace[] = 'rsync';
            return 0;
        };
        Config::save(Config::defaults());
        Credentials::$secretsDirOverride = null;
    }

    protected function tearDown(): void
    {
        Runner::$teardownFault    = null;
        Runner::$hookRunner       = null;
        Runner::$pidProvider      = null;
        Rsync::$runner            = null;
        Rsync::$rsyncPathOverride = null;
        Notify::$runner           = null;
        Notify::$notifyPath       = '/usr/local/emhttp/webGui/scripts/notify';
        @unlink($this->fakeBin);
        if ($this->rt !== '') {
            Ssh::$runtimeBase = '/tmp/unraid.rsync';
            Credentials::save(Credentials::defaults());
            $this->rmTree($this->rt);
            $this->rt = '';
        }
    }

    /** @param array<string,mixed> $overrides */
    private function saveLocalJob(array $overrides = []): string
    {
        $id     = 'j-life-' . bin2hex(random_bytes(4));
        $config = Config::load();
        $config['jobs'][] = array_merge(Config::defaultJob(), [
            'id'         => $id,
            'name'       => $id,
            'transport'  => 'LOCAL',
            'direction'  => 'PUSH',
            'pairs'      => [['local' => '/mnt/user/src/', 'remote' => '/mnt/disk1/dst/']],
            'notifyMode' => 'always',
            'postHook'   => 'POST',
        ], $overrides);
        Config::save($config);
        return $id;
    }

    /** An SSH key job with accept-new, so the run materialises secrets and pins. */
    private function saveSshJob(): string
    {
        $this->rt = sys_get_temp_dir() . '/ur-life-rt-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Ssh::$runtimeBase = $this->rt;
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id' => 'k-1', 'name' => 'k1',
            'privateKey'  => "-----BEGIN OPENSSH PRIVATE KEY-----\nFAKEKEYMATERIAL\n-----END OPENSSH PRIVATE KEY-----\n",
            'publicKey'   => 'ssh-ed25519 AAAA fake', 'fingerprint' => 'SHA256:fake',
        ];
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-key', 'name' => 'ckey', 'host' => 'h.example', 'username' => 'root',
            'authMethod' => 'KEY', 'keyId' => 'k-1', 'strictHostKey' => 'accept-new',
        ]);
        Credentials::save($creds);
        return $this->saveLocalJob([
            'transport' => 'SSH', 'connectionId' => 'c-key',
            'pairs'     => [['local' => '/mnt/user/src/', 'remote' => '/data/dst/']],
        ]);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
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

    /** The secret files a run left in the tmpfs runtime base. */
    private function secretFiles(): array
    {
        return array_merge(glob($this->rt . '/keys/*') ?: [], glob($this->rt . '/pass/*') ?: [], glob($this->rt . '/known_hosts/*') ?: []);
    }

    private function pluginLogSince(int $offset): string
    {
        return (string) @file_get_contents(Logger::pluginLogPath(), false, null, $offset);
    }

    private function pluginLogSize(): int
    {
        clearstatcache();
        return is_file(Logger::pluginLogPath()) ? (int) filesize(Logger::pluginLogPath()) : 0;
    }

    private function importance(int $i = 0): string
    {
        $this->assertSame(1, preg_match("/'-i' '(\\w+)'/", $this->captured[$i] ?? '', $m));
        return $m[1];
    }

    // --- DI-26: independent teardown steps ---------------------------------

    public function testAThrowingHostKeyPinStillRunsTheRestOfTheTeardown(): void
    {
        $id = $this->saveSshJob();
        Logger::event('', $id, 'make sure plugin.log exists');
        $before = $this->pluginLogSize();
        Runner::$teardownFault = static function (string $step): void {
            if ($step === 'host-key pin') {
                throw new RuntimeException('pin exploded');
            }
        };

        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], 'a failed pin does not change the outcome');
        $this->assertContains('hook:POST:SUCCESS', $this->trace, 'the post-hook ran');
        $this->assertSame([], $this->secretFiles(), 'the secrets after the pin were still cleaned up');
        $this->assertSame('SUCCESS', Runner::readSummary($id)['state'] ?? null);
        $this->assertSame('SUCCESS', History::list($id)['runs'][0]['state'] ?? null);
        $this->assertFalse(RunState::read($id)['running'] ?? true, 'the run state is marked stopped');
        $this->assertCount(1, $this->captured, 'the notification went out');
        $tail = $this->pluginLogSince($before);
        $this->assertSame(1, substr_count($tail, 'Run teardown step "host-key pin" failed: pin exploded'), $tail);
        RunState::releaseLock($this->assertLockFree($id));
    }

    public function testAThrowingSummaryWriterStillCleansUpAndMarksTheStateStopped(): void
    {
        $id = $this->saveSshJob();
        Runner::$teardownFault = static function (string $step): void {
            if ($step === 'summary') {
                throw new RuntimeException('flash gone');
            }
        };

        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_FAILED, $res['state'], 'an outcome that could not be recorded is a failure');
        $this->assertSame('teardown-failed', $res['reason'] ?? null);
        $this->assertSame([], $this->secretFiles(), 'secret cleanup ran');
        $this->assertNull(Runner::readSummary($id));
        $row = History::list($id)['runs'][0] ?? [];
        $this->assertSame(['FAILED', 'teardown-failed'], [$row['state'] ?? null, $row['reason'] ?? null], 'History carries it');
        $this->assertFalse(RunState::read($id)['running'] ?? true, 'the run state is marked stopped');
        $this->assertFalse(RunState::abortRequested($id));
        $this->assertSame('alert', $this->importance());
        RunState::releaseLock($this->assertLockFree($id));
    }

    public function testARunStateThatCannotBeWrittenNoLongerEscapesTheRun(): void
    {
        $id    = $this->saveLocalJob();
        $state = RunState::stateDir();
        $moved = $state . '.real-' . bin2hex(random_bytes(3));
        // During the transfer the state dir turns into a symlink, which
        // RunState refuses to write through: markStopped throws.
        Rsync::$runner = function (array $argv, $onOutput) use ($state, $moved): int {
            rename($state, $moved);
            symlink($moved, $state);
            return 0;
        };
        try {
            $res = Runner::run($id, false);
        } finally {
            if (is_link($state)) {
                unlink($state);
                rename($moved, $state);
            }
        }

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('teardown-failed', $res['reason'] ?? null);
        $this->assertContains('hook:POST:SUCCESS', $this->trace);
        $this->assertSame('SUCCESS', Runner::readSummary($id)['state'] ?? null, 'recorded before the state write failed');
        $this->assertCount(1, $this->captured, 'the failure is notified');
        RunState::releaseLock($this->assertLockFree($id));
    }

    /** @return resource */
    private function assertLockFree(string $id)
    {
        $lock = RunState::acquireLock($id);
        $this->assertNotNull($lock, 'the run lock was released');
        return $lock;
    }

    // --- DI-13 / DI-14: what counts as an Abort ---------------------------

    public function testAnAbortDuringThePreHookIsAbortedNotAHookFailure(): void
    {
        $id = $this->saveLocalJob(['preHook' => 'PRE', 'notifyMode' => 'failure-only']);
        Runner::$hookRunner = function (string $hook, array $env) use ($id): int {
            $this->trace[] = 'hook:' . $hook . ':' . ($env['UR_JOB_STATUS'] ?? '');
            if ($hook === 'PRE') {
                RunState::requestAbort($id, (string) (RunState::read($id)['gen'] ?? ''));
                return 143; // killed by the stop request
            }
            return 0;
        };

        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_ABORTED, $res['state']);
        $this->assertArrayNotHasKey('reason', $res, 'not prehook-failed');
        $this->assertNotContains('rsync', $this->trace);
        $this->assertContains('hook:POST:ABORTED', $this->trace);
        $this->assertSame([], $this->captured, 'a manual abort does not alert a failure-only job');
    }

    public function testAStopWithoutAUserAbortIsAFailedTerminatedRun(): void
    {
        $id = $this->saveLocalJob(['notifyMode' => 'failure-only']);
        Rsync::$runner = function (array $argv, $onOutput) use ($id): int {
            // What the runner's own SIGTERM handler records on a shutdown.
            RunState::requestAbort($id, (string) (RunState::read($id)['gen'] ?? ''), 'signal');
            return 20;
        };

        $res = Runner::run($id, false, 'manual');

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('terminated', $res['reason'] ?? null);
        $this->assertContains('hook:POST:FAILED', $this->trace, 'the post-hook sees the final classification');
        $this->assertSame(['FAILED', 'terminated'], [History::list($id)['runs'][0]['state'], History::list($id)['runs'][0]['reason']]);
        $this->assertCount(1, $this->captured, 'failure-only hears about it');
        $this->assertSame('alert', $this->importance());
    }

    public function testASignalFlagNeverReplacesTheUsersAbort(): void
    {
        $id = $this->saveLocalJob();
        Rsync::$runner = function (array $argv, $onOutput) use ($id): int {
            $gen = (string) (RunState::read($id)['gen'] ?? '');
            RunState::requestAbort($id, $gen);           // the handler's flag...
            RunState::requestAbort($id, $gen, 'signal'); // ...then its SIGTERM lands
            return 143;
        };
        $res = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_ABORTED, $res['state']);
    }

    public function testARealSigtermWithoutAnAbortIsRecordedAsTerminated(): void
    {
        if (!function_exists('pcntl_signal_get_handler') || !function_exists('posix_kill')) {
            $this->markTestSkipped('needs pcntl and posix');
        }
        $id = $this->saveLocalJob();
        $sent = false;
        Rsync::$runner = function (array $argv, $onOutput) use (&$sent): int {
            // Only ever with the runner's trap installed: the default
            // disposition would kill the test process.
            if (is_callable(pcntl_signal_get_handler(SIGTERM))) {
                $sent = posix_kill(getmypid(), SIGTERM);
            }
            return 20;
        };
        $res = Runner::run($id, false);
        if (!$sent) {
            $this->markTestSkipped('pcntl is disabled here, so the runner installs no trap');
        }
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('terminated', $res['reason'] ?? null);
        $this->assertFalse(RunState::abortRequested($id), 'the signal flag is cleared at the end');
    }

    // --- DI-17: the launch generation --------------------------------------

    public function testAnAbortClickedBeforeTheRunnerStartsIsHonoured(): void
    {
        $id  = $this->saveLocalJob(['preHook' => 'PRE']);
        $gen = RunState::beginLaunch($id);
        RunState::requestAbort($id, (string) RunState::read($id)['gen']); // abortJob, same second

        $res = Runner::run($id, false, 'manual', $gen);

        $this->assertSame(Rsync::STATE_ABORTED, $res['state']);
        $this->assertSame(['hook:POST:ABORTED'], $this->trace, 'no pre-hook, no transfer; the post-hook still runs');
        $this->assertSame($gen, RunState::read($id)['gen'] ?? null, 'the runner adopted the launch generation');
        $this->assertFalse(RunState::abortRequested($id));
    }

    public function testAStaleAbortFromAnEarlierLaunchIsIgnored(): void
    {
        $id  = $this->saveLocalJob();
        $old = RunState::beginLaunch($id);
        RunState::requestAbort($id, $old);
        $new = RunState::beginLaunch($id);

        $res = Runner::run($id, false, 'manual', $new);

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertContains('rsync', $this->trace);
    }

    public function testAScheduledRunMakesItsOwnGenerationAndDropsAnyEarlierFlag(): void
    {
        $id = $this->saveLocalJob();
        RunState::requestAbort($id, RunState::beginLaunch($id));
        $res = Runner::run($id, false, 'schedule', 'not-hex!');
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) (RunState::read($id)['gen'] ?? ''));
    }

    // --- DI-6: the summary model -------------------------------------------

    public function testADryRunAfterAFailedRunKeepsTheFailureAsTheLastRealRun(): void
    {
        $id = $this->saveLocalJob(['notifyMode' => 'off']);
        Rsync::$runner = fn(array $argv, $onOutput): int => 12;
        $this->assertSame(Rsync::STATE_FAILED, Runner::run($id, false)['state']);
        $real = Runner::readSummary($id);

        Rsync::$runner = fn(array $argv, $onOutput): int => 0;
        $this->assertSame(Rsync::STATE_SUCCESS, Runner::run($id, true)['state']);

        $sum = Runner::readSummary($id);
        $this->assertTrue($sum['dryRun'], 'the top level is the last execution');
        $this->assertSame('SUCCESS', $sum['state']);
        $this->assertSame(Runner::lastReal($real), Runner::lastReal($sum), 'lastReal untouched by the dry run');
        $this->assertSame('FAILED', Runner::lastReal($sum)['state']);
        $this->assertSame('FAILED', PageHelpers::deriveState(false, Runner::lastReal($sum)));
        $this->assertSame('Dry-run OK', PageHelpers::lastDryRun($sum)['label'] ?? null);

        // The next real run replaces both.
        $this->assertSame(Rsync::STATE_SUCCESS, Runner::run($id, false)['state']);
        $sum = Runner::readSummary($id);
        $this->assertFalse($sum['dryRun']);
        $this->assertSame('SUCCESS', Runner::lastReal($sum)['state']);
        $this->assertNull(PageHelpers::lastDryRun($sum));
    }

    public function testASummaryWrittenBeforeLastRealExistedStillReads(): void
    {
        $old = ['state' => 'SUCCESS', 'startedAt' => '2026-10-01T03:00:00Z', 'finishedAt' => '2026-10-01T03:10:00Z',
            'exitCode' => 0, 'durationSec' => 600, 'dryRun' => false, 'trigger' => 'schedule'];
        $this->assertSame(
            ['state' => 'SUCCESS', 'startedAt' => '2026-10-01T03:00:00Z', 'finishedAt' => '2026-10-01T03:10:00Z', 'exitCode' => 0, 'reason' => ''],
            Runner::lastReal($old)
        );
        $this->assertNull(Runner::lastReal(['dryRun' => true] + $old), 'an old dry-run summary has no real run in it');
        $this->assertNull(Runner::lastReal(null));

        // A dry run on top of an old-format summary keeps it as the last real run.
        $id   = 'j-oldfmt-' . bin2hex(random_bytes(3));
        $path = rtrim(UR_CONFIG_BASE, '/') . '/runs/' . $id . '.summary.json';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($old));
        $this->assertTrue(Runner::writeSummary($id, ['state' => 'FAILED', 'startedAt' => '2026-10-02T03:00:00Z', 'dryRun' => true]));
        $this->assertSame('2026-10-01T03:00:00Z', Runner::lastReal(Runner::readSummary($id))['startedAt'] ?? null);
        $this->assertSame('Dry-run failed', PageHelpers::lastDryRun(Runner::readSummary($id))['label'] ?? null);
    }

    public function testAnOldDryRunSummaryFindsTheRealRunItOverwroteInHistory(): void
    {
        // The upgrade case: a real SUCCESS, then a dry run, both before
        // lastReal existed. The summary only remembers the dry run.
        $id = 'j-olddry-' . bin2hex(random_bytes(3));
        History::append($id, ['startedAt' => '2026-10-20T03:00:00Z', 'finishedAt' => '2026-10-20T03:30:00Z', 'state' => 'SUCCESS', 'exitCode' => 0, 'dryRun' => false]);
        History::append($id, ['startedAt' => '2026-10-21T03:00:00Z', 'finishedAt' => '2026-10-21T03:00:00Z', 'state' => 'SKIPPED', 'exitCode' => 0, 'dryRun' => false]);
        History::append($id, ['startedAt' => '2026-10-21T09:00:00Z', 'finishedAt' => '2026-10-21T09:01:00Z', 'state' => 'SUCCESS', 'exitCode' => 0, 'dryRun' => true]);
        $dry  = ['state' => 'SUCCESS', 'startedAt' => '2026-10-21T09:00:00Z', 'finishedAt' => '2026-10-21T09:01:00Z', 'exitCode' => 0, 'durationSec' => 60, 'dryRun' => true, 'trigger' => 'manual'];

        $this->assertNull(Runner::lastReal($dry));
        $this->assertSame(
            ['state' => 'SUCCESS', 'startedAt' => '2026-10-20T03:00:00Z', 'finishedAt' => '2026-10-20T03:30:00Z', 'exitCode' => 0, 'reason' => ''],
            Runner::lastRealFor($id, $dry)
        );
        $this->assertNull(Runner::lastRealFor($id, ['lastReal' => null] + $dry), 'a new-format summary says "none" itself');

        // The next write carries it over, so History is not consulted again.
        $path = rtrim(UR_CONFIG_BASE, '/') . '/runs/' . $id . '.summary.json';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($dry));
        $this->assertTrue(Runner::writeSummary($id, ['state' => 'SUCCESS', 'startedAt' => '2026-10-22T09:00:00Z', 'dryRun' => true]));
        $this->assertSame('2026-10-20T03:00:00Z', Runner::readSummary($id)['lastReal']['startedAt'] ?? null);
        @unlink(History::path($id));
    }

    // --- DI-5: crashed runs and scheduled hard failures reach the summary ---

    public function testACrashedRunReplacesTheOlderSuccessInTheSummary(): void
    {
        $id = $this->saveLocalJob(['notifyMode' => 'off']);
        Runner::writeSummary($id, ['state' => 'SUCCESS', 'startedAt' => '2026-10-18T03:00:00Z', 'finishedAt' => '2026-10-18T03:10:00Z', 'exitCode' => 0]);

        Runner::reportCrashed(['jobId' => $id, 'pid' => 999999, 'startedAt' => '2026-10-21T03:00:00Z', 'trigger' => 'schedule']);

        $sum = Runner::readSummary($id);
        $this->assertSame(['CRASHED', 'crashed', null, '2026-10-21T03:00:00Z'], [$sum['state'], $sum['reason'], $sum['exitCode'], $sum['startedAt']]);
        $this->assertSame('CRASHED', Runner::lastReal($sum)['state']);
        $this->assertSame(['cls' => 'failed', 'label' => 'Crashed'], PageHelpers::state('CRASHED'));
        $this->assertSame(['FAILED', 'crashed'], [History::list($id)['runs'][0]['state'], History::list($id)['runs'][0]['reason']], 'History records keep their shape');
    }

    public function testACrashRecordNeverReplacesANewerRun(): void
    {
        $id = $this->saveLocalJob(['notifyMode' => 'off']);
        $newer = ['state' => 'SUCCESS', 'startedAt' => '2026-10-22T03:00:00Z', 'finishedAt' => '2026-10-22T03:10:00Z', 'exitCode' => 0];
        Runner::writeSummary($id, $newer);
        $before = Runner::readSummary($id);

        Runner::reportCrashed(['jobId' => $id, 'pid' => 999999, 'startedAt' => '2026-10-21T03:00:00Z', 'trigger' => 'schedule']);

        $this->assertSame($before, Runner::readSummary($id));
        $this->assertSame('crashed', History::list($id)['runs'][0]['reason'] ?? null, 'still in History');
    }

    public function testACrashOlderThanADryRunStillBecomesTheLastRealRun(): void
    {
        $id = $this->saveLocalJob(['notifyMode' => 'off']);
        Runner::writeSummary($id, ['state' => 'SUCCESS', 'startedAt' => '2026-10-19T03:00:00Z', 'finishedAt' => '2026-10-19T03:10:00Z', 'exitCode' => 0]);
        Runner::writeSummary($id, ['state' => 'SUCCESS', 'startedAt' => '2026-10-22T09:00:00Z', 'finishedAt' => '2026-10-22T09:01:00Z', 'exitCode' => 0, 'dryRun' => true]);

        Runner::reportCrashed(['jobId' => $id, 'pid' => 999999, 'startedAt' => '2026-10-21T03:00:00Z', 'trigger' => 'schedule']);

        $sum = Runner::readSummary($id);
        $this->assertSame(['SUCCESS', true], [$sum['state'], $sum['dryRun']], 'the newer dry run stays the last execution');
        $this->assertSame(['CRASHED', '2026-10-21T03:00:00Z'], [Runner::lastReal($sum)['state'], Runner::lastReal($sum)['startedAt']]);
    }

    public function testAScheduledHardFailWritesAFailedSummary(): void
    {
        $id = $this->saveLocalJob(['notifyMode' => 'off']);
        Runner::writeSummary($id, ['state' => 'SUCCESS', 'startedAt' => '2026-10-18T03:00:00Z', 'finishedAt' => '2026-10-18T03:10:00Z', 'exitCode' => 0]);
        file_put_contents(Config::path(), '{ not json');
        try {
            $res = Runner::run($id, false, 'schedule');
        } finally {
            Config::save(Config::defaults());
        }
        $this->assertSame('config-unreadable', $res['reason']);
        $sum = Runner::readSummary($id);
        $this->assertSame(['FAILED', 'config-unreadable', null], [$sum['state'], $sum['reason'], $sum['exitCode']]);
        $this->assertSame('FAILED', Runner::lastReal($sum)['state']);
    }

    public function testAManualHardFailLeavesTheSummaryAlone(): void
    {
        $id = $this->saveLocalJob(['notifyMode' => 'off']);
        Runner::writeSummary($id, ['state' => 'SUCCESS', 'startedAt' => '2026-10-18T03:00:00Z', 'exitCode' => 0]);
        $before = Runner::readSummary($id);
        $held = RunState::acquireLock($id);
        try {
            $this->assertSame('already-running', Runner::run($id, false, 'schedule')['reason']);
            $this->assertSame($before, Runner::readSummary($id), 'SKIPPED writes no summary');
        } finally {
            RunState::releaseLock($held);
        }
        file_put_contents(Config::path(), '{ not json');
        try {
            Runner::run($id, false, 'manual');
        } finally {
            Config::save(Config::defaults());
        }
        $this->assertSame($before, Runner::readSummary($id), 'a manual hard failure is answered in the UI, not recorded');
    }
}

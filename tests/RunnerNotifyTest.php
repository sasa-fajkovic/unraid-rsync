<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the run notifications: Runner::notifyHook / notifyImportance /
 * shouldNotify / notifyDescription driven directly, and the same decisions
 * reached through Runner::run, hardFail and the stale-state reaper.
 *
 * Dispatch is captured via an injected Notify::$runner (the fake notify binary
 * is present so available() is true). Asserts:
 *   - importance is chosen by CAUSE (exit codes per pair, reason, trigger),
 *     not by state alone, and failure-only fires on every non-normal one;
 *   - the full mode x cause matrix;
 *   - the description carries job, state, reason, exit code + meaning,
 *     duration, trigger and the dry-run flag;
 *   - hard failures notify per reason and trigger, and record scheduled ones;
 *   - a dead runner is reported exactly once;
 *   - notifyHook NEVER throws.
 */
final class RunnerNotifyTest extends TestCase
{
    /** @var string a real, executable temp file standing in for the notify binary */
    private string $fakeBin = '';

    /** @var array<int,string> captured notify command lines this test */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->fakeBin = sys_get_temp_dir() . '/ur-rnotify-' . getmypid() . '-' . bin2hex(random_bytes(4));
        file_put_contents($this->fakeBin, "#!/bin/sh\nexit 0\n");
        chmod($this->fakeBin, 0755);

        Notify::$notifyPath = $this->fakeBin;
        $this->captured = [];
        Notify::$runner = function (string $command): int {
            $this->captured[] = $command;
            return 0;
        };

        Runner::$pidProvider       = fn(): int => 12345;
        Runner::$hookRunner        = fn(string $hook): int => 0;
        Rsync::$rsyncPathOverride  = PHP_BINARY;
        Rsync::$runner             = fn(array $argv, $onOutput): int => 0;
        Config::save(Config::defaults());
    }

    protected function tearDown(): void
    {
        Notify::$notifyPath = '/usr/local/emhttp/webGui/scripts/notify';
        Notify::$runner     = null;
        Runner::$pidProvider      = null;
        Runner::$hookRunner       = null;
        Rsync::$rsyncPathOverride = null;
        Rsync::$runner            = null;
        RunState::$baseOverride   = null;
        if ($this->fakeBin !== '' && is_file($this->fakeBin)) {
            @unlink($this->fakeBin);
        }
    }

    /** A minimal job array with the given notifyMode. */
    private function job(string $notifyMode, string $name = 'music'): array
    {
        return [
            'id'         => 'j-' . $name,
            'name'       => $name,
            'notifyMode' => $notifyMode,
        ];
    }

    /** Build + persist a LOCAL job with a unique id, returning the id. */
    private function saveJob(string $notifyMode, array $overrides = []): string
    {
        $id     = 'j-n-' . bin2hex(random_bytes(4));
        $config = Config::load();
        $job    = array_merge(Config::defaultJob(), [
            'id'         => $id,
            'name'       => $id,
            'transport'  => 'LOCAL',
            'direction'  => 'PUSH',
            'pairs'      => [['local' => '/mnt/user/src/', 'remote' => '/mnt/disk1/dst/']],
            'notifyMode' => $notifyMode,
        ], $overrides);
        $config['jobs'][] = $job;
        Config::save($config);
        return $id;
    }

    /** The importance a captured command line was sent with. */
    private function importanceOf(string $cmd): string
    {
        $this->assertSame(1, preg_match("/'-i' '(\\w+)'/", $cmd, $m), $cmd);
        return $m[1];
    }

    /** All terminal states the runner can produce. */
    private function terminalStates(): array
    {
        return [
            Rsync::STATE_SUCCESS,
            Rsync::STATE_WARNING,
            Rsync::STATE_PARTIAL,
            Rsync::STATE_TIMEOUT,
            Rsync::STATE_FAILED,
            Rsync::STATE_ABORTED,
        ];
    }

    // --- dry-run notifies (with a marker), subject to the same gating --------

    public function testDryRunNotifiesUnderAlwaysWithMarker(): void
    {
        foreach ($this->terminalStates() as $state) {
            $this->captured = [];
            Runner::notifyHook($this->job('always'), ['state' => $state, 'exitCode' => 0, 'dryRun' => true]);
            $this->assertCount(1, $this->captured, "dry-run must notify under 'always' for state $state");
            $this->assertStringContainsString('[Dry-run]', $this->captured[0]);
        }
    }

    public function testDryRunStillRespectsGating(): void
    {
        $run = ['state' => Rsync::STATE_SUCCESS, 'exitCode' => 0, 'dryRun' => true];
        Runner::notifyHook($this->job('off'), $run);
        Runner::notifyHook($this->job('failure-only'), $run);
        $this->assertCount(0, $this->captured);
    }

    public function testUnknownNotifyModeNeverNotifies(): void
    {
        foreach ($this->terminalStates() as $state) {
            Runner::notifyHook($this->job('garbage-mode'), ['state' => $state, 'exitCode' => 0]);
        }
        $this->assertCount(0, $this->captured);
    }

    // --- importance by cause, and the mode x cause matrix --------------------

    /** @return array<string,array{0:array<string,mixed>,1:string,2:bool}> run, importance, in success-only */
    public static function causeProvider(): array
    {
        $S = Rsync::STATE_SUCCESS; $W = Rsync::STATE_WARNING; $P = Rsync::STATE_PARTIAL;
        $T = Rsync::STATE_TIMEOUT; $F = Rsync::STATE_FAILED;  $A = Rsync::STATE_ABORTED;
        return [
            'success'                 => [['state' => $S, 'exitCode' => 0, 'exitCodes' => [0]], 'normal', true],
            'failed (rsync 12)'       => [['state' => $F, 'exitCode' => 12, 'exitCodes' => [12]], 'alert', false],
            'partial (23)'            => [['state' => $P, 'exitCode' => 23, 'exitCodes' => [23]], 'warning', false],
            'timeout (30)'            => [['state' => $T, 'exitCode' => 30, 'exitCodes' => [30]], 'warning', false],
            'timeout (35)'            => [['state' => $T, 'exitCode' => 35, 'exitCodes' => [35]], 'warning', false],
            'vanished only (24)'      => [['state' => $W, 'exitCode' => 24, 'exitCodes' => [24]], 'normal', true],
            'vanished + clean pair'   => [['state' => $W, 'exitCode' => 24, 'exitCodes' => [0, 24]], 'normal', true],
            'max-delete (25)'         => [['state' => $W, 'exitCode' => 25, 'exitCodes' => [25]], 'warning', false],
            'vanished + max-delete'   => [['state' => $W, 'exitCode' => 24, 'exitCodes' => [24, 25]], 'warning', false],
            'post-hook failed'        => [['state' => $W, 'exitCode' => 0, 'exitCodes' => [0], 'reason' => 'posthook-failed'], 'warning', false],
            'post-hook timeout'       => [['state' => $W, 'exitCode' => 0, 'exitCodes' => [0], 'reason' => 'posthook-timeout'], 'warning', false],
            'vanished + post-hook'    => [['state' => $W, 'exitCode' => 24, 'exitCodes' => [24], 'reason' => 'posthook-failed'], 'warning', false],
            'pre-hook timeout'        => [['state' => $F, 'exitCode' => 124, 'reason' => 'prehook-timeout'], 'alert', false],
            'pre-hook failed'         => [['state' => $F, 'exitCode' => 7, 'reason' => 'prehook-failed'], 'alert', false],
            'aborted, scheduled'      => [['state' => $A, 'exitCode' => 143, 'exitCodes' => [143], 'trigger' => 'schedule'], 'warning', false],
            'aborted, manual'         => [['state' => $A, 'exitCode' => 143, 'exitCodes' => [143], 'trigger' => 'manual'], 'normal', false],
            'did not start'           => [['state' => $F, 'exitCode' => 1, 'reason' => 'job-not-found'], 'alert', false],
            'scheduled run skipped'   => [['state' => $F, 'exitCode' => 0, 'reason' => 'already-running', 'trigger' => 'schedule'], 'warning', false],
            'crashed'                 => [['state' => $F, 'exitCode' => 1, 'reason' => 'crashed'], 'alert', false],
        ];
    }

    /** @param array<string,mixed> $run */
    #[DataProvider('causeProvider')]
    public function testImportanceAndModeMatrixByCause(array $run, string $importance, bool $inSuccessOnly): void
    {
        $this->assertSame($importance, Runner::notifyImportance($run));

        $expect = [
            'off'          => false,
            'always'       => true,
            'failure-only' => $importance !== 'normal',
            'success-only' => $inSuccessOnly,
        ];
        foreach ($expect as $mode => $notify) {
            $this->captured = [];
            Runner::notifyHook($this->job($mode), $run);
            $this->assertCount($notify ? 1 : 0, $this->captured, "mode=$mode");
            if ($notify) {
                $this->assertSame($importance, $this->importanceOf($this->captured[0]), "mode=$mode");
            }
        }
    }

    // --- message composition -------------------------------------------------

    public function testSubjectAndDescriptionCarryEveryField(): void
    {
        $run = [
            'state' => Rsync::STATE_WARNING, 'exitCode' => 25, 'exitCodes' => [25],
            'trigger' => 'schedule', 'dryRun' => false, 'durationSec' => 125,
        ];
        Runner::notifyHook($this->job('always', 'photos'), $run);
        $this->assertCount(1, $this->captured);
        $cmd  = $this->captured[0];
        $desc = Runner::notifyDescription($this->job('always', 'photos'), $run);

        $this->assertStringContainsString(escapeshellarg('Unraid Rsync: photos WARNING'), $cmd);
        $this->assertStringContainsString(escapeshellarg($desc), $cmd);
        $this->assertStringContainsString(escapeshellarg('/Settings/UnraidRsync'), $cmd);
        $this->assertSame(
            'Job "photos" finished. State: WARNING. '
            . 'Exit code: 25 = max-delete limit reached, deletions stopped: the destination is incomplete. '
            . 'Duration: 2m 5s. Trigger: schedule. Dry-run: no.',
            $desc
        );
    }

    public function testDescriptionCarriesReasonAndDryRun(): void
    {
        $desc = Runner::notifyDescription($this->job('always', 'photos'), [
            'state' => Rsync::STATE_WARNING, 'exitCode' => 0, 'exitCodes' => [0],
            'reason' => 'posthook-failed', 'dryRun' => true, 'durationSec' => 3,
        ]);
        $this->assertStringStartsWith('[Dry-run] Job "photos" finished.', $desc);
        $this->assertStringContainsString('Reason: posthook-failed.', $desc);
        $this->assertStringContainsString('Exit code: 0 = success.', $desc);
        $this->assertStringContainsString('Trigger: manual.', $desc);
        $this->assertStringContainsString('Dry-run: yes.', $desc);
    }

    public function testDescriptionListsPairsWhenTheyDisagree(): void
    {
        $desc = Runner::notifyDescription($this->job('always'), [
            'state' => Rsync::STATE_PARTIAL, 'exitCode' => 23, 'exitCodes' => [25, 23],
        ]);
        $this->assertStringContainsString(
            'Exit code: 23 = partial transfer due to error (pair #1 25 = max-delete limit reached', $desc
        );
        $this->assertStringContainsString('pair #2 23 = partial transfer due to error)', $desc);
    }

    public function testDescriptionOnlyExplainsCodesRsyncProduced(): void
    {
        // A pre-flight failure carries a placeholder 1, a pre-hook its own code:
        // rsync's table ("syntax or usage error") would mislabel both.
        $guard = Runner::notifyDescription($this->job('always'), ['state' => Rsync::STATE_FAILED, 'exitCode' => 1, 'reason' => 'guardrail']);
        $this->assertStringContainsString('Exit code: 1.', $guard);
        $pre = Runner::notifyDescription($this->job('always'), ['state' => Rsync::STATE_FAILED, 'exitCode' => 1, 'exitCodes' => [], 'reason' => 'prehook-failed']);
        $this->assertStringContainsString('Exit code: 1 (pre-run hook).', $pre);
        $this->assertStringContainsString('Duration: unknown.', $pre);
    }

    // --- the same decisions through Runner::run ------------------------------

    public function testRunExit24IsSuccessForNotifications(): void
    {
        Rsync::$runner = fn(array $argv, $onOutput): int => 24;
        $res = Runner::run($this->saveJob('failure-only'), false);
        $this->assertSame(Rsync::STATE_WARNING, $res['state'], 'state/history keep WARNING');
        $this->assertCount(0, $this->captured, 'exit 24 is not a failure-only notification');

        Runner::run($this->saveJob('success-only'), false);
        $this->assertCount(1, $this->captured);
        $this->assertSame('normal', $this->importanceOf($this->captured[0]));
    }

    public function testRunExit25WarnsThatTheDestinationIsIncomplete(): void
    {
        Rsync::$runner = fn(array $argv, $onOutput): int => 25;
        Runner::run($this->saveJob('failure-only'), false, 'schedule');
        $this->assertCount(1, $this->captured);
        $this->assertSame('warning', $this->importanceOf($this->captured[0]));
        $this->assertStringContainsString('25 = max-delete limit reached', $this->captured[0]);
        $this->assertStringContainsString('the destination is incomplete', $this->captured[0]);
        $this->assertStringContainsString('Trigger: schedule.', $this->captured[0]);
    }

    public function testRunPostHookFailureOnAnExit24RunStillWarns(): void
    {
        Rsync::$runner      = fn(array $argv, $onOutput): int => 24;
        Runner::$hookRunner = fn(string $hook): int => $hook === 'POST' ? 3 : 0;
        $res = Runner::run($this->saveJob('failure-only', ['postHook' => 'POST']), false);
        $this->assertSame('posthook-failed', $res['reason'] ?? '');
        $this->assertCount(1, $this->captured);
        $this->assertSame('warning', $this->importanceOf($this->captured[0]));
    }

    public function testRunPostHookTimeoutWarns(): void
    {
        Runner::$hookRunner = fn(string $hook): int => $hook === 'POST' ? Runner::HOOK_TIMED_OUT : 0;
        Runner::run($this->saveJob('failure-only', ['postHook' => 'POST']), false);
        $this->assertCount(1, $this->captured);
        $this->assertSame('warning', $this->importanceOf($this->captured[0]));
    }

    public function testRunPreHookTimeoutAlerts(): void
    {
        Runner::$hookRunner = fn(string $hook): int => $hook === 'PRE' ? Runner::HOOK_TIMED_OUT : 0;
        Runner::run($this->saveJob('failure-only', ['preHook' => 'PRE']), false);
        $this->assertCount(1, $this->captured);
        $this->assertSame('alert', $this->importanceOf($this->captured[0]));
        $this->assertStringContainsString('Reason: prehook-timeout.', $this->captured[0]);
    }

    /** @return array<string,array{0:string,1:int}> trigger, failure-only notifications */
    public static function abortProvider(): array
    {
        return ['scheduled abort warns' => ['schedule', 1], 'manual abort is FYI' => ['manual', 0]];
    }

    #[DataProvider('abortProvider')]
    public function testRunAbortImportanceDependsOnTrigger(string $trigger, int $failureOnly): void
    {
        $id = $this->saveJob('failure-only');
        Rsync::$runner = function (array $argv, $onOutput) use ($id): int {
            RunState::requestAbort($id);
            return 143;
        };
        $res = Runner::run($id, false, $trigger);
        $this->assertSame(Rsync::STATE_ABORTED, $res['state']);
        $this->assertCount($failureOnly, $this->captured);
    }

    // --- hard failures --------------------------------------------------------

    public function testJobNotFoundAlertsEvenWithoutAJobToReadAndRecordsTheScheduledRun(): void
    {
        $id  = 'j-gone-' . bin2hex(random_bytes(3));
        $res = Runner::run($id, false, 'schedule');
        $this->assertSame('job-not-found', $res['reason']);
        $this->assertCount(1, $this->captured);
        $this->assertSame('alert', $this->importanceOf($this->captured[0]));
        $this->assertStringContainsString(escapeshellarg("Unraid Rsync: $id: run did not start"), $this->captured[0]);
        $this->assertStringContainsString("Job not found: $id", $this->captured[0]);

        $runs = History::list($id)['runs'];
        $this->assertCount(1, $runs);
        $this->assertSame(Rsync::STATE_FAILED, $runs[0]['state']);
        $this->assertSame('job-not-found', $runs[0]['reason']);
        $this->assertSame('schedule', $runs[0]['trigger']);
        $this->assertSame('', $runs[0]['logRef']);
    }

    public function testManualHardFailNotifiesButRecordsNoHistory(): void
    {
        $id = 'j-gone-' . bin2hex(random_bytes(3));
        Runner::run($id, false, 'manual');
        $this->assertCount(1, $this->captured);
        $this->assertSame(0, History::list($id)['total']);
    }

    public function testUnreadableConfigAlwaysAlerts(): void
    {
        file_put_contents(rtrim(UR_CONFIG_BASE, '/') . '/config.json', 'not json');
        try {
            $res = Runner::run('j-any', false, 'schedule');
        } finally {
            Config::save(Config::defaults());
        }
        $this->assertSame('config-unreadable', $res['reason']);
        $this->assertCount(1, $this->captured);
        $this->assertSame('alert', $this->importanceOf($this->captured[0]));
        $this->assertStringContainsString('config.json is not valid JSON', $this->captured[0]);
    }

    public function testAlreadyRunningManualIsSilent(): void
    {
        $id   = $this->saveJob('always');
        $held = RunState::acquireLock($id);
        try {
            $res = Runner::run($id, false, 'manual');
        } finally {
            RunState::releaseLock($held);
        }
        $this->assertSame('already-running', $res['reason']);
        $this->assertCount(0, $this->captured);
        $this->assertSame(0, History::list($id)['total']);
    }

    public function testAlreadyRunningScheduledWarnsAndRecordsTheSkip(): void
    {
        $id   = $this->saveJob('failure-only');
        $held = RunState::acquireLock($id);
        try {
            Runner::run($id, false, 'schedule');
        } finally {
            RunState::releaseLock($held);
        }
        $this->assertCount(1, $this->captured);
        $this->assertSame('warning', $this->importanceOf($this->captured[0]));
        $this->assertStringContainsString('previous run still running, scheduled run skipped', $this->captured[0]);
        $runs = History::list($id)['runs'];
        $this->assertSame(['FAILED', 'already-running'], [$runs[0]['state'], $runs[0]['reason']]);
    }

    public function testHardFailHonoursNotifyModeOffWhenTheJobIsReadable(): void
    {
        $id   = $this->saveJob('off');
        $held = RunState::acquireLock($id);
        try {
            Runner::run($id, false, 'schedule');
        } finally {
            RunState::releaseLock($held);
        }
        $this->assertCount(0, $this->captured);
        $this->assertSame(1, History::list($id)['total'], 'the skipped run is still recorded');
    }

    public function testLockErrorAlerts(): void
    {
        $id   = $this->saveJob('failure-only');
        $file = sys_get_temp_dir() . '/ur-notabase-' . bin2hex(random_bytes(4));
        touch($file);
        RunState::$baseOverride = $file;
        try {
            $res = Runner::run($id, false, 'schedule');
        } finally {
            RunState::$baseOverride = null;
            @unlink($file);
        }
        $this->assertSame('lock-error', $res['reason']);
        $this->assertCount(1, $this->captured);
        $this->assertSame('alert', $this->importanceOf($this->captured[0]));
    }

    public function testStateInitFailureAlerts(): void
    {
        $id = $this->saveJob('failure-only');
        // A file where the job's log dir must go makes Logger::openRun throw.
        @mkdir(Logger::logsDir(), 0700, true);
        touch(Logger::jobLogDir($id));
        try {
            $res = Runner::run($id, false, 'manual');
        } finally {
            @unlink(Logger::jobLogDir($id));
        }
        $this->assertSame('state-init-failed', $res['reason']);
        $this->assertCount(1, $this->captured);
        $this->assertSame('alert', $this->importanceOf($this->captured[0]));
    }

    // --- a runner that died ----------------------------------------------------

    /** Leave the state a runner that died mid-run leaves behind. */
    private function writeDeadRunnerState(string $id, string $trigger = 'schedule'): void
    {
        RunState::write($id, [
            'pid'        => 999999,
            'running'    => true,
            'trigger'    => $trigger,
            'startedAt'  => '2026-10-20T03:00:00Z',
            'currentLog' => '/tmp/unraid.rsync/logs/' . $id . '/run-20261020T030000Z.log',
        ]);
    }

    public function testDeadRunnerIsReportedExactlyOnce(): void
    {
        $id = $this->saveJob('failure-only');
        $this->writeDeadRunnerState($id);

        $this->assertFalse(RunState::isRunning($id));
        $this->assertFalse(RunState::isRunning($id));
        $this->assertFalse(RunState::isRunning($id));

        $this->assertCount(1, $this->captured);
        $this->assertSame('alert', $this->importanceOf($this->captured[0]));
        $this->assertStringContainsString(escapeshellarg("Unraid Rsync: $id: previous run died without finishing"), $this->captured[0]);
        $this->assertStringContainsString('previous run died without finishing (pid 999999, started 2026-10-20 03:00:00+00:00)', $this->captured[0]);
        $this->assertStringContainsString('services stopped by the pre-hook may still be down', $this->captured[0]);

        $runs = History::list($id)['runs'];
        $this->assertCount(1, $runs);
        $this->assertSame(['FAILED', 'crashed', 'schedule'], [$runs[0]['state'], $runs[0]['reason'], $runs[0]['trigger']]);
        $this->assertSame('2026-10-20T03:00:00Z', $runs[0]['startedAt']);
        $this->assertSame('run-20261020T030000Z.log', $runs[0]['logRef'], 'the dead run\'s log stays reachable');
    }

    public function testDeadRunnerIsNotReapedWhileTheLockIsHeld(): void
    {
        // A held lock is a runner that is starting (it reaps the state itself)
        // or another detector mid-report.
        $id = $this->saveJob('always');
        $this->writeDeadRunnerState($id);
        $held = RunState::acquireLock($id);
        try {
            $this->assertFalse(RunState::isRunning($id));
            $this->assertTrue(RunState::read($id)['running'], 'left for the lock holder');
            $this->assertCount(0, $this->captured);
        } finally {
            RunState::releaseLock($held);
        }
        RunState::isRunning($id);
        $this->assertCount(1, $this->captured);
    }

    public function testNextRunReportsTheDeadRunnerBeforeStarting(): void
    {
        $id = $this->saveJob('always');
        $this->writeDeadRunnerState($id, 'manual');

        $res = Runner::run($id, false, 'manual');
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);

        $this->assertCount(2, $this->captured, 'the crash, then the run itself');
        $this->assertStringContainsString('previous run died without finishing', $this->captured[0]);
        $this->assertStringContainsString("Unraid Rsync: $id SUCCESS", $this->captured[1]);
        $this->assertSame(['SUCCESS', 'FAILED'], array_column(History::list($id)['runs'], 'state'));

        RunState::isRunning($id);
        $this->assertCount(2, $this->captured, 'nothing left to reap');
    }

    public function testDeadRunnerHonoursNotifyModeOffButIsStillRecorded(): void
    {
        $id = $this->saveJob('off');
        $this->writeDeadRunnerState($id);
        RunState::isRunning($id);
        $this->assertCount(0, $this->captured);
        $this->assertSame('crashed', History::list($id)['runs'][0]['reason']);
    }

    // --- never-throws contract -----------------------------------------------

    public function testNotifyHookNeverThrowsWhenDispatchThrows(): void
    {
        Notify::$runner = function (string $command): int {
            throw new RuntimeException('boom');
        };
        Runner::notifyHook($this->job('always'), ['state' => Rsync::STATE_FAILED, 'exitCode' => 1]);
        $this->addToAssertionCount(1);
    }

    public function testNotifyHookNoOpWhenBinaryMissing(): void
    {
        Notify::$notifyPath = '/no/such/notify';
        Runner::notifyHook($this->job('always'), ['state' => Rsync::STATE_FAILED, 'exitCode' => 1]);
        $this->assertCount(0, $this->captured);
    }

    public function testFormatDuration(): void
    {
        $this->assertSame('0s', Runner::formatDuration(0));
        $this->assertSame('45s', Runner::formatDuration(45));
        $this->assertSame('2m 5s', Runner::formatDuration(125));
        $this->assertSame('1h 1m 1s', Runner::formatDuration(3661));
        $this->assertSame('0s', Runner::formatDuration(-10));
    }
}

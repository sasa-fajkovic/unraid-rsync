<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for Runner::notifyHook (Phase 7) - the per-job notification dispatch.
 *
 * Drives notifyHook directly with a fabricated $job and captures dispatch via an
 * injected Notify::$runner (the fake notify binary is present so available() is
 * true). Asserts:
 *   - dryRun suppresses ALL notifications;
 *   - the FULL gating matrix: each notifyMode x each terminal state -> notify?
 *     and the correct importance;
 *   - the message (subject + description) carries job name, state, exit code;
 *   - notifyHook NEVER throws (a notify failure can't break the run).
 *
 * Notify.php / Runner.php load via the bootstrap.
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
    }

    protected function tearDown(): void
    {
        Notify::$notifyPath = '/usr/local/emhttp/webGui/scripts/notify';
        Notify::$runner     = null;
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

    // --- dry-run suppression -------------------------------------------------

    public function testDryRunSuppressesEvenWithAlways(): void
    {
        foreach ($this->terminalStates() as $state) {
            $this->captured = [];
            Runner::notifyHook($this->job('always'), $state, 0, true);
            $this->assertCount(0, $this->captured, "dry-run must suppress notify for state $state");
        }
    }

    // --- the full gating matrix: mode x state -> notify? ---------------------

    /**
     * @dataProvider gatingMatrixProvider
     */
    public function testGatingMatrix(string $mode, string $state, bool $expectNotify): void
    {
        $this->captured = [];
        Runner::notifyHook($this->job($mode), $state, 0, false);
        $this->assertCount(
            $expectNotify ? 1 : 0,
            $this->captured,
            "mode=$mode state=$state expected notify=" . ($expectNotify ? 'yes' : 'no')
        );
    }

    public function gatingMatrixProvider(): array
    {
        // isSuccess = {SUCCESS, WARNING}; isFailure = {FAILED, PARTIAL, TIMEOUT};
        // ABORTED is neither (only `always` fires on it).
        $S = 'SUCCESS'; $W = 'WARNING'; $P = 'PARTIAL'; $T = 'TIMEOUT'; $F = 'FAILED'; $A = 'ABORTED';
        return [
            // off => never
            'off/SUCCESS'  => ['off', $S, false],
            'off/WARNING'  => ['off', $W, false],
            'off/PARTIAL'  => ['off', $P, false],
            'off/TIMEOUT'  => ['off', $T, false],
            'off/FAILED'   => ['off', $F, false],
            'off/ABORTED'  => ['off', $A, false],

            // success-only => only success states (SUCCESS, WARNING)
            'success/SUCCESS' => ['success-only', $S, true],
            'success/WARNING' => ['success-only', $W, true],
            'success/PARTIAL' => ['success-only', $P, false],
            'success/TIMEOUT' => ['success-only', $T, false],
            'success/FAILED'  => ['success-only', $F, false],
            'success/ABORTED' => ['success-only', $A, false],

            // failure-only => only failure states (FAILED, PARTIAL, TIMEOUT)
            'failure/SUCCESS' => ['failure-only', $S, false],
            'failure/WARNING' => ['failure-only', $W, false],
            'failure/PARTIAL' => ['failure-only', $P, true],
            'failure/TIMEOUT' => ['failure-only', $T, true],
            'failure/FAILED'  => ['failure-only', $F, true],
            'failure/ABORTED' => ['failure-only', $A, false],

            // always => every terminal state INCLUDING ABORTED
            'always/SUCCESS' => ['always', $S, true],
            'always/WARNING' => ['always', $W, true],
            'always/PARTIAL' => ['always', $P, true],
            'always/TIMEOUT' => ['always', $T, true],
            'always/FAILED'  => ['always', $F, true],
            'always/ABORTED' => ['always', $A, true],
        ];
    }

    public function testUnknownNotifyModeNeverNotifies(): void
    {
        foreach ($this->terminalStates() as $state) {
            $this->captured = [];
            Runner::notifyHook($this->job('garbage-mode'), $state, 0, false);
            $this->assertCount(0, $this->captured, "unknown mode must default to off (state $state)");
        }
    }

    // --- importance mapping --------------------------------------------------

    /**
     * @dataProvider importanceProvider
     */
    public function testImportanceMapping(string $state, string $expected): void
    {
        $this->assertSame($expected, Runner::notifyImportance($state));

        // And it actually reaches the dispatched command line under `always`.
        $this->captured = [];
        Runner::notifyHook($this->job('always'), $state, 0, false);
        $this->assertCount(1, $this->captured);
        $this->assertStringContainsString(escapeshellarg('-i'), $this->captured[0]);
        $this->assertStringContainsString(escapeshellarg($expected), $this->captured[0]);
    }

    public function importanceProvider(): array
    {
        return [
            'SUCCESS->normal'  => ['SUCCESS', 'normal'],
            'WARNING->warning' => ['WARNING', 'warning'],
            'PARTIAL->warning' => ['PARTIAL', 'warning'],
            'TIMEOUT->warning' => ['TIMEOUT', 'warning'],
            'FAILED->alert'    => ['FAILED', 'alert'],
            'ABORTED->normal'  => ['ABORTED', 'normal'],
        ];
    }

    // --- message composition -------------------------------------------------

    public function testMessageIncludesJobNameStateAndExitCode(): void
    {
        $this->captured = [];
        Runner::notifyHook($this->job('always', 'photos'), Rsync::STATE_FAILED, 23, false);
        $this->assertCount(1, $this->captured);
        $cmd = $this->captured[0];

        // Subject: "Unraid Rsync: <jobName> <STATE>"
        $this->assertStringContainsString(escapeshellarg('Unraid Rsync: photos FAILED'), $cmd);
        // Event label.
        $this->assertStringContainsString(escapeshellarg('Unraid Rsync'), $cmd);
        // Description carries the job name, state, and exit code.
        $desc = Runner::notifyDescription($this->job('always', 'photos'), Rsync::STATE_FAILED, 23);
        $this->assertStringContainsString('photos', $desc);
        $this->assertStringContainsString('FAILED', $desc);
        $this->assertStringContainsString('23', $desc);
        $this->assertStringContainsString(escapeshellarg($desc), $cmd);
        // Clickable link to the plugin settings page.
        $this->assertStringContainsString(escapeshellarg('/Settings/UnraidRsync'), $cmd);
    }

    public function testDescriptionIncludesDurationWhenSummaryAvailable(): void
    {
        // Write a /boot summary so notifyHook can read the duration.
        $jobId = 'j-withdur';
        Runner::writeSummary($jobId, [
            'state'       => Rsync::STATE_SUCCESS,
            'startedAt'   => '2026-06-13T00:00:00Z',
            'finishedAt'  => '2026-06-13T00:02:05Z',
            'exitCode'    => 0,
            'durationSec' => 125,
            'dryRun'      => false,
        ]);

        $job  = ['id' => $jobId, 'name' => 'withdur', 'notifyMode' => 'always'];
        $desc = Runner::notifyDescription($job, Rsync::STATE_SUCCESS, 0);
        $this->assertStringContainsString('Duration:', $desc);
        $this->assertStringContainsString('2m 5s', $desc);
    }

    public function testDescriptionOmitsDurationWhenNoSummary(): void
    {
        $job  = ['id' => 'j-nodur-' . bin2hex(random_bytes(3)), 'name' => 'nodur'];
        $desc = Runner::notifyDescription($job, Rsync::STATE_SUCCESS, 0);
        $this->assertStringNotContainsString('Duration:', $desc);
    }

    // --- never-throws contract -----------------------------------------------

    public function testNotifyHookNeverThrowsWhenDispatchThrows(): void
    {
        Notify::$runner = function (string $command): int {
            throw new RuntimeException('boom');
        };
        // No exception must escape - the run's finally must be safe.
        Runner::notifyHook($this->job('always'), Rsync::STATE_FAILED, 1, false);
        $this->addToAssertionCount(1);
    }

    public function testNotifyHookNoOpWhenBinaryMissing(): void
    {
        Notify::$notifyPath = '/no/such/notify';
        $this->captured = [];
        Runner::notifyHook($this->job('always'), Rsync::STATE_FAILED, 1, false);
        $this->assertCount(0, $this->captured);
    }

    // --- helper classification -----------------------------------------------

    public function testSuccessAndFailureClassification(): void
    {
        $this->assertTrue(Runner::isSuccessState('SUCCESS'));
        $this->assertTrue(Runner::isSuccessState('WARNING'));
        $this->assertFalse(Runner::isSuccessState('PARTIAL'));
        $this->assertFalse(Runner::isSuccessState('ABORTED'));

        $this->assertTrue(Runner::isFailureState('FAILED'));
        $this->assertTrue(Runner::isFailureState('PARTIAL'));
        $this->assertTrue(Runner::isFailureState('TIMEOUT'));
        $this->assertFalse(Runner::isFailureState('SUCCESS'));
        $this->assertFalse(Runner::isFailureState('ABORTED'));
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

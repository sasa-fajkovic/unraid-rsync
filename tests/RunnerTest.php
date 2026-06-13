<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for Runner.php orchestration, driven with a FAKE rsync (Rsync::$runner)
 * and FAKE hooks (Runner::$hookRunner) so nothing real is spawned. They assert:
 *   - ordering: preHook -> pair(s) -> postHook;
 *   - postHook runs on the FAILURE path (failing rsync) and the preHook-failure
 *     path (pairs skipped, postHook still runs);
 *   - the worst pair exit maps to the final state + the /boot summary is written;
 *   - LOCAL pairs are resolved + argv built via Rsync (no SSH materialisation);
 *   - runtime path-guardrail RE-ENFORCEMENT rejects a /boot dest and --delete
 *     without a sub-dir destination (the run fails before rsync runs).
 *
 * Config is written to the temp UR_CONFIG_BASE; the runtime base (state+logs) is
 * the temp UR_RUNTIME_BASE from bootstrap.php.
 */
final class RunnerTest extends TestCase
{
    /** @var array<int,string> ordered trace of hook/rsync events for assertions */
    private array $trace = [];

    protected function setUp(): void
    {
        $this->trace = [];

        // FAKE hook runner: record the order + which hook, return its scripted code.
        Runner::$hookRunner = function (string $hook, array $env, $onOutput): int {
            // The hook text is "PRE" / "POST" / "PRE:fail" / "POST:fail<code>".
            $this->trace[] = 'hook:' . $hook . ':' . ($env['UR_JOB_STATUS'] ?? '');
            if (strpos($hook, ':fail') !== false) {
                return 7;
            }
            return 0;
        };

        // FAKE rsync: record the call + return a scripted exit code based on the
        // dest operand encoded by the test (default 0).
        Runner::$pidProvider = function (): int {
            return 12345;
        };

        // Reset config to a clean empty install for each test.
        Config::save(Config::defaults());

        // Ensure no leftover state/abort for our test jobs.
        foreach (['j-local', 'j-fail', 'j-pre', 'j-guard', 'j-delete', 'j-multi'] as $jid) {
            RunState::clear($jid);
            RunState::clearAbort($jid);
        }
    }

    protected function tearDown(): void
    {
        Runner::$hookRunner = null;
        Runner::$pidProvider = null;
        Rsync::$runner = null;
    }

    /** Build + persist a LOCAL job, returning its id. */
    private function saveLocalJob(string $id, array $overrides = []): string
    {
        $config = Config::load();
        $job = Config::defaultJob();
        $job['id']        = $id;
        $job['name']      = $id;
        $job['transport'] = 'LOCAL';
        $job['direction'] = 'PUSH';
        $job['pairs']     = [['local' => '/mnt/user/src/', 'remote' => '/mnt/disk1/dst/']];
        $job = array_merge($job, $overrides);
        $config['jobs'][] = $job;
        Config::save($config);
        return $id;
    }

    public function testHappyPathOrderingPreThenPairsThenPost(): void
    {
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            $this->trace[] = 'rsync';
            $onOutput("transferring...\n");
            return 0;
        };

        $id = $this->saveLocalJob('j-local', ['preHook' => 'PRE', 'postHook' => 'POST']);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertSame(0, $res['exitCode']);
        $this->assertSame(1, $rsyncCalls);
        // Ordering: pre hook, then rsync, then post hook.
        $this->assertSame(['hook:PRE:', 'rsync', 'hook:POST:SUCCESS'], $this->trace);

        // Summary persisted to /boot with the right state.
        $summary = Runner::readSummary($id);
        $this->assertNotNull($summary);
        $this->assertSame(Rsync::STATE_SUCCESS, $summary['state']);
        $this->assertSame(0, $summary['exitCode']);
        $this->assertFalse($summary['dryRun']);

        // Running state cleared after the run.
        $this->assertFalse(RunState::isRunning($id));
    }

    public function testPostHookRunsOnRsyncFailure(): void
    {
        Rsync::$runner = function (array $argv, $onOutput): int {
            $this->trace[] = 'rsync';
            return 12; // -> FAILED
        };
        $id = $this->saveLocalJob('j-fail', ['preHook' => 'PRE', 'postHook' => 'POST']);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame(12, $res['exitCode']);
        // postHook STILL ran, and saw the FAILED status in its env.
        $this->assertContains('hook:POST:FAILED', $this->trace);
        // Order: pre, rsync, post.
        $this->assertSame(['hook:PRE:', 'rsync', 'hook:POST:FAILED'], $this->trace);
        $this->assertSame(Rsync::STATE_FAILED, Runner::readSummary($id)['state']);
    }

    public function testPreHookFailureSkipsPairsButRunsPostHook(): void
    {
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };
        $id = $this->saveLocalJob('j-pre', ['preHook' => 'PRE:fail', 'postHook' => 'POST']);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('prehook-failed', $res['reason'] ?? '');
        $this->assertSame(0, $rsyncCalls, 'pairs are skipped when the pre-hook fails');
        // postHook still ran on the failure path.
        $this->assertContains('hook:POST:FAILED', $this->trace);
    }

    public function testWorstOfMultiplePairExits(): void
    {
        $codes = [0, 24, 12]; // SUCCESS, WARNING, FAILED -> worst FAILED(12)
        $i = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$i, $codes): int {
            return $codes[$i++] ?? 0;
        };
        $id = $this->saveLocalJob('j-multi', [
            'pairs' => [
                ['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/'],
                ['local' => '/mnt/user/b/', 'remote' => '/mnt/disk1/b/'],
                ['local' => '/mnt/user/c/', 'remote' => '/mnt/disk1/c/'],
            ],
        ]);
        $res = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame(12, $res['exitCode']);
    }

    public function testDryRunFlagThreadedToRsyncAndSummary(): void
    {
        $sawDryRun = false;
        Rsync::$runner = function (array $argv, $onOutput) use (&$sawDryRun): int {
            $sawDryRun = in_array('--dry-run', $argv, true);
            return 0;
        };
        $id = $this->saveLocalJob('j-local');
        $res = Runner::run($id, true);
        $this->assertTrue($sawDryRun, 'rsync argv should carry --dry-run on a dry run');
        $this->assertTrue(Runner::readSummary($id)['dryRun']);
    }

    public function testRuntimeGuardrailRejectsBootDestination(): void
    {
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };
        // A hand-edited config could put /boot as the LOCAL dest; the runner must
        // re-enforce the guardrail and refuse before rsync runs.
        $id = $this->saveLocalJob('j-guard', [
            'pairs' => [['local' => '/mnt/user/src/', 'remote' => '/boot']],
        ]);
        $res = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('guardrail', $res['reason'] ?? '');
        $this->assertSame(0, $rsyncCalls, 'rsync must NOT run for a guardrail-rejected pair');
    }

    public function testGuardrailErrorsRejectsDeleteWithoutSubdirRemote(): void
    {
        // The --delete "destination must be a specific sub-dir" rule is most
        // meaningful for an SSH remote dest (PUSH -> remote). Exercised here as a
        // pure unit (no run): a remote dest of "/" with --delete is rejected.
        $job = Config::defaultJob();
        $job['transport'] = 'SSH';
        $job['direction'] = 'PUSH';
        $opts = Config::mergeRsyncOptions(['delete' => true]);
        $errors = Runner::guardrailErrors(
            $job,
            ['local' => '/mnt/user/src/', 'remote' => '/'],
            'SSH',
            $opts
        );
        $joined = implode(' ', $errors);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('sub-directory', $joined);
    }

    public function testGuardrailErrorsCleanRemotePassesWithDelete(): void
    {
        $job = Config::defaultJob();
        $job['transport'] = 'SSH';
        $job['direction'] = 'PUSH';
        $opts = Config::mergeRsyncOptions(['delete' => true]);
        $errors = Runner::guardrailErrors(
            $job,
            ['local' => '/mnt/user/src/', 'remote' => '/data/backup/'],
            'SSH',
            $opts
        );
        $this->assertSame([], $errors);
    }

    public function testResolvePairSshPushAndPull(): void
    {
        $job = Config::defaultJob();
        $job['transport'] = 'SSH';

        $job['direction'] = 'PUSH';
        $push = Runner::resolvePair($job, ['local' => '/mnt/user/m/', 'remote' => '/data/m/'], 'SSH', 'sasa@rpi');
        $this->assertSame('/mnt/user/m/', $push['src']);
        $this->assertSame('sasa@rpi:/data/m/', $push['dest']);

        $job['direction'] = 'PULL';
        $pull = Runner::resolvePair($job, ['local' => '/mnt/user/m/', 'remote' => '/data/m/'], 'SSH', 'sasa@rpi');
        $this->assertSame('sasa@rpi:/data/m/', $pull['src']);
        $this->assertSame('/mnt/user/m/', $pull['dest']);
    }

    public function testGuardrailDeleteDestForLocalUsesRemoteSideRegardlessOfDirection(): void
    {
        // A hand-edited LOCAL job with direction=PULL must still treat the
        // `remote` field as the destination (resolvePair coerces LOCAL to PUSH),
        // so the --delete sub-dir check targets the side rsync actually writes.
        $job = Config::defaultJob();
        $job['transport'] = 'LOCAL';
        $job['direction'] = 'PULL'; // spurious for LOCAL
        $opts = Config::mergeRsyncOptions(['delete' => true]);

        // remote dest "/" -> should be flagged (it is the real destination).
        $errors = Runner::guardrailErrors(
            $job,
            ['local' => '/mnt/user/src/', 'remote' => '/'],
            'LOCAL',
            $opts
        );
        // "/" also fails checkLocalPath, but the delete-subdir message must be
        // among the errors targeting the REMOTE (dest) side, not the local side.
        $joined = implode(' ', $errors);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('sub-directory', $joined);
    }

    public function testResolvePairLocalIgnoresDirection(): void
    {
        $job = Config::defaultJob();
        $job['transport'] = 'LOCAL';
        $job['direction'] = 'PUSH';
        $r = Runner::resolvePair($job, ['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/'], 'LOCAL');
        $this->assertSame('/mnt/user/a/', $r['src']);
        $this->assertSame('/mnt/disk1/a/', $r['dest']);
    }

    public function testLocalArgvHasNoSshTransport(): void
    {
        $seenArgv = null;
        Rsync::$runner = function (array $argv, $onOutput) use (&$seenArgv): int {
            $seenArgv = $argv;
            return 0;
        };
        $id = $this->saveLocalJob('j-local');
        Runner::run($id, false);
        $this->assertNotNull($seenArgv);
        $this->assertSame('rsync', $seenArgv[0], 'LOCAL: rsync is the program, no sshpass prefix');
        $this->assertNotContains('-e', $seenArgv, 'LOCAL: no -e transport');
        // operands resolved local -> remote (both local paths) after --.
        $dd = array_search('--', $seenArgv, true);
        $this->assertSame('/mnt/user/src/', $seenArgv[$dd + 1]);
        $this->assertSame('/mnt/disk1/dst/', $seenArgv[$dd + 2]);
    }

    public function testJobNotFoundIsHardFail(): void
    {
        $res = Runner::run('j-does-not-exist', false);
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('error', $res['reason'] ?? '');
    }

    public function testNoPairsFails(): void
    {
        Rsync::$runner = function (array $argv, $onOutput): int {
            return 0;
        };
        $id = $this->saveLocalJob('j-local', ['pairs' => []]);
        $res = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('no-pairs', $res['reason'] ?? '');
    }

    public function testAbortBetweenPairsYieldsAbortedAndStopsRemainingPairs(): void
    {
        // The runner clears any STALE abort flag at run start (so a leftover flag
        // from a previous run can't poison a fresh one) and polls it BEFORE each
        // pair. So we request the abort from inside the first pair's rsync; the
        // SECOND pair's pre-loop poll then sees it and stops the run.
        $id = $this->saveLocalJob('j-multi', [
            'postHook' => 'POST',
            'pairs'    => [
                ['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/'],
                ['local' => '/mnt/user/b/', 'remote' => '/mnt/disk1/b/'],
            ],
        ]);
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls, $id): int {
            $rsyncCalls++;
            if ($rsyncCalls === 1) {
                RunState::requestAbort($id); // user clicks Abort during pair #1
            }
            return 0;
        };
        $res = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_ABORTED, $res['state']);
        $this->assertSame(143, $res['exitCode']);
        $this->assertSame(1, $rsyncCalls, 'only pair #1 ran; the abort stopped pair #2');
        // postHook still ran, seeing ABORTED.
        $this->assertContains('hook:POST:ABORTED', $this->trace);
        // The abort flag is cleared at the end of the run.
        $this->assertFalse(RunState::abortRequested($id));
    }

    public function testStaleAbortFlagIsClearedAtRunStart(): void
    {
        // A leftover abort flag from a prior run must NOT abort a fresh run.
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };
        $id = $this->saveLocalJob('j-local');
        RunState::requestAbort($id); // stale flag from "before"
        $res = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertSame(1, $rsyncCalls, 'a stale abort flag is cleared at run start');
    }

    public function testRunLockIsReleasedSoSubsequentRunSucceeds(): void
    {
        Rsync::$runner = function (array $argv, $onOutput): int {
            return 0;
        };
        $id = $this->saveLocalJob('j-local');
        $r1 = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_SUCCESS, $r1['state']);
        // A second run must succeed too - proving the lock was released in finally.
        $r2 = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_SUCCESS, $r2['state']);
    }

    public function testRunRefusedWhileLockHeld(): void
    {
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };
        $id = $this->saveLocalJob('j-local');
        // Hold the per-job lock externally (simulating a concurrent live runner).
        $held = RunState::acquireLock($id);
        $this->assertIsResource($held);
        try {
            $res = Runner::run($id, false);
            $this->assertSame('already-running', $res['reason'] ?? '');
            $this->assertSame(0, $rsyncCalls, 'no rsync runs while the lock is held');
        } finally {
            RunState::releaseLock($held);
        }
    }

    public function testRunnerCliArgParsing(): void
    {
        // Require the CLI without executing its main block (UR_RUNNER_TESTING).
        if (!defined('UR_RUNNER_TESTING')) {
            define('UR_RUNNER_TESTING', true);
        }
        require_once __DIR__ . '/../source/scripts/runner.php';

        $this->assertSame(
            ['job' => 'j-music', 'dryRun' => false],
            ur_runner_parse_args(['runner.php', '--job=j-music'])
        );
        $this->assertSame(
            ['job' => 'j-music', 'dryRun' => true],
            ur_runner_parse_args(['runner.php', '--job=j-music', '--dry-run'])
        );
        // --job <id> (space form) also accepted.
        $this->assertSame(
            ['job' => 'j-x', 'dryRun' => false],
            ur_runner_parse_args(['runner.php', '--job', 'j-x'])
        );
        // No job.
        $this->assertSame('', ur_runner_parse_args(['runner.php'])['job']);
    }

    public function testRunnerCliExitCodeMapping(): void
    {
        if (!defined('UR_RUNNER_TESTING')) {
            define('UR_RUNNER_TESTING', true);
        }
        require_once __DIR__ . '/../source/scripts/runner.php';

        $this->assertSame(0, ur_runner_exit_code(Rsync::STATE_SUCCESS));
        $this->assertSame(0, ur_runner_exit_code(Rsync::STATE_WARNING));
        $this->assertSame(0, ur_runner_exit_code(Rsync::STATE_PARTIAL));
        $this->assertSame(143, ur_runner_exit_code(Rsync::STATE_ABORTED));
        $this->assertSame(1, ur_runner_exit_code(Rsync::STATE_FAILED));
        $this->assertSame(1, ur_runner_exit_code(Rsync::STATE_TIMEOUT));
    }
}

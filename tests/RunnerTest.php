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

        // Point the rsync presence check at an executable that always exists in
        // the test environment (the PHP binary), so rsyncAvailable() is true and
        // the run path proceeds independently of the host's /usr/bin/rsync. The
        // missing-rsync path is exercised explicitly in testRunFailsWhenRsyncMissing.
        Rsync::$rsyncPathOverride = PHP_BINARY;

        // Reset config to a clean empty install for each test.
        Config::save(Config::defaults());

        // Ensure no leftover state/abort for our test jobs.
        foreach (['j-local', 'j-fail', 'j-pre', 'j-guard', 'j-delete', 'j-multi', 'j-redact'] as $jid) {
            RunState::clear($jid);
            RunState::clearAbort($jid);
        }
    }

    protected function tearDown(): void
    {
        Runner::$hookRunner = null;
        Runner::$pidProvider = null;
        Rsync::$runner = null;
        Rsync::$rsyncPathOverride = null;
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

    public function testSshKeyfileJobFailsWhenKeyFileMissing(): void
    {
        // An SSH job using a KEYFILE connection whose key file does NOT exist
        // must fail the run BEFORE any rsync, with the clear run-time message
        // (no rsync call, postHook still runs, FAILED state + summary).
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };

        $origBase = Ssh::$runtimeBase;
        $rt = sys_get_temp_dir() . '/ur-runner-ssh-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Ssh::$runtimeBase = $rt;

        // Seed a KEYFILE connection pointing at a non-existent key file.
        $creds = Credentials::defaults();
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-kf', 'name' => 'kf', 'host' => 'h.example', 'username' => 'root',
            'authMethod' => 'KEYFILE', 'keyFilePath' => $rt . '/absent/id_ed25519',
        ]);
        Credentials::save($creds);

        // An SSH job referencing that connection.
        $config = Config::load();
        $job = Config::defaultJob();
        $job['id']           = 'j-kf';
        $job['name']         = 'j-kf';
        $job['transport']    = 'SSH';
        $job['direction']    = 'PUSH';
        $job['connectionId'] = 'c-kf';
        $job['pairs']        = [['local' => '/mnt/user/src/', 'remote' => '/data/dst/']];
        $job['postHook']     = 'POST';
        $config['jobs'][]    = $job;
        Config::save($config);
        RunState::clear('j-kf');
        RunState::clearAbort('j-kf');

        try {
            $res = Runner::run('j-kf', false);
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            if (is_dir($rt)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($rt, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($rt);
            }
        }

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame(0, $rsyncCalls, 'rsync must NOT run when the key file is missing');
        // postHook still ran on the failure path.
        $this->assertContains('hook:POST:FAILED', $this->trace);
        // The clear run-time message landed in the run log.
        $log = @file_get_contents($res['runLog']);
        $this->assertIsString($log);
        $this->assertStringContainsString('not found or unreadable', $log);
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
        // argv[0] is the RESOLVED rsync binary (here the test override), proving
        // the run uses the same path the presence check validates - not a bare
        // "rsync" resolved via PATH. No sshpass prefix on a LOCAL job.
        $this->assertSame(Rsync::rsyncPath(), $seenArgv[0], 'LOCAL: the resolved rsync binary is the program, no sshpass prefix');
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

    public function testAbortDuringSinglePairYieldsAborted(): void
    {
        // THE live case: a SINGLE-pair job aborted mid-rsync. There is no "next
        // pair" pre-loop poll, so the POST-pair abort check is what records
        // ABORTED. The killed rsync returns a non-zero code (here 255, as seen
        // live when the abort tore down the ssh transport); without the post-pair
        // check that would read as FAILED (or, when the runner died on the group
        // SIGTERM, leave the previous run's stale state).
        $id = $this->saveLocalJob('j-single', [
            'postHook' => 'POST',
            'pairs'    => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']],
        ]);
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls, $id): int {
            $rsyncCalls++;
            RunState::requestAbort($id); // abort lands during the only pair
            return 255;                  // rsync killed by the abort signal
        };
        $res = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_ABORTED, $res['state'], 'a single-pair abort must record ABORTED, not FAILED');
        $this->assertSame(143, $res['exitCode']);
        $this->assertSame(1, $rsyncCalls);
        $this->assertContains('hook:POST:ABORTED', $this->trace); // postHook sees ABORTED
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

    public function testRunFailsWhenRsyncMissing(): void
    {
        // Simulate a broken system with no rsync binary: the run must fail
        // cleanly (FAILED + rsync-missing reason) WITHOUT running any rsync, and
        // the post-hook still fires.
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };
        Rsync::$rsyncPathOverride = '/nonexistent/path/to/rsync';

        $id  = $this->saveLocalJob('j-local', ['postHook' => 'POST']);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('rsync-missing', $res['reason'] ?? '');
        $this->assertSame(0, $rsyncCalls, 'no rsync runs when the binary is missing');
        // postHook still ran on this failure path, seeing FAILED.
        $this->assertContains('hook:POST:FAILED', $this->trace);
        // The /boot summary records the failure.
        $this->assertSame(Rsync::STATE_FAILED, Runner::readSummary($id)['state']);
        // And the run log carries the clear, install-free guidance.
        $log = @file_get_contents($res['runLog']);
        $this->assertIsString($log);
        $this->assertStringContainsString('rsync not found', $log);
        $this->assertStringContainsString('misconfigured', $log);
    }

    public function testRunProceedsWhenRsyncPresent(): void
    {
        // The companion to the missing case: with rsync present (the setUp
        // override points at an executable), the run proceeds and rsync runs.
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };
        $this->assertTrue(Rsync::rsyncAvailable(), 'setUp points rsyncPath at an executable');

        $id  = $this->saveLocalJob('j-local');
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertSame(1, $rsyncCalls, 'rsync runs when the binary is present');
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

    // =====================================================================
    // GAP-FILL: rsync exit code -> run state mapped through the FULL Runner::run
    // path (not just the pure Rsync::exitToState unit). Proves a single pair's
    // non-zero-but-non-fatal exit (vanished files / partial / I/O timeout) is
    // threaded all the way into the run's result state + the /boot summary, with
    // the run COMPLETING (not hard-failing) so the pair loop and finally behave.
    // =====================================================================

    /**
     * @dataProvider singlePairExitToStateProvider
     */
    public function testSinglePairExitMapsToRunStateThroughRun(int $exitCode, string $expectedState): void
    {
        Rsync::$runner = function (array $argv, $onOutput) use ($exitCode): int {
            return $exitCode;
        };
        $id  = $this->saveLocalJob('j-local');
        $res = Runner::run($id, false);

        $this->assertSame($expectedState, $res['state'], "exit $exitCode -> $expectedState via Runner::run");
        $this->assertSame($exitCode, $res['exitCode']);
        // The /boot summary records the SAME state the result reports.
        $summary = Runner::readSummary($id);
        $this->assertNotNull($summary);
        $this->assertSame($expectedState, $summary['state']);
        $this->assertSame($exitCode, $summary['exitCode']);
    }

    public function singlePairExitToStateProvider(): array
    {
        // Mirrors Rsync::exitToState, but asserted END-TO-END through Runner::run.
        return [
            'success-0'    => [0, Rsync::STATE_SUCCESS],
            'vanished-24'  => [24, Rsync::STATE_WARNING],
            'maxdelete-25' => [25, Rsync::STATE_WARNING],
            'partial-23'   => [23, Rsync::STATE_PARTIAL],
            'iotimeout-30' => [30, Rsync::STATE_TIMEOUT],
            'contimeout-35'=> [35, Rsync::STATE_TIMEOUT],
            'rsyncterm-20' => [20, Rsync::STATE_ABORTED],
            'failed-12'    => [12, Rsync::STATE_FAILED],
        ];
    }

    public function testEarlyFailingPairStillRunsLaterPairsAndAggregatesWorst(): void
    {
        // A NON-ZERO rsync exit on an EARLY pair does NOT short-circuit the run
        // (unlike a guardrail/prehook hard-fail): rsync runs for EVERY pair and
        // the run reports the worst-of across them. Here pair #1 fails (12), but
        // pair #2 still runs and pair #3 is ABORTED-coded (20); ABORTED outranks
        // FAILED, so the aggregate is ABORTED carrying code 20.
        $codes = [12, 0, 20]; // FAILED, SUCCESS, ABORTED
        $i = 0;
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$i, &$rsyncCalls, $codes): int {
            $rsyncCalls++;
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

        $this->assertSame(3, $rsyncCalls, 'an early non-zero rsync exit must NOT skip later pairs');
        $this->assertSame(Rsync::STATE_ABORTED, $res['state'], 'worst-of across pairs (ABORTED > FAILED)');
        $this->assertSame(20, $res['exitCode'], 'the worst pair\'s code is carried');
    }

    public function testPostHookFailureDowngradesCleanRunToWarning(): void
    {
        // A failing postHook must NOT override a real failure, but on an OTHERWISE
        // CLEAN run it surfaces as WARNING (reason posthook-failed) so it is not
        // silent. rsync succeeds; only the postHook returns non-zero.
        Rsync::$runner = function (array $argv, $onOutput): int {
            return 0; // clean rsync
        };
        $id  = $this->saveLocalJob('j-local', ['postHook' => 'POST:fail']);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_WARNING, $res['state']);
        $this->assertSame('posthook-failed', $res['reason'] ?? '');
        // The exit code stays the rsync exit code (0); the downgrade is in STATE.
        $this->assertSame(0, $res['exitCode']);
        // The summary records WARNING.
        $this->assertSame(Rsync::STATE_WARNING, Runner::readSummary($id)['state']);
        // Importance for the surfaced WARNING maps to "warning" (sanity tie-in).
        $this->assertSame(Notify::IMPORTANCE_WARNING, Runner::notifyImportance($res['state']));
    }

    public function testPostHookFailureDoesNotOverrideARealFailure(): void
    {
        // When rsync ALREADY failed, a failing postHook must leave the state
        // FAILED (a posthook problem can't "downgrade" a real failure to WARNING
        // nor mask it). The companion to the WARNING-downgrade case above.
        Rsync::$runner = function (array $argv, $onOutput): int {
            return 12; // FAILED
        };
        $id  = $this->saveLocalJob('j-fail', ['postHook' => 'POST:fail']);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame(12, $res['exitCode']);
        $this->assertNotSame('posthook-failed', $res['reason'] ?? '');
    }

    public function testDryRunSuppressesNotificationThroughFullRun(): void
    {
        // notifyHook's dry-run suppression is unit-tested directly; here we prove
        // it END-TO-END: a DRY run with notifyMode=always dispatches NO notify
        // through the real Runner::run finally. We capture via Notify's seam.
        $fakeBin = sys_get_temp_dir() . '/ur-rnotify-dry-' . getmypid() . '-' . bin2hex(random_bytes(4));
        file_put_contents($fakeBin, "#!/bin/sh\nexit 0\n");
        chmod($fakeBin, 0755);
        $origPath = Notify::$notifyPath;
        $captured = [];
        Notify::$notifyPath = $fakeBin;
        Notify::$runner = function (string $command) use (&$captured): int {
            $captured[] = $command;
            return 0;
        };

        Rsync::$runner = function (array $argv, $onOutput): int {
            return 0;
        };

        try {
            $id = $this->saveLocalJob('j-local', ['notifyMode' => 'always']);
            $res = Runner::run($id, true); // DRY run
            $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
            $this->assertCount(0, $captured, 'a dry run must never notify, even with notifyMode=always');
        } finally {
            Notify::$notifyPath = $origPath;
            Notify::$runner = null;
            @unlink($fakeBin);
        }
    }

    public function testNonDryRunDoesNotifyThroughFullRun(): void
    {
        // Companion to the dry-run case: a NON-dry run with notifyMode=always DOES
        // dispatch exactly one notification through the real Runner::run finally,
        // proving the suppression above is specific to dry-run (not a dead seam).
        $fakeBin = sys_get_temp_dir() . '/ur-rnotify-live-' . getmypid() . '-' . bin2hex(random_bytes(4));
        file_put_contents($fakeBin, "#!/bin/sh\nexit 0\n");
        chmod($fakeBin, 0755);
        $origPath = Notify::$notifyPath;
        $captured = [];
        Notify::$notifyPath = $fakeBin;
        Notify::$runner = function (string $command) use (&$captured): int {
            $captured[] = $command;
            return 0;
        };

        Rsync::$runner = function (array $argv, $onOutput): int {
            return 0;
        };

        try {
            $id = $this->saveLocalJob('j-local', ['notifyMode' => 'always']);
            $res = Runner::run($id, false); // LIVE run
            $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
            $this->assertCount(1, $captured, 'a live run with notifyMode=always notifies exactly once');
        } finally {
            Notify::$notifyPath = $origPath;
            Notify::$runner = null;
            @unlink($fakeBin);
        }
    }

    public function testRedactionArmedBeforeRsyncOutputIsCapturedOnSshRun(): void
    {
        // F1 invariant at the RUNNER integration level: secret-path redaction is
        // armed at SSH materialisation, BEFORE any rsync output is captured. We
        // drive a real SSH KEY-auth run (a managed key materialised to a temp
        // tmpfs base) with a FAKE rsync that echoes the tmpfs key PATH (as `rsync
        // -vvv` would when it prints the remote-shell command). If redaction were
        // armed after capture, the path would leak into the root-written,
        // browser-visible run log; it must be scrubbed.
        $rsyncCalls = 0;
        $seenDashE  = null;
        $emittedKey = null;

        $origBase = Ssh::$runtimeBase;
        $rt = sys_get_temp_dir() . '/ur-runner-redact-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Ssh::$runtimeBase = $rt;

        // Seed a managed KEY + a connection that uses it.
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id'          => 'k-1',
            'name'        => 'k1',
            'privateKey'  => "-----BEGIN OPENSSH PRIVATE KEY-----\nFAKEKEYMATERIAL\n-----END OPENSSH PRIVATE KEY-----\n",
            'publicKey'   => 'ssh-ed25519 AAAA fake',
            'fingerprint' => 'SHA256:fake',
        ];
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-key', 'name' => 'ckey', 'host' => 'h.example', 'username' => 'root',
            'authMethod' => 'KEY', 'keyId' => 'k-1', 'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
        ]);
        Credentials::save($creds);

        // The fake rsync: capture the -e value (carries the tmpfs key path) and
        // EMIT that path through the sink, as a debug-level rsync would.
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls, &$seenDashE, &$emittedKey): int {
            $rsyncCalls++;
            $eIdx = array_search('-e', $argv, true);
            $seenDashE = ($eIdx !== false) ? (string) $argv[$eIdx + 1] : '';
            // The -e value is the escapeshellarg'd ssh argv: '-i' '<keypath>' ...
            // Pull the materialised key path out so we can assert on EXACTLY the
            // path the run armed for redaction.
            if (preg_match("#'-i'\\s+'([^']+)'#", $seenDashE, $m)) {
                $emittedKey = $m[1];
            }
            $onOutput('opening connection using: ' . $seenDashE . "\n");
            return 0;
        };

        $config = Config::load();
        $job = Config::defaultJob();
        $job['id']           = 'j-redact';
        $job['name']         = 'j-redact';
        $job['transport']    = 'SSH';
        $job['direction']    = 'PUSH';
        $job['connectionId'] = 'c-key';
        $job['logLevel']     = 'debug';
        $job['pairs']        = [['local' => '/mnt/user/src/', 'remote' => '/data/dst/']];
        $config['jobs'][]    = $job;
        Config::save($config);
        RunState::clear('j-redact');
        RunState::clearAbort('j-redact');

        try {
            $res = Runner::run('j-redact', false);
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            if (is_dir($rt)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($rt, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($rt);
            }
        }

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertSame(1, $rsyncCalls, 'the SSH run reached rsync');
        $this->assertNotNull($emittedKey, 'the fake rsync saw a -i <tmpfs key path> in -e');
        $this->assertStringContainsString($rt, (string) $emittedKey, 'the key path is under the per-run tmpfs base');

        // The key path the run EMITTED must be scrubbed from the run log: proof
        // that setRedaction() was armed BEFORE the rsync output was captured.
        $log = @file_get_contents($res['runLog']);
        $this->assertIsString($log);
        $this->assertStringNotContainsString((string) $emittedKey, $log, 'the tmpfs key path must be redacted from the run log');
        // The redaction placeholder is present where the path was.
        $this->assertStringContainsString('opening connection using:', $log, 'the captured line itself still landed (redacted)');
        // Redaction is disarmed after the run (finally), so a later plain write
        // of that same string is NOT scrubbed (no leaked global state).
        $this->assertSame((string) $emittedKey, Logger::redact((string) $emittedKey), 'redaction is disarmed after the run');
    }

    public function testRunnerCliExitCodeAgreesWithRunnerStateMatrix(): void
    {
        // CLI<->Runner consistency: for every terminal STATE the runner can
        // produce, runner.php's ur_runner_exit_code() must agree with the runner's
        // own result.exitCode CONTRACT (0 for completed runs, 1 for failures, 143
        // for abort). This ties the thin CLI's mapping to the orchestrator so they
        // can't silently drift.
        if (!defined('UR_RUNNER_TESTING')) {
            define('UR_RUNNER_TESTING', true);
        }
        require_once __DIR__ . '/../source/scripts/runner.php';

        // SUCCESS / WARNING / PARTIAL / TIMEOUT all mean "the run completed" -> 0,
        // FAILED -> 1, ABORTED -> 143. Drive a single-pair run per state and check
        // ur_runner_exit_code(result.state) is the documented process code.
        $cases = [
            0  => 0,    // SUCCESS  -> exit 0
            24 => 0,    // WARNING  -> exit 0
            23 => 0,    // PARTIAL  -> exit 0
            30 => 1,    // TIMEOUT  -> exit 1
            12 => 1,    // FAILED   -> exit 1
        ];
        foreach ($cases as $rsyncExit => $expectedCli) {
            Rsync::$runner = function (array $argv, $onOutput) use ($rsyncExit): int {
                return $rsyncExit;
            };
            RunState::clear('j-local');
            RunState::clearAbort('j-local');
            $config = Config::load();
            // remove any prior j-local so saveLocalJob doesn't duplicate-collide
            $config['jobs'] = array_values(array_filter(
                $config['jobs'] ?? [],
                static fn($j) => !is_array($j) || (string) ($j['id'] ?? '') !== 'j-local'
            ));
            Config::save($config);
            $id  = $this->saveLocalJob('j-local');
            $res = Runner::run($id, false);
            $this->assertSame(
                $expectedCli,
                ur_runner_exit_code((string) $res['state']),
                "rsync exit $rsyncExit -> state {$res['state']} -> CLI exit $expectedCli"
            );
        }
    }
}

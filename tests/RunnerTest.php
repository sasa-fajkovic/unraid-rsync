<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

        // Reset the secrets-dir override so a leak can't redirect Credentials I/O.
        Credentials::$secretsDirOverride = null;

        // Ensure no leftover state/abort for our test jobs.
        foreach (['j-local', 'j-fail', 'j-pre', 'j-guard', 'j-delete', 'j-multi', 'j-redact', 'j-secrets'] as $jid) {
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
        Credentials::$secretsDirOverride = null;
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

    /**
     * Logger::sink() holds progress redraws back (5%/30s) and buffers an
     * unterminated tail, so the Runner MUST flush it once rsync has exited -
     * otherwise the final percentage and any newline-less last line would be
     * silently dropped from the run log. This is the wiring test for that.
     */
    public function testRunnerFlushesTheSinkSoTheFinalProgressLineReachesTheLog(): void
    {
        Rsync::$runner = function (array $argv, $onOutput): int {
            $this->trace[] = 'rsync';
            // A realistic --info=progress2 burst: bare-\r redraws, the last of
            // them inside the throttle window, and no trailing newline at all.
            foreach ([0, 1, 2, 3, 99, 100] as $pct) {
                $onOutput("\r" . sprintf('%12s %3d%%   2.93MB/s    0:00:01  ', number_format($pct * 419430), $pct));
            }
            $onOutput('sent 41,953,721 bytes  received 146 bytes');
            return 0;
        };

        $id  = $this->saveLocalJob('j-flush');
        $res = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);

        $log = (string) @file_get_contents($res['runLog']);
        $this->assertStringContainsString('sent 41,953,721 bytes', $log, 'the unterminated tail must be flushed');
        $this->assertStringNotContainsString("\r", $log, 'no bare CR may reach the run log');
        // The sub-5% redraws were dropped; 0% and 100% survive.
        $this->assertStringContainsString('  0%', $log);
        $this->assertStringContainsString('100%', $log);
        $this->assertStringNotContainsString('  2%', $log);
    }

    /**
     * The `finally` flush has to happen BEFORE the postHook. runHook() opens a
     * new sink on the same run log and Logger::sink() RESETS the per-path state,
     * so a flush placed after the hook finds nothing left to rescue. The pair's
     * own flush covers every normal and abort path; this is the case that needs
     * the outer one - output captured, then a throw before that flush.
     */
    public function testAnUnterminatedTailSurvivesAThrowOnAJobWithAPostHook(): void
    {
        Rsync::$runner = function (array $argv, $onOutput): int {
            $this->trace[] = 'rsync';
            $onOutput("\r  41,943,046 100%   7.80MB/s    0:00:05");
            $onOutput('rsync: partial line with no trailing newline');
            throw new RuntimeException('boom');
        };

        $id  = $this->saveLocalJob('j-posthook-flush', ['postHook' => 'POST']);
        $res = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);

        $log = (string) @file_get_contents($res['runLog']);
        $this->assertStringContainsString('partial line with no trailing newline', $log);
        $this->assertStringContainsString('Post-run hook exited', $log, 'the postHook still ran');
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

    public function testRunThreadsTriggerIntoSummary(): void
    {
        Rsync::$runner = fn(array $a, $o): int => 0;
        $id = $this->saveLocalJob('j-trig');

        // schedule trigger lands in the persisted summary.
        Runner::run($id, false, 'schedule');
        $this->assertSame('schedule', Runner::readSummary($id)['trigger']);

        // default trigger is manual; a bogus value is clamped to manual.
        Runner::run($id, false);
        $this->assertSame('manual', Runner::readSummary($id)['trigger']);
        Runner::run($id, false, 'bogus');
        $this->assertSame('manual', Runner::readSummary($id)['trigger']);
    }

    public function testHookEnvIncludesTrigger(): void
    {
        $this->assertSame('schedule', Runner::hookEnv([], 'j-x', false, null, null, 'schedule')['UR_TRIGGER']);
        $this->assertSame('manual', Runner::hookEnv([], 'j-x', false)['UR_TRIGGER']);
        $this->assertSame('manual', Runner::hookEnv([], 'j-x', false, null, null, 'bogus')['UR_TRIGGER']);
    }

    public function testRetentionSettingPrunesHistoryAndLogs(): void
    {
        Rsync::$runner = fn(array $a, $o): int => 0;
        // Persist a small retention, then run more times than that.
        $id = $this->saveLocalJob('j-ret');
        $cfg = Config::load();
        $cfg['global']['retention'] = 2;
        Config::save($cfg);
        try {
            for ($i = 0; $i < 4; $i++) {
                Runner::run($id, false);
            }
            // The retention setting (2) prunes BOTH stores: at most 2 history
            // records and at most 2 tmpfs run logs survive.
            $this->assertSame(2, History::list($id, 0, 25)['total']);
            $this->assertLessThanOrEqual(2, count(Logger::listRuns($id, 100)));
        } finally {
            @unlink(History::path($id));
        }
    }

    public function testRunAppendsHistoryRecord(): void
    {
        Rsync::$runner = fn(array $a, $o): int => 0;
        $id = $this->saveLocalJob('j-histrun');
        try {
            Runner::run($id, true, 'schedule'); // dry-run + scheduled
            $page = History::list($id, 0, 25);
            $this->assertSame(1, $page['total']);
            $r = $page['runs'][0];
            $this->assertSame(Rsync::STATE_SUCCESS, $r['state']);
            $this->assertTrue($r['dryRun']);
            $this->assertSame('schedule', $r['trigger']);
            // logRef is a run-log basename that resolves back via runLogPathById.
            $this->assertStringStartsWith('run-', $r['logRef']);
            $this->assertNotNull(Logger::runLogPathById($id, $r['logRef']));
        } finally {
            @unlink(History::path($id));
        }
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
        // "rsync" resolved via PATH. Nothing wraps rsync on a LOCAL job.
        $this->assertSame(Rsync::rsyncPath(), $seenArgv[0], 'LOCAL: the resolved rsync binary is the program');
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
        $this->assertSame('job-not-found', $res['reason'] ?? '');
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

        // trigger defaults to 'manual'.
        $this->assertSame(
            ['job' => 'j-music', 'dryRun' => false, 'trigger' => 'manual'],
            ur_runner_parse_args(['runner.php', '--job=j-music'])
        );
        $this->assertSame(
            ['job' => 'j-music', 'dryRun' => true, 'trigger' => 'manual'],
            ur_runner_parse_args(['runner.php', '--job=j-music', '--dry-run'])
        );
        // --trigger=schedule is parsed; --job <id> (space form) also accepted.
        $this->assertSame(
            ['job' => 'j-x', 'dryRun' => false, 'trigger' => 'schedule'],
            ur_runner_parse_args(['runner.php', '--job', 'j-x', '--trigger=schedule'])
        );
        // An unrecognised trigger value is clamped to 'manual'.
        $this->assertSame(
            ['job' => 'j-x', 'dryRun' => false, 'trigger' => 'manual'],
            ur_runner_parse_args(['runner.php', '--job=j-x', '--trigger=bogus'])
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

    #[DataProvider('singlePairExitToStateProvider')]
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

    public static function singlePairExitToStateProvider(): array
    {
        // Mirrors Rsync::exitToState, but asserted END-TO-END through Runner::run.
        return [
            'success-0'    => [0, Rsync::STATE_SUCCESS],
            'vanished-24'  => [24, Rsync::STATE_WARNING],
            'maxdelete-25' => [25, Rsync::STATE_WARNING],
            'partial-23'   => [23, Rsync::STATE_PARTIAL],
            'iotimeout-30' => [30, Rsync::STATE_TIMEOUT],
            'contimeout-35' => [35, Rsync::STATE_TIMEOUT],
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
        $this->assertSame(Notify::IMPORTANCE_WARNING, Runner::notifyImportance($res));
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

    public function testDryRunNotifiesWithMarkerThroughFullRun(): void
    {
        // notifyHook's dry-run behaviour is unit-tested directly; here we prove it
        // END-TO-END: a DRY run with notifyMode=always DOES dispatch a notify
        // through the real Runner::run finally, carrying the [Dry-run] marker. We
        // capture via Notify's seam.
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
            $this->assertCount(1, $captured, 'a dry run must notify under notifyMode=always');
            $this->assertStringContainsString('[Dry-run]', $captured[0], 'the dry-run notification must be marked');
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

    public function testAcceptNewSshRunPinsTheHostKeyForTheNextRun(): void
    {
        // TOFU at the RUNNER level: the connection is accept-new with nothing
        // pinned, so ssh would accept - and append to this run's known_hosts -
        // whatever key the host presents. The fake rsync stands in for that
        // append; the run must lift the key into credentials.json BEFORE
        // cleanupRuntime() deletes the file, or every future run trusts a MITM.
        $hostKeyLine    = 'h.example ssh-ed25519 AAAAacceptedbyssh';
        $seenKnownHosts = null;

        $origBase = Ssh::$runtimeBase;
        $rt = sys_get_temp_dir() . '/ur-runner-tofu-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Ssh::$runtimeBase = $rt;

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
            'authMethod' => 'KEY', 'keyId' => 'k-1', 'strictHostKey' => 'accept-new',
        ]);
        Credentials::save($creds);

        Rsync::$runner = function (array $argv, $onOutput) use (&$seenKnownHosts, $hostKeyLine): int {
            $eIdx  = array_search('-e', $argv, true);
            $dashE = ($eIdx !== false) ? (string) $argv[$eIdx + 1] : '';
            if (preg_match("#'UserKnownHostsFile=([^']+)'#", $dashE, $m)) {
                $seenKnownHosts = $m[1];
                file_put_contents($seenKnownHosts, $hostKeyLine . "\n", FILE_APPEND);
            }
            return 0;
        };

        $config = Config::load();
        $job = Config::defaultJob();
        $job['id']           = 'j-tofu';
        $job['name']         = 'j-tofu';
        $job['transport']    = 'SSH';
        $job['direction']    = 'PUSH';
        $job['connectionId'] = 'c-key';
        $job['pairs']        = [['local' => '/mnt/user/src/', 'remote' => '/data/dst/']];
        $config['jobs'][]    = $job;
        Config::save($config);
        RunState::clear('j-tofu');
        RunState::clearAbort('j-tofu');

        try {
            $res = Runner::run('j-tofu', false);
            $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
            $this->assertNotNull($seenKnownHosts, 'the fake rsync saw the per-run known_hosts in -e');

            $stored = Credentials::findConnection(Credentials::load(), 'c-key');
            $this->assertIsArray($stored);
            $this->assertSame($hostKeyLine, trim((string) $stored['remoteHostKey']));

            $log = (string) @file_get_contents($res['runLog']);
            $this->assertStringContainsString('Pinned host key for connection c-key on first use.', $log);
            // The log line must carry neither the key material nor the tmpfs path.
            $this->assertStringNotContainsString('AAAAacceptedbyssh', $log);
            $this->assertStringNotContainsString((string) $seenKnownHosts, $log);
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

    // --- secretsDir plumbing -----------------------------------------------

    public function testRunSetsCredentialsSecretsDirOverrideFromConfig(): void
    {
        $id = $this->saveLocalJob('j-local');
        $cfg = Config::load();
        $cfg['global']['secretsDir'] = '/mnt/user/system/unraid.rsync';
        Config::save($cfg);

        Rsync::$runner = function (array $argv, $onOutput): int {
            return 0;
        };
        Runner::run($id, false);

        // The run pushed the validated secrets dir into Credentials, exactly like
        // it does for the Logger log dir.
        $this->assertSame('/mnt/user/system/unraid.rsync', Credentials::$secretsDirOverride);
    }

    public function testRunFailsCleanlyWhenSecretsDirUnavailable(): void
    {
        // An SSH job whose connection lives in credentials.json, but secretsDir
        // points at an /mnt path that doesn't exist (the array isn't started).
        // The keychain reads back EMPTY from the missing dir, so the connection
        // lookup fails with a clear "Connection not found" - NOT a PHP fatal.
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };

        // Seed the connection on the default /boot location.
        $creds = Credentials::defaults();
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-key', 'name' => 'k', 'host' => 'h.example', 'username' => 'root',
            'authMethod' => 'KEYFILE', 'keyFilePath' => '/root/.ssh/id_ed25519',
        ]);
        Credentials::save($creds);

        $config = Config::load();
        $job = Config::defaultJob();
        $job['id']           = 'j-secrets';
        $job['name']         = 'j-secrets';
        $job['transport']    = 'SSH';
        $job['direction']    = 'PUSH';
        $job['connectionId'] = 'c-key';
        $job['pairs']        = [['local' => '/mnt/user/src/', 'remote' => '/data/dst/']];
        // Simulate "array not started": secretsDir points at a missing /mnt path.
        $config['global']['secretsDir'] = '/mnt/user/nope-' . bin2hex(random_bytes(4)) . '/ur';
        $config['jobs'][] = $job;
        Config::save($config);
        RunState::clear('j-secrets');
        RunState::clearAbort('j-secrets');

        $res = Runner::run('j-secrets', false);

        // Reset the override (the run left it pointing at the missing /mnt path)
        // BEFORE any cleanup write, so the reset keychain lands back on /boot.
        Credentials::$secretsDirOverride = null;

        $this->assertIsArray($res);
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame(0, $rsyncCalls, 'rsync must not run when credentials are unreachable');
        $log = @file_get_contents($res['runLog']);
        $this->assertIsString($log);
        $this->assertStringContainsString('Connection not found', $log);

        Credentials::save(Credentials::defaults());
    }

    // =====================================================================
    // rsync DAEMON transport (issue #139). Every daemon run below is driven
    // through the SAME injected seams the SSH/LOCAL runs use - Rsync::$runner
    // for the spawn and Ssh::$runtimeBase for the tmpfs - so no rsync, ssh or
    // rsyncd is ever contacted.
    // =====================================================================

    /** Recursively remove a temp runtime base (same shape as the inline cleanups above). */
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

    /**
     * Seed a DAEMON connection + a DAEMON job and point Ssh::$runtimeBase at a
     * fresh temp tmpfs. Returns that base; the caller restores
     * Ssh::$runtimeBase, resets the keychain and rmTree()s it in a finally.
     *
     * @param array<string,mixed> $connOverrides
     * @param array<string,mixed> $jobOverrides
     */
    private function seedDaemonJob(string $jobId, array $connOverrides = [], array $jobOverrides = []): string
    {
        $rt = sys_get_temp_dir() . '/ur-runner-daemon-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Ssh::$runtimeBase = $rt;

        $creds = Credentials::defaults();
        $creds['connections'][] = Credentials::mergeConnection(array_merge([
            'id'        => 'c-daemon',
            'name'      => 'nas',
            'transport' => 'DAEMON',
            'host'      => 'nas.local',
            'username'  => 'moduser',
            'password'  => Credentials::obfuscate('s3cret'),
        ], $connOverrides));
        Credentials::save($creds);

        $config = Config::load();
        $job = Config::defaultJob();
        $job['id']           = $jobId;
        $job['name']         = $jobId;
        $job['transport']    = 'DAEMON';
        $job['direction']    = 'PUSH';
        $job['connectionId'] = 'c-daemon';
        $job['pairs']        = [['local' => '/mnt/user/src/', 'remote' => 'rsync_bkp/photos']];
        $config['jobs'][]    = array_merge($job, $jobOverrides);
        Config::save($config);
        RunState::clear($jobId);
        RunState::clearAbort($jobId);

        return $rt;
    }

    // --- resolvePair --------------------------------------------------------

    /**
     * resolvePair is a pure operand builder, and the DAEMON case differs from
     * the SSH one by exactly one character ('::' vs ':'). The matrix pins all
     * three transports PLUS a junk hand-edited value in ONE place, whole-array,
     * so a future transport branch cannot quietly change SSH or LOCAL bytes.
     *
     * @param array{src:string,dest:string} $expected
     */
    #[DataProvider('resolvePairTransportProvider')]
    public function testResolvePairTransportMatrix(
        string $transport,
        string $direction,
        string $remote,
        array $expected
    ): void {
        $job = Config::defaultJob();
        $job['transport'] = $transport;
        $job['direction'] = $direction;

        $this->assertSame(
            $expected,
            Runner::resolvePair(
                $job,
                ['local' => '/mnt/user/m/', 'remote' => $remote],
                $transport,
                'sasa@rpi'
            )
        );
    }

    public static function resolvePairTransportProvider(): array
    {
        return [
            // DAEMON: userHost . '::' . module reference, both directions.
            'daemon-push' => ['DAEMON', 'PUSH', 'rsync_bkp/photos', [
                'src' => '/mnt/user/m/', 'dest' => 'sasa@rpi::rsync_bkp/photos',
            ]],
            'daemon-pull' => ['DAEMON', 'PULL', 'rsync_bkp/photos', [
                'src' => 'sasa@rpi::rsync_bkp/photos', 'dest' => '/mnt/user/m/',
            ]],
            // A bare module root keeps its shape too (no slash is inserted).
            'daemon-module-root-push' => ['DAEMON', 'PUSH', 'rsync_bkp', [
                'src' => '/mnt/user/m/', 'dest' => 'sasa@rpi::rsync_bkp',
            ]],
            // A trailing slash is meaningful to rsync and must survive verbatim.
            'daemon-trailing-slash-pull' => ['DAEMON', 'PULL', 'rsync_bkp/', [
                'src' => 'sasa@rpi::rsync_bkp/', 'dest' => '/mnt/user/m/',
            ]],
            // SSH: single colon, unchanged by the daemon arm inserted above it.
            'ssh-push' => ['SSH', 'PUSH', '/data/m/', [
                'src' => '/mnt/user/m/', 'dest' => 'sasa@rpi:/data/m/',
            ]],
            'ssh-pull' => ['SSH', 'PULL', '/data/m/', [
                'src' => 'sasa@rpi:/data/m/', 'dest' => '/mnt/user/m/',
            ]],
            // LOCAL: both sides bare, direction ignored, userHost never applied.
            'local-push' => ['LOCAL', 'PUSH', '/mnt/disk1/m/', [
                'src' => '/mnt/user/m/', 'dest' => '/mnt/disk1/m/',
            ]],
            'local-pull' => ['LOCAL', 'PULL', '/mnt/disk1/m/', [
                'src' => '/mnt/user/m/', 'dest' => '/mnt/disk1/m/',
            ]],
            // An unknown hand-edited transport keeps TODAY's LOCAL-shaped
            // fallthrough - it must not fall into the new DAEMON arm.
            'unknown-push' => ['FTP', 'PUSH', '/data/m/', [
                'src' => '/mnt/user/m/', 'dest' => '/data/m/',
            ]],
            'unknown-pull' => ['FTP', 'PULL', '/data/m/', [
                'src' => '/mnt/user/m/', 'dest' => '/data/m/',
            ]],
        ];
    }

    // --- guardrailErrors <-> Job::validate mirror (D13) ----------------------

    /**
     * Rewrite Job::validate's per-pair error labels into Runner::guardrailErrors'
     * labels, so the two lists can be compared BY VALUE and IN ORDER. Anything
     * that is not a pure label difference therefore shows up as a mismatch.
     *
     * @param array<int,string> $jobPairErrors
     * @return array<int,string>
     */
    private function asRunnerLabels(array $jobPairErrors, string $transport, string $direction): array
    {
        $destIsRemote = in_array($transport, ['SSH', 'DAEMON'], true) ? ($direction !== 'PULL') : true;
        $localRole    = $destIsRemote ? 'source' : 'destination';
        $remoteRole   = $destIsRemote ? 'destination' : 'source';
        $qualifier    = ($transport === 'LOCAL') ? 'local' : (($transport === 'DAEMON') ? 'module' : 'remote');
        $remoteLabel  = ($transport === 'LOCAL')
            ? 'second local path'
            : (($transport === 'DAEMON') ? 'daemon module path' : 'remote path');

        // strtr() picks the LONGEST match at each position, and the "required"
        // forms carry "Pair #1: " (with the colon) while the checker labels
        // carry "Pair #1 " (without), so the two shapes can never collide.
        $map = [
            "Pair #1: $localRole (local) path is required."        => 'local path is required.',
            "Pair #1: $remoteRole ($qualifier) path is required."  => $remoteLabel . ' is required.',
            "Pair #1 $localRole (local)"                           => 'local path',
            "Pair #1 $remoteRole ($qualifier)"                     => $remoteLabel,
            'Pair #1: a delete option'                             => 'a delete option',
        ];

        return array_values(array_map(
            static fn(string $e): string => strtr($e, $map),
            $jobPairErrors
        ));
    }

    /**
     * D13: Job::validate (save time) and Runner::guardrailErrors (run time) are
     * a MIRROR PAIR - same checker, same predicate, same order, for every
     * transport including a junk hand-edited one. They may differ ONLY in the
     * error labels, which asRunnerLabels() normalises away. A daemon branch
     * added to one side and not the other fails here.
     */
    #[DataProvider('guardrailMirrorProvider')]
    public function testGuardrailErrorsMirrorJobValidateForEveryTransport(
        string $transport,
        string $direction,
        string $local,
        string $remote,
        bool $deleteOn
    ): void {
        $opts = Config::mergeRsyncOptions($deleteOn ? ['delete' => true] : []);

        $job = Config::defaultJob();
        $job['name']         = 'mirror';
        $job['transport']    = $transport;
        $job['direction']    = $direction;
        $job['pairs']        = [['local' => $local, 'remote' => $remote]];
        $job['rsyncOptions'] = $opts;

        $runnerErrors = Runner::guardrailErrors($job, ['local' => $local, 'remote' => $remote], $transport, $opts);

        $jobPairErrors = array_values(array_filter(
            Job::validate($job)['errors'],
            static fn(string $e): bool => strpos($e, 'Pair #') === 0
        ));

        $this->assertSame(
            $this->asRunnerLabels($jobPairErrors, $transport, $direction),
            $runnerErrors,
            "save-time and run-time guardrails disagree for $transport/$direction"
        );
    }

    public static function guardrailMirrorProvider(): array
    {
        return [
            // DAEMON: the happy module reference, and every shape checkDaemonModule rejects.
            'daemon-ok'                  => ['DAEMON', 'PUSH', '/mnt/user/src/', 'rsync_bkp/photos', false],
            'daemon-module-root-delete'  => ['DAEMON', 'PUSH', '/mnt/user/src/', 'rsync_bkp', true],
            'daemon-absolute-module'     => ['DAEMON', 'PUSH', '/mnt/user/src/', '/data', true],
            'daemon-host-in-module'      => ['DAEMON', 'PUSH', '/mnt/user/src/', 'nas::mod', false],
            'daemon-dotdot-segment'      => ['DAEMON', 'PUSH', '/mnt/user/src/', 'rsync_bkp/../etc', false],
            'daemon-empty-remote'        => ['DAEMON', 'PUSH', '/mnt/user/src/', '', true],
            'daemon-pull-bad-local'      => ['DAEMON', 'PULL', '/mnt/user', 'rsync_bkp/photos', true],
            'daemon-pull-delete-ok'      => ['DAEMON', 'PULL', '/mnt/user/dst/', 'rsync_bkp', true],
            // SSH and LOCAL: unchanged behaviour on both sides.
            'ssh-push-root-delete'       => ['SSH', 'PUSH', '/mnt/user/src/', '/', true],
            'ssh-pull-root-delete'       => ['SSH', 'PULL', '/mnt/user/src/sub/', '/', true],
            'ssh-push-module-shaped'     => ['SSH', 'PUSH', '/mnt/user/src/', 'tmp', false],
            'local-pull-root-delete'     => ['LOCAL', 'PULL', '/mnt/user/src/', '/', true],
            'local-push-ok'              => ['LOCAL', 'PUSH', '/mnt/user/src/', '/mnt/disk1/dst/', true],
            // A junk hand-edited transport: both sides must agree about it too.
            'unknown-pull-root-delete'   => ['FTP', 'PULL', '/mnt/user/src/sub/', '/', true],
            'unknown-push-module-shaped' => ['FTP', 'PUSH', '/mnt/user/src/', 'tmp', false],
            'both-sides-empty'           => ['SSH', 'PUSH', '', '', true],
        ];
    }

    /** @return array<string,array{0:string,1:string,2:bool,3:string,4:string}> */
    public static function bootMatrixProvider(): array
    {
        $cases = [];
        foreach (['SSH', 'DAEMON', 'LOCAL'] as $t) {
            foreach (['PUSH', 'PULL'] as $d) {
                foreach ([true, false] as $snap) {
                    foreach (['/boot', '/boot/', '/boot/config', '/bootx', '/mnt/user/x'] as $p) {
                        foreach (['local', 'remote'] as $side) {
                            $cases["$t $d snapshot-" . ($snap ? 'on' : 'off') . " $side=$p"] = [$t, $d, $snap, $side, $p];
                        }
                    }
                }
            }
        }
        return $cases;
    }

    /**
     * Spec §8: /boot is a valid `local` only as the source of an SSH PUSH
     * snapshot job, never a subdirectory, never this server's destination, and
     * save time (Job::validate) agrees with run time (preflight's
     * guardrailErrors + snapshotErrors) in every cell. A path counts as accepted
     * when the errors equal those of the same job with a known-good path there.
     */
    #[DataProvider('bootMatrixProvider')]
    public function testBootIsOnlyEverAnSshPushSnapshotSource(string $transport, string $direction, bool $snap, string $side, string $path): void
    {
        $remoteOk = ['SSH' => '/srv/backup/x', 'DAEMON' => 'mod/x', 'LOCAL' => '/mnt/user/dst'][$transport];
        $build = static function (string $value) use ($transport, $direction, $snap, $side, $remoteOk): array {
            $job = array_merge(Config::defaultJob(), [
                'name' => 'boot', 'transport' => $transport, 'direction' => $direction, 'connectionId' => 'c-1',
                'targetMarker' => '/srv/backup/.unraid-rsync-target', 'targetMarkerContent' => 'uuid-1',
                'rsyncOptions' => Config::mergeRsyncOptions(['archive' => true, 'times' => true]),
                'pairs' => [$side === 'local'
                    ? ['local' => $value, 'remote' => $snap ? '' : $remoteOk, 'sub' => 'boot']
                    : ['local' => '/mnt/user/src/', 'remote' => $value, 'sub' => 'boot']],
            ]);
            if ($snap) {
                $job['snapshot'] = ['enabled' => true, 'root' => '/srv/backup/snapshots/boot'];
            }
            return $job;
        };
        $saveTime = static fn(array $job): array => Job::validate($job)['errors'];
        $runTime  = static fn(array $job): array => array_merge(
            Runner::guardrailErrors($job, $job['pairs'][0], $transport, $job['rsyncOptions']),
            $snap ? Job::snapshotErrors($job, $job['rsyncOptions'], $transport, $direction) : []
        );
        $good = $side === 'local' ? '/mnt/user/x' : ($snap ? '' : $remoteOk);

        $savedOk = $saveTime($build($path)) === $saveTime($build($good));
        $runOk   = $runTime($build($path)) === $runTime($build($good));

        $isBoot = in_array($path, ['/boot', '/boot/'], true);
        if ($side === 'local') {
            $expected = $path === '/mnt/user/x' || ($isBoot && $transport === 'SSH' && $direction === 'PUSH' && $snap);
        } elseif ($snap || $transport === 'DAEMON') {
            $expected = false;
        } else {
            // SSH `remote` is a path on the receiver, not this server's /boot.
            $expected = $transport === 'SSH' || $path === '/mnt/user/x';
        }
        $this->assertSame($expected, $savedOk, 'save time');
        $this->assertSame($savedOk, $runOk, 'save time and run time disagree');
    }

    /** @return array<string,array{0:array<string,mixed>,1:string,2:bool}> */
    public static function bootHandEditProvider(): array
    {
        $on = ['snapshot' => ['enabled' => true, 'root' => '/srv/backup/snapshots/boot']];
        $cases = [
            'whitespace is trimmed' => [$on, " /boot/\t", true],
            'lower-case enums'      => [['transport' => 'ssh', 'direction' => 'push'] + $on, '/boot/', true],
            'missing direction'     => [['direction' => null] + $on, '/boot', true],
            'lower-case pull'       => [['direction' => 'pull'] + $on, '/boot/', false],
        ];
        foreach (['/boot//', '/boot/.', '/./boot', '/mnt/../boot', '/BOOT'] as $p) {
            $cases["path $p"] = [$on, $p, false];
        }
        foreach (['int 1' => 1, 'string 1' => '1', 'string true' => 'true', 'null' => null] as $name => $v) {
            $cases["enabled $name"] = [['snapshot' => ['enabled' => $v, 'root' => '/srv/backup/snapshots/boot']], '/boot/', false];
        }
        return $cases;
    }

    /**
     * Hand-edited config.json shapes that never pass through the form's
     * normalisation: only a JSON true enables snapshot mode, only the exact
     * strings open /boot, and save time and run time agree on each.
     *
     * @param array<string,mixed> $overrides
     */
    #[DataProvider('bootHandEditProvider')]
    public function testHandEditedBootJobsAgreeAtSaveAndRunTime(array $overrides, string $path, bool $expected): void
    {
        $build = static function (string $local) use ($overrides): array {
            $job = array_merge(Config::defaultJob(), [
                'name' => 'boot', 'transport' => 'SSH', 'direction' => 'PUSH', 'connectionId' => 'c-1',
                'targetMarker' => '/srv/backup/.unraid-rsync-target', 'targetMarkerContent' => 'uuid-1',
                'rsyncOptions' => Config::mergeRsyncOptions(['archive' => true, 'times' => true]),
                'pairs' => [['local' => $local, 'remote' => '', 'sub' => 'boot']],
            ], $overrides);
            return array_filter($job, static fn($v): bool => $v !== null);
        };
        $runTime = static function (array $job): array {
            $transport = strtoupper(trim((string) ($job['transport'] ?? 'SSH')));
            $direction = strtoupper(trim((string) ($job['direction'] ?? 'PUSH')));
            return array_merge(
                Runner::guardrailErrors($job, $job['pairs'][0], $transport, $job['rsyncOptions']),
                Job::snapshotErrors($job, $job['rsyncOptions'], $transport, $direction)
            );
        };

        $savedOk = Job::validate($build($path))['errors'] === Job::validate($build('/mnt/user/x'))['errors'];
        $runOk   = $runTime($build($path)) === $runTime($build('/mnt/user/x'));
        $this->assertSame($expected, $savedOk, 'save time');
        $this->assertSame($savedOk, $runOk, 'save time and run time disagree');
    }

    /**
     * The --delete destination side, pinned WHOLE-ARRAY per transport.
     *
     * The discriminating case is 'FTP' + PULL, NOT 'LOCAL' + PULL: LOCAL is
     * forced to PUSH by normalize(), so it yields destIsRemote=true under both
     * the old (`$transport !== 'SSH' ? true : ...`) and the new
     * (`in_array(..., ['SSH','DAEMON']) ? ... : true`) expression and would
     * catch nothing. An unknown transport under PULL is the only input where a
     * plausible mis-write of the new expression flips the checked side.
     */
    public function testGuardrailDeleteDestinationSideIsIdenticalForSshLocalAndAnUnknownTransport(): void
    {
        $opts = Config::mergeRsyncOptions(['delete' => true]);
        $pair = ['local' => '/mnt/user/src/sub/', 'remote' => '/'];
        $job  = Config::defaultJob();
        $job['direction'] = 'PULL';

        $rootIsNotSubdir = "remote path '/' must be a specific sub-directory, not the filesystem root.";
        $deleteError     = 'a delete option is enabled, so the destination must be a specific sub-directory, not a root.';

        // SSH + PULL: the destination is the LOCAL side, which IS specific, so
        // only the remote-path root complaint fires.
        $job['transport'] = 'SSH';
        $this->assertSame([$rootIsNotSubdir], Runner::guardrailErrors($job, $pair, 'SSH', $opts));

        // Unknown transport + PULL: the destination is the REMOTE side (matching
        // resolvePair's LOCAL-shaped fallthrough), so the delete guard fires.
        $job['transport'] = 'FTP';
        $this->assertSame([$rootIsNotSubdir, $deleteError], Runner::guardrailErrors($job, $pair, 'FTP', $opts));

        // LOCAL + PULL: destination is the remote side too (direction is
        // spurious for LOCAL), checked with the LOCAL checker.
        $job['transport'] = 'LOCAL';
        $this->assertSame(
            [
                "second local path '/' is a protected system or array root and cannot be used.",
                $deleteError,
            ],
            Runner::guardrailErrors($job, $pair, 'LOCAL', $opts)
        );

        // DAEMON + PULL: destination is the LOCAL side, exactly as for SSH.
        $job['transport'] = 'DAEMON';
        $this->assertSame(
            [
                "daemon module path '/' must not begin with \"/\". An rsync daemon path is relative to the "
                    . 'module, so enter the module reference (for example rsync_bkp or rsync_bkp/photos), '
                    . 'not an absolute filesystem path.',
            ],
            Runner::guardrailErrors($job, $pair, 'DAEMON', $opts)
        );
    }

    // --- a full daemon run --------------------------------------------------

    public function testDaemonRunOnANonDefaultPortEmitsThatPortNotEightSevenThree(): void
    {
        // buildArgv is proven to honour a port and materializeDaemon is proven to
        // return one, but the JOIN between them - Runner reading $mat['port'] into
        // the pieces bag - is only exercised here. A NAS rsyncd behind a port map
        // is the common case, and hard-coding 873 in the Runner would pass every
        // other daemon test.
        $seenArgv = null;
        $origBase = Ssh::$runtimeBase;
        $rt       = $this->seedDaemonJob('j-daemon-port', ['port' => 8730]);

        Rsync::$runner = function (array $argv, $onOutput, ?array $env = null) use (&$seenArgv): int {
            $seenArgv = $argv;
            return 0;
        };

        try {
            $res = Runner::run('j-daemon-port', false);
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);
        }

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertIsArray($seenArgv);
        $this->assertContains('--port=8730', $seenArgv);
        $this->assertNotContains('--port=873', $seenArgv, 'the connection port must reach the argv, not a default');
    }

    public function testSshRunUnlinksItsRuntimeSecretsInTheFinally(): void
    {
        // The daemon arm asserts its pass file is gone after the run; its SSH
        // sibling never did, so dropping the token on the SSH arm would leave the
        // run's known_hosts / pass files behind (and per-token redaction unarmed)
        // with the suite still green.
        $khDuringRun   = null;
        $passDuringRun = null;

        $origBase = Ssh::$runtimeBase;
        $rt = sys_get_temp_dir() . '/ur-runner-sshclean-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Ssh::$runtimeBase = $rt;

        // PASSWORD auth + a pinned host key, so BOTH pass/ and known_hosts/ are
        // materialised for the run.
        $creds = Credentials::defaults();
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-pw', 'name' => 'cpw', 'host' => 'h.example', 'username' => 'root',
            'authMethod' => 'PASSWORD', 'password' => Credentials::obfuscate('pw'),
            'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
        ]);
        Credentials::save($creds);

        Rsync::$runner = function (array $argv, $onOutput) use (&$khDuringRun, &$passDuringRun, $rt): int {
            $kh   = glob($rt . '/known_hosts/*');
            $pass = glob($rt . '/pass/*');
            $khDuringRun   = (is_array($kh) && $kh !== []) ? $kh[0] : null;
            $passDuringRun = (is_array($pass) && $pass !== []) ? $pass[0] : null;
            return 0;
        };

        $config = Config::load();
        $job = Config::defaultJob();
        $job['id']           = 'j-sshclean';
        $job['name']         = 'j-sshclean';
        $job['transport']    = 'SSH';
        $job['direction']    = 'PUSH';
        $job['connectionId'] = 'c-pw';
        $job['pairs']        = [['local' => '/mnt/user/src/', 'remote' => '/data/dst/']];
        $config['jobs'][]    = $job;
        Config::save($config);
        RunState::clear('j-sshclean');
        RunState::clearAbort('j-sshclean');

        try {
            $res = Runner::run('j-sshclean', false);
            // Sampled INSIDE the try: the finally below rmTree()s the whole base,
            // which would make every "is it gone?" assertion pass vacuously.
            $khAfterRun   = ($khDuringRun !== null) ? is_file($khDuringRun) : null;
            $passAfterRun = ($passDuringRun !== null) ? is_file($passDuringRun) : null;
            $leftOver     = array_merge(glob($rt . '/pass/*') ?: [], glob($rt . '/known_hosts/*') ?: []);
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);
        }

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertNotNull($khDuringRun, 'a known_hosts file exists while the SSH rsync runs');
        $this->assertNotNull($passDuringRun, 'a pass file exists while the SSH rsync runs');
        $this->assertFalse($khAfterRun, 'known_hosts is unlinked by the run finally');
        $this->assertFalse($passAfterRun, 'the pass file is unlinked by the run finally');
        $this->assertSame([], $leftOver, 'no per-run secret survives the run');
    }

    public function testDaemonRunEmitsTheFullDaemonArgvAndMapsTheExitCode(): void
    {
        $seenArgv       = null;
        $seenEnv        = 'not-called';
        $passDuringRun  = null;
        $permDuringRun  = null;
        $passAfterRun   = null;

        $origBase = Ssh::$runtimeBase;
        // contimeout is emitted ONLY for daemon transport (rsync main.c:1558
        // makes it a hard failure everywhere else), so a daemon run is the one
        // place the whole chain job -> effectiveOptions -> buildArgv can be
        // asserted end to end.
        $rt = $this->seedDaemonJob('j-daemon', [], [
            'rsyncOptions' => Config::mergeRsyncOptions(['contimeout' => '45']),
        ]);

        Rsync::$runner = function (array $argv, $onOutput, ?array $env = null) use (&$seenArgv, &$seenEnv, &$passDuringRun, &$permDuringRun, $rt): int {
            $seenArgv = $argv;
            $seenEnv  = $env;
            // The materialised secret, found independently of the argv.
            $files = glob($rt . '/pass/*');
            if (is_array($files) && count($files) === 1) {
                $passDuringRun = $files[0];
                clearstatcache(true, $passDuringRun);
                $permDuringRun = fileperms($passDuringRun) & 0777;
                // FIRST captured byte names the passfile, exactly as rsync's own
                // getpassf() errors do: redaction must already be armed.
                $onOutput('opening password file ' . $passDuringRun . "\n");
            }
            return 24; // vanished files -> WARNING
        };

        try {
            $res          = Runner::run('j-daemon', false);
            $passAfterRun = ($passDuringRun !== null) ? is_file($passDuringRun) : null;
            $log          = (string) @file_get_contents($res['runLog']);
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);
        }

        // Exit-code mapping is the same machinery every other transport uses.
        $this->assertSame(Rsync::STATE_WARNING, $res['state']);
        $this->assertSame(24, $res['exitCode']);
        $this->assertSame(Rsync::STATE_WARNING, Runner::readSummary('j-daemon')['state']);

        // The 0600 tmpfs passfile existed while rsync ran, and is gone after.
        $this->assertNotNull($passDuringRun, 'exactly one pass file exists while the daemon rsync runs');
        $this->assertStringStartsWith($rt . '/pass/', (string) $passDuringRun);
        $this->assertSame(0600, $permDuringRun, 'rsync getpassf() refuses a file with st_mode & 06 set');
        $this->assertFalse($passAfterRun, 'the pass file is unlinked by the run finally');

        // The WHOLE argv, in order: --port ALWAYS, --password-file only because
        // a secret exists, --log-file still emitted, no -e, tail always
        // ['--', src, dest], and the operand is userHost . '::' . module.
        $this->assertSame([
            Rsync::rsyncPath(),
            '-r',
            '-h',
            '-t',
            '--partial',
            '--mkpath',
            '--contimeout=45',
            '--info=progress2',
            '--log-file-format=',
            '--log-file=' . $res['runLog'],
            '--port=873',
            '--password-file=' . $passDuringRun,
            '--',
            '/mnt/user/src/',
            'moduser@nas.local::rsync_bkp/photos',
        ], $seenArgv);

        // F9 / D10: the pieces bag always carries sshEnv => [], and
        // Ssh::childEnv([]) is null - the child inherits, carrying no secret.
        $this->assertNull($seenEnv, 'a daemon run passes no child environment');

        // Redaction was armed BEFORE the first captured byte.
        $this->assertStringNotContainsString((string) $passDuringRun, $log);
        $this->assertStringContainsString(
            'opening password file ' . Logger::REDACT_PLACEHOLDER,
            $log
        );
        // ...and disarmed afterwards, so nothing leaks into a later run.
        $this->assertSame((string) $passDuringRun, Logger::redact((string) $passDuringRun));
    }

    public function testAnonymousDaemonModuleRunEmitsNoPasswordFileAndWritesNoSecret(): void
    {
        // A module with no `auth users` needs no secret. An EMPTY password file
        // would make rsync exit 1 ("failed to read a password from %s",
        // authenticate.c:215), so nothing must be written and --password-file
        // must be absent - which also proves buildArgv gates on the explicit
        // `daemon` key rather than on "is there a password file".
        $seenArgv    = null;
        $passDirMade = null;

        $origBase = Ssh::$runtimeBase;
        $rt = $this->seedDaemonJob('j-daemon-anon', ['password' => '']);

        Rsync::$runner = function (array $argv, $onOutput) use (&$seenArgv, &$passDirMade, $rt): int {
            $seenArgv    = $argv;
            $passDirMade = is_dir($rt . '/pass');
            return 0;
        };

        try {
            $res = Runner::run('j-daemon-anon', false);
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);
        }

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertFalse($passDirMade, 'an anonymous module mints no token and creates no pass dir');
        $this->assertSame([
            Rsync::rsyncPath(),
            '-r',
            '-h',
            '-t',
            '--partial',
            '--mkpath',
            '--info=progress2',
            '--log-file-format=',
            '--log-file=' . $res['runLog'],
            '--port=873',
            '--',
            '/mnt/user/src/',
            'moduser@nas.local::rsync_bkp/photos',
        ], $seenArgv);
    }

    public function testDaemonRunRedactsThePassFilePathRsyncWritesThroughItsOwnLogFile(): void
    {
        // D9. rsync writes the run log ITSELF through --log-file, on its own fd:
        // those bytes never pass through Logger::sink, and Logger::setRedaction
        // only hooks the sink. authenticate.c:188 and :215 name the password
        // file VERBATIM in two of rsync's own errors, so the fake runner appends
        // straight to the run-log FILE here. A sink-only test is green while the
        // real thing leaks, which is exactly why this one bypasses the sink.
        $passFromArgv = null;

        $origBase = Ssh::$runtimeBase;
        $rt = $this->seedDaemonJob('j-daemon-logfile');

        Rsync::$runner = function (array $argv, $onOutput) use (&$passFromArgv): int {
            $logFile = '';
            foreach ($argv as $tok) {
                if (strpos($tok, '--log-file=') === 0) {
                    $logFile = substr($tok, strlen('--log-file='));
                }
                if (strpos($tok, '--password-file=') === 0) {
                    $passFromArgv = substr($tok, strlen('--password-file='));
                }
            }
            // Written DIRECTLY to the file, never through $onOutput.
            file_put_contents(
                $logFile,
                "2026/09/03 10:00:00 [999] could not open password file $passFromArgv\n"
                    . "2026/09/03 10:00:00 [999] ERROR: failed to read a password from $passFromArgv\n",
                FILE_APPEND
            );
            return 1;
        };

        try {
            $res = Runner::run('j-daemon-logfile', false);
            $log = (string) @file_get_contents($res['runLog']);
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);
        }

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertNotNull($passFromArgv, 'the daemon argv carried a --password-file path');
        $this->assertStringStartsWith($rt . '/pass/', (string) $passFromArgv);

        // The path rsync wrote itself is scrubbed from the browser-visible log,
        // and the surrounding rsync text still reads correctly.
        $this->assertStringNotContainsString((string) $passFromArgv, $log);
        $this->assertStringContainsString(
            'could not open password file ' . Logger::REDACT_PLACEHOLDER,
            $log
        );
        $this->assertStringContainsString(
            'ERROR: failed to read a password from ' . Logger::REDACT_PLACEHOLDER,
            $log
        );
        // Disarmed in the finally, after the last redaction pass.
        $this->assertSame((string) $passFromArgv, Logger::redact((string) $passFromArgv));
    }

    // --- transport mismatch: job vs. connection ------------------------------

    public function testSshJobPointingAtADaemonConnectionFailsWithNoRsyncSpawn(): void
    {
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };

        $origBase = Ssh::$runtimeBase;
        $rt = $this->seedDaemonJob('j-ssh-at-daemon', [], ['transport' => 'SSH', 'postHook' => 'POST']);

        try {
            $res      = Runner::run('j-ssh-at-daemon', false);
            $log      = (string) @file_get_contents($res['runLog']);
            $rtDirs   = [is_dir($rt . '/keys'), is_dir($rt . '/pass'), is_dir($rt . '/known_hosts')];
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);
        }

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('ssh-config', $res['reason'] ?? '');
        $this->assertSame(0, $rsyncCalls, 'no rsync is spawned for a transport mismatch');
        $this->assertStringContainsString(
            'SSH transport could not be prepared: This Connection uses rsync daemon (rsyncd) transport; '
                . 'an SSH transport cannot be built from it.',
            $log
        );
        // The refusal precedes ensureRuntimeDirs()/newRuntimeToken(): nothing at
        // all was created under the runtime base.
        $this->assertSame([false, false, false], $rtDirs, 'a refused materialise creates no runtime dirs');
        // The failure path is still a normal unwind: the postHook ran.
        $this->assertContains('hook:POST:FAILED', $this->trace);
    }

    public function testDaemonJobPointingAtAnSshConnectionFailsWithNoRsyncSpawn(): void
    {
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };

        $origBase = Ssh::$runtimeBase;
        $rt = $this->seedDaemonJob(
            'j-daemon-at-ssh',
            ['transport' => 'SSH', 'authMethod' => 'PASSWORD', 'password' => Credentials::obfuscate('pw')],
            ['postHook' => 'POST']
        );

        try {
            $res    = Runner::run('j-daemon-at-ssh', false);
            $log    = (string) @file_get_contents($res['runLog']);
            $rtDirs = [is_dir($rt . '/keys'), is_dir($rt . '/pass'), is_dir($rt . '/known_hosts')];
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);
        }

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('daemon-config', $res['reason'] ?? '');
        $this->assertSame(0, $rsyncCalls, 'no rsync is spawned for a transport mismatch');
        $this->assertStringContainsString(
            'rsync daemon transport could not be prepared: The selected Connection uses SSH transport, '
                . 'but this job uses rsync daemon transport.',
            $log
        );
        // The Runner rejects on the MERGED connection before Ssh::materializeDaemon
        // is reached, so no SSH passfile is materialised for the daemon job either.
        $this->assertSame([false, false, false], $rtDirs, 'a refused daemon materialise creates no runtime dirs');
        $this->assertContains('hook:POST:FAILED', $this->trace);
    }

    // --- an incomplete connection fails fast ---------------------------------

    public function testDaemonJobWithAnEmptyHostFailsFastInsteadOfBuildingADoubleColonOperand(): void
    {
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };

        $origBase = Ssh::$runtimeBase;
        $rt = $this->seedDaemonJob('j-daemon-nohost', ['host' => '']);

        try {
            $res = Runner::run('j-daemon-nohost', false);
            $log = (string) @file_get_contents($res['runLog']);
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);
        }

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('connection-incomplete', $res['reason'] ?? '');
        $this->assertSame(0, $rsyncCalls, 'rsync must not run with an unresolvable operand');
        $this->assertStringContainsString(
            'rsync daemon transport could not be prepared: The selected Connection is incomplete: '
                . 'an rsync daemon job needs both a host and a username.',
            $log
        );
        // The whole point: the run never reaches the "::module" operand.
        $this->assertStringNotContainsString('::rsync_bkp', $log);
    }

    public function testSshJobWithAnEmptyUsernameFailsFastInsteadOfBuildingAColonOperand(): void
    {
        // Ssh::materialize never reads host or username, so before the fail-fast
        // guard this job returned ok:true and handed rsync the operand
        // ":/data/dst/", failing opaquely mid-run. It could never have succeeded.
        $rsyncCalls = 0;
        Rsync::$runner = function (array $argv, $onOutput) use (&$rsyncCalls): int {
            $rsyncCalls++;
            return 0;
        };

        $origBase = Ssh::$runtimeBase;
        $rt = sys_get_temp_dir() . '/ur-runner-nouser-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Ssh::$runtimeBase = $rt;

        $creds = Credentials::defaults();
        $creds['connections'][] = Credentials::mergeConnection([
            'id'         => 'c-nouser',
            'name'       => 'nouser',
            'host'       => 'h.example',
            'username'   => '',
            'authMethod' => 'PASSWORD',
            'password'   => Credentials::obfuscate('pw'),
        ]);
        Credentials::save($creds);

        $config = Config::load();
        $job = Config::defaultJob();
        $job['id']           = 'j-ssh-nouser';
        $job['name']         = 'j-ssh-nouser';
        $job['transport']    = 'SSH';
        $job['direction']    = 'PUSH';
        $job['connectionId'] = 'c-nouser';
        $job['pairs']        = [['local' => '/mnt/user/src/', 'remote' => '/data/dst/']];
        $config['jobs'][]    = $job;
        Config::save($config);
        RunState::clear('j-ssh-nouser');
        RunState::clearAbort('j-ssh-nouser');

        try {
            $res       = Runner::run('j-ssh-nouser', false);
            $log       = (string) @file_get_contents($res['runLog']);
            $leftovers = array_merge(
                (array) glob($rt . '/pass/*'),
                (array) glob($rt . '/keys/*'),
                (array) glob($rt . '/known_hosts/*')
            );
        } finally {
            Ssh::$runtimeBase = $origBase;
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);
        }

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('connection-incomplete', $res['reason'] ?? '');
        $this->assertSame(0, $rsyncCalls, 'rsync must not run with an unresolvable operand');
        $this->assertStringContainsString(
            'SSH transport could not be prepared: The selected Connection is incomplete: '
                . 'it needs both a host and a username.',
            $log
        );
        // The operand the old code would have built never appears...
        $this->assertStringNotContainsString(':/data/dst/', $log);
        // ...and the secrets materialise() had already written are cleaned up
        // before the guard returns, so nothing is orphaned in tmpfs.
        $this->assertSame([], $leftovers, 'the fail-fast guard cleans up first');
    }

    public function testContimeoutIsDroppedOnANonDaemonRunThroughTheFullRunner(): void
    {
        // rsync main.c:1558 makes --contimeout a HARD failure (exit 1,
        // RERR_SYNTAX) on every remote-shell AND local transfer - only the
        // daemon socket path returns earlier, at main.c:1550. A stored value
        // must therefore be dropped rather than emitted: before this, every
        // LOCAL/SSH job that set it exited 1 before transferring anything. The
        // daemon run above asserts the same option IS emitted there.
        $seenArgv = null;
        Rsync::$runner = function (array $argv, $onOutput) use (&$seenArgv): int {
            $seenArgv = $argv;
            return 0;
        };

        $id  = $this->saveLocalJob('j-local', [
            'rsyncOptions' => Config::mergeRsyncOptions(['contimeout' => '45']),
        ]);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertSame([
            Rsync::rsyncPath(),
            '-r',
            '-h',
            '-t',
            '--partial',
            '--mkpath',
            '--info=progress2',
            '--log-file-format=',
            '--log-file=' . $res['runLog'],
            '--',
            '/mnt/user/src/',
            '/mnt/disk1/dst/',
        ], $seenArgv);
    }

    // =====================================================================
    // Pre-flight (guardrails, target marker, --delete probe), hook deadlines
    // and abortable scheduled runs.
    // =====================================================================

    /** @var array<int,array<int,string>> argv of every fake rsync call, in order */
    private array $argvs = [];

    /**
     * Fake rsync that tells the three kinds of call apart: the marker fetch
     * (--max-size=4K, writes $marker to the destination file, or "not found"
     * when null), the --delete probe (--list-only, emits $listing) and the
     * transfer.
     */
    private function fakeRsync(?string $marker = null, string $listing = '', int $listExit = 0): void
    {
        $this->argvs = [];
        Rsync::$runner = function (array $argv, $onOutput) use ($marker, $listing, $listExit): int {
            $this->argvs[] = $argv;
            if (in_array('--max-size=4K', $argv, true)) {
                $this->trace[] = 'marker';
                if ($marker === null) {
                    $onOutput("rsync: link_stat \"/x\" failed: No such file or directory (2)\n");
                    return 23;
                }
                file_put_contents((string) end($argv), $marker);
                return 0;
            }
            if (in_array('--list-only', $argv, true)) {
                $this->trace[] = 'probe';
                $onOutput($listing);
                return $listExit;
            }
            $this->trace[] = 'rsync';
            return 0;
        };
    }

    /** Seed a KEY-auth SSH connection + job; returns the temp runtime base (caller cleans up). */
    private function seedSshJob(string $jobId, array $jobOverrides = []): string
    {
        $rt = sys_get_temp_dir() . '/ur-runner-pre-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Ssh::$runtimeBase = $rt;
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id' => 'k-1', 'name' => 'k1', 'privateKey' => "KEY\n",
            'publicKey' => 'ssh-ed25519 AAAA fake', 'fingerprint' => 'SHA256:fake',
        ];
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-key', 'name' => 'ckey', 'host' => 'h.example', 'username' => 'root',
            'authMethod' => 'KEY', 'keyId' => 'k-1', 'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
        ]);
        Credentials::save($creds);

        $config = Config::load();
        $job = Config::defaultJob();
        $job['id']           = $jobId;
        $job['name']         = $jobId;
        $job['connectionId'] = 'c-key';
        $job['pairs']        = [['local' => '/mnt/user/src/', 'remote' => '/srv/backup/a']];
        $job['rsyncOptions'] = Config::mergeRsyncOptions(['delete' => true, 'maxDelete' => '50', 'remoteRsyncPath' => '/usr/bin/rsync']);
        $config['jobs'][]    = array_merge($job, $jobOverrides);
        Config::save($config);
        RunState::clear($jobId);
        RunState::clearAbort($jobId);
        return $rt;
    }

    private function runInTempRuntime(string $rt, string $jobId): array
    {
        $orig = Ssh::$runtimeBase;
        Ssh::$runtimeBase = $rt;
        try {
            $res = Runner::run($jobId, false);
            $res['leftoverMarkers'] = glob($rt . '/markers/*') ?: [];
            return $res;
        } finally {
            Ssh::$runtimeBase = $orig === $rt ? '/tmp/unraid.rsync' : $orig;
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);
        }
    }

    private function newRuntime(): string
    {
        return sys_get_temp_dir() . '/ur-runner-pre-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    public function testPreflightRunsMarkerThenProbeBeforeThePreHook(): void
    {
        $this->fakeRsync("uuid-1\n", "drwxr-xr-x          4,096 2026/10/20 12:00:00 .\n-rw-r--r--             12 2026/10/20 12:00:00 notes.txt\n");
        $rt = $this->seedSshJob('j-order', [
            'preHook' => 'PRE', 'postHook' => 'POST',
            'targetMarker' => '/srv/.unraid-rsync-target', 'targetMarkerContent' => 'uuid-1',
        ]);
        $res = $this->runInTempRuntime($rt, 'j-order');

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], (string) @file_get_contents($res['runLog']));
        $this->assertSame(['marker', 'probe', 'hook:PRE:', 'rsync', 'hook:POST:SUCCESS'], $this->trace);
        $this->assertSame([], $res['leftoverMarkers'], 'the fetched marker is removed');

        [$marker, $probe] = $this->argvs;
        $this->assertSame('root@h.example:/srv/.unraid-rsync-target', $marker[count($marker) - 2]);
        // The probe uses the transfer's transport and --rsync-path, one level deep.
        $this->assertContains('--list-only', $probe);
        $this->assertContains('--rsync-path=/usr/bin/rsync', $probe);
        $this->assertContains('-e', $probe);
        $this->assertNotContains('-r', $probe);
        $this->assertSame(['--', 'root@h.example:/srv/backup/a/'], array_slice($probe, -2));
        $this->assertSame($probe[array_search('-e', $probe, true) + 1], $this->argvs[2][array_search('-e', $this->argvs[2], true) + 1]);
    }

    public function testAGuardrailOnALaterPairFailsBeforeThePreHookAndAnyCopy(): void
    {
        $this->fakeRsync();
        $id = $this->saveLocalJob('j-guard', [
            'preHook' => 'PRE', 'postHook' => 'POST',
            'pairs'   => [
                ['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/'],
                ['local' => '/mnt/user/b/', 'remote' => '/mnt/disk1/b/'],
                ['local' => '/mnt/user/c/', 'remote' => '/boot/c'],
            ],
        ]);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('guardrail', $res['reason'] ?? '');
        $this->assertSame(['hook:POST:FAILED'], $this->trace, 'no pre-hook, no copy; the post-hook still runs');
        $this->assertStringContainsString('Pair #3', (string) file_get_contents($res['runLog']));
    }

    /** @return array<string,array{0:array<string,string>}> */
    public static function badGlobalReceiverDirProvider(): array
    {
        return [
            'tempDir root'      => [['tempDir' => '/']],
            'backupDir dotdots' => [['backupDir' => '../../../../']],
        ];
    }

    #[DataProvider('badGlobalReceiverDirProvider')]
    public function testTempDirAndBackupDirAreReCheckedAtRunTimeOnTheEffectiveOptions(array $globalOpts): void
    {
        $this->fakeRsync();
        $config = Config::load();
        $config['global']['defaultRsyncOptions'] = Config::mergeRsyncOptions($globalOpts);
        Config::save($config);
        $rt = $this->seedSshJob('j-recv', ['useGlobalDefaults' => true, 'preHook' => 'PRE', 'rsyncOptions' => Config::defaultRsyncOptions()]);
        $res = $this->runInTempRuntime($rt, 'j-recv');

        $this->assertSame('guardrail', $res['reason'] ?? '');
        $this->assertSame([], $this->trace);
    }

    public function testAncestryIsReEnforcedAtRunTime(): void
    {
        $this->fakeRsync();
        $this->saveLocalJob('j-inner', ['pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/dst/inner']]]);
        $id = $this->saveLocalJob('j-outer', [
            'rsyncOptions' => Config::mergeRsyncOptions(['delete' => true, 'maxDelete' => '5']),
            'pairs'        => [['local' => '/mnt/user/b/', 'remote' => '/mnt/disk1/dst/']],
        ]);
        $res = Runner::run($id, false);

        $this->assertSame('guardrail', $res['reason'] ?? '');
        $this->assertSame([], $this->trace);
        $this->assertStringContainsString('job "j-inner"', (string) file_get_contents($res['runLog']));
    }

    /** @return array<string,mixed> a stored, enabled snapshot job's own fields */
    private function snapshotFields(): array
    {
        return [
            'targetMarker' => '/srv/backup/.unraid-rsync-target', 'targetMarkerContent' => 'uuid-1',
            'pairs'        => [['local' => '/mnt/user/src/', 'remote' => '', 'sub' => 'src', 'seed' => true]],
            'rsyncOptions' => Config::mergeRsyncOptions(['recursive' => true, 'times' => true, 'mkpath' => false]),
            'snapshot'     => ['enabled' => true, 'root' => '/srv/backup/snapshots/snap'] + Config::defaultSnapshot(),
        ];
    }

    /**
     * Golden: snapshot mode must leave a mirror run byte-for-byte as it was -
     * the transfer argv, both hook environments, the History record, the
     * summary and the notification. Every expected value below was produced
     * by the code before snapshot runs existed.
     */
    public function testAMirrorRunIsUnchangedBySnapshotMode(): void
    {
        $this->fakeRsync("uuid-1\n", "drwxr-xr-x          4,096 2026/10/20 12:00:00 .\n");
        $fakeBin = sys_get_temp_dir() . '/ur-rnotify-golden-' . getmypid() . '-' . bin2hex(random_bytes(4));
        file_put_contents($fakeBin, "#!/bin/sh\nexit 0\n");
        chmod($fakeBin, 0755);
        $origPath = Notify::$notifyPath;
        $captured = [];
        Notify::$notifyPath = $fakeBin;
        Notify::$runner = function (string $command) use (&$captured): int {
            $captured[] = $command;
            return 0;
        };
        $envs = [];
        Runner::$hookRunner = function (string $hook, array $env) use (&$envs): int {
            $envs[$hook] = $env;
            return 0;
        };

        try {
            $rt = $this->seedSshJob('j-golden', [
                'name' => 'golden', 'preHook' => 'PRE', 'postHook' => 'POST', 'notifyMode' => 'always',
                'targetMarker' => '/srv/.unraid-rsync-target', 'targetMarkerContent' => 'uuid-1',
            ]);
            Ssh::$runtimeBase = $rt;
            $res = Runner::run('j-golden', false, 'schedule');
            $log = $res['runLog'];
            Ssh::$runtimeBase = '/tmp/unraid.rsync';
            Credentials::save(Credentials::defaults());
            $this->rmTree($rt);

            $this->assertSame(['state' => 'SUCCESS', 'exitCode' => 0, 'runLog' => $log], $res);
            $argv = $this->argvs[2];
            $e    = $argv[array_search('-e', $argv, true) + 1];
            $this->assertMatchesRegularExpression("#^'ssh' '-i' '[^']+' .* '-p' '22'\$#", $e);
            $this->assertSame([
                PHP_BINARY, '-r', '-h', '-t', '--partial', '--delete', '--mkpath', '--max-delete=50', '--rsync-path=/usr/bin/rsync',
                '--info=progress2', '--log-file-format=', '--log-file=' . $log, '-e', $e, '--', '/mnt/user/src/', 'root@h.example:/srv/backup/a',
            ], $argv);
            $base = ['UR_JOB_ID' => 'j-golden', 'UR_JOB_NAME' => 'golden', 'UR_DRY_RUN' => '0', 'UR_TRIGGER' => 'schedule'];
            $this->assertSame($base + ['UR_JOB_STATUS' => '', 'UR_EXIT_CODE' => ''], $envs['PRE']);
            $this->assertSame($base + ['UR_JOB_STATUS' => 'SUCCESS', 'UR_EXIT_CODE' => '0'], $envs['POST']);

            $row = History::list('j-golden', 0, 1)['runs'][0];
            $this->assertSame(
                ['startedAt', 'finishedAt', 'jobName', 'dryRun', 'trigger', 'state', 'exitCode', 'durationSec', 'logRef', 'reason'],
                array_keys($row)
            );
            $this->assertSame(['golden', false, 'schedule', 'SUCCESS', 0, basename($log), ''], [
                $row['jobName'], $row['dryRun'], $row['trigger'], $row['state'], $row['exitCode'], $row['logRef'], $row['reason'],
            ]);
            $line = (string) file_get_contents(History::path('j-golden'));
            $this->assertSame(1, preg_match('/^\{"startedAt":"[^"]+","finishedAt":"[^"]+","jobName":"golden","dryRun":false,"trigger":"schedule","state":"SUCCESS","exitCode":0,"durationSec":\d+,"logRef":"run-[^"]+\.log","reason":""\}\n$/D', $line), $line);
            $this->assertSame(
                ['state', 'startedAt', 'finishedAt', 'exitCode', 'durationSec', 'dryRun', 'trigger'],
                array_keys((array) Runner::readSummary('j-golden'))
            );

            $this->assertCount(1, $captured);
            $this->assertSame(
                "'$fakeBin' '-e' 'Unraid Rsync' '-s' 'Unraid Rsync: golden SUCCESS' '-d' 'Job \"golden\" finished. State: SUCCESS. "
                . "Exit code: 0 = success. Duration: Ns. Trigger: schedule. Dry-run: no.' '-i' 'normal' '-l' '/Settings/UnraidRsync'",
                preg_replace('/Duration: \d+s\./', 'Duration: Ns.', $captured[0])
            );
        } finally {
            Notify::$notifyPath = $origPath;
            Notify::$runner = null;
            @unlink($fakeBin);
            @unlink(History::path('j-golden'));
        }
    }

    public function testTheInflightRecordOfAMirrorHasNoSnapshotKey(): void
    {
        $this->fakeRsync();
        $seen = null;
        Runner::$hookRunner = function (string $hook) use (&$seen): int {
            $seen = RunState::readInflight('j-inflight');
            return 0;
        };
        $rt = $this->seedSshJob('j-inflight', ['preHook' => 'PRE', 'notifyMode' => 'off', 'rsyncOptions' => Config::mergeRsyncOptions([])]);
        $this->runInTempRuntime($rt, 'j-inflight');
        $this->assertSame(['jobId', 'pid', 'startedAt', 'trigger', 'dryRun', 'currentLog'], array_keys((array) $seen));
    }

    public function testAMirrorInsideASnapshotRootIsRefusedAtRunTime(): void
    {
        $this->fakeRsync();
        $config = Config::load();
        $config['jobs'][] = array_merge(Config::defaultJob(), ['id' => 'j-snap', 'name' => 'snap', 'connectionId' => 'c-key'], $this->snapshotFields());
        Config::save($config);
        $rt  = $this->seedSshJob('j-stray', [
            'notifyMode'   => 'off',
            'pairs'        => [['local' => '/mnt/user/src/', 'remote' => '/srv/backup/snapshots/snap/stray']],
            'rsyncOptions' => Config::mergeRsyncOptions([]),
        ]);
        $res = $this->runInTempRuntime($rt, 'j-stray');

        $this->assertSame('guardrail', $res['reason'] ?? '');
        $this->assertSame([], $this->argvs);
        $this->assertStringContainsString(
            'Pair #1: the destination must not be or lie inside the snapshot root of job "snap"',
            (string) file_get_contents($res['runLog'])
        );
    }

    public function testADeleteMirrorOverASnapshotRootsParentIsRefusedAtRunTime(): void
    {
        $this->fakeRsync();
        $config = Config::load();
        $config['jobs'][] = array_merge(Config::defaultJob(), ['id' => 'j-snap', 'name' => 'snap', 'connectionId' => 'c-key'], $this->snapshotFields());
        Config::save($config);
        // seedSshJob's mirror has --delete on.
        $rt  = $this->seedSshJob('j-over', [
            'notifyMode' => 'off',
            'pairs'      => [['local' => '/mnt/user/src/', 'remote' => '/srv/backup/snapshots']],
        ]);
        $res = $this->runInTempRuntime($rt, 'j-over');

        $this->assertSame('guardrail', $res['reason'] ?? '');
        $this->assertSame([], $this->argvs, 'refused before the marker fetch and the --delete probe');
        $this->assertStringContainsString(
            "must not be, contain, or lie inside the snapshot root of job \"snap\" ('/srv/backup/snapshots/snap')",
            (string) file_get_contents($res['runLog'])
        );
    }

    /** @return array<string,array{0:?string,1:string,2:bool}> */
    public static function markerProvider(): array
    {
        return [
            'missing'          => [null, '', false],
            'wrong content'    => ["other\n", 'uuid-1', false],
            'present, no want' => ["anything\n", '', true],
            'matching content' => ["uuid-1\n", 'uuid-1', true],
        ];
    }

    #[DataProvider('markerProvider')]
    public function testTargetMarkerGatesTheRunBeforeThePreHook(?string $body, string $expected, bool $ok): void
    {
        $this->fakeRsync($body);
        $rt = $this->newRuntime();
        $overrides = ['preHook' => 'PRE', 'postHook' => 'POST', 'targetMarker' => '/mnt/disk1/.unraid-rsync-target'];
        if ($expected !== '') {
            $overrides['targetMarkerContent'] = $expected;
        }
        $id  = $this->saveLocalJob('j-marker', $overrides);
        $res = $this->runInTempRuntime($rt, $id);

        $this->assertSame([], $res['leftoverMarkers']);
        // A LOCAL job's marker is a plain local path: no transport pieces.
        $this->assertSame('/mnt/disk1/.unraid-rsync-target', $this->argvs[0][count($this->argvs[0]) - 2]);
        $this->assertNotContains('-e', $this->argvs[0]);
        if ($ok) {
            $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
            $this->assertSame(['marker', 'hook:PRE:', 'rsync', 'hook:POST:SUCCESS'], $this->trace);
        } else {
            $this->assertSame(Rsync::STATE_FAILED, $res['state']);
            $this->assertSame('dest-not-mounted', $res['reason'] ?? '');
            $this->assertSame(['marker', 'hook:POST:FAILED'], $this->trace);
        }
    }

    /** @return array<string,array{0:string,1:int,2:string}> */
    public static function probeProvider(): array
    {
        $dir = "drwxr-xr-x          4,096 2026/10/20 12:00:00 ";
        return [
            'snapshot store'   => [$dir . ".\n" . $dir . "snapshots\n", 0, 'dest-is-protected'],
            'snapshot root'    => [$dir . ".\n-rw-r--r--              0 2026/10/20 12:00:00 .ur-snapshot-root\n", 0, 'dest-is-protected'],
            'drive root'       => ["-rw-r--r--             36 2026/10/20 12:00:00 .unraid-rsync-target\n", 0, 'dest-is-protected'],
            'ordinary dir'     => [$dir . ".\n" . $dir . "snapshots-old\n", 0, ''],
            'not there yet'    => ["rsync: [sender] change_dir \"/srv/backup/a\" failed: No such file or directory (2)\n", 23, ''],
            'unreachable host' => ["ssh: connect to host h.example port 22: Connection refused\n", 255, 'dest-probe-failed'],
        ];
    }

    #[DataProvider('probeProvider')]
    public function testDeleteProbeRefusesAProtectedDestinationBeforeThePreHook(string $listing, int $exit, string $reason): void
    {
        $this->fakeRsync(null, $listing, $exit);
        $rt  = $this->seedSshJob('j-probe', ['preHook' => 'PRE']);
        $res = $this->runInTempRuntime($rt, 'j-probe');

        $this->assertSame($reason, $res['reason'] ?? '');
        $this->assertSame($reason === '' ? ['probe', 'hook:PRE:', 'rsync'] : ['probe'], $this->trace);
    }

    public function testNoProbeForAJobWithoutDelete(): void
    {
        $this->fakeRsync();
        $rt  = $this->seedSshJob('j-noprobe', ['rsyncOptions' => Config::defaultRsyncOptions()]);
        $res = $this->runInTempRuntime($rt, 'j-noprobe');
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertSame(['rsync'], $this->trace);
    }

    public function testListingName(): void
    {
        $this->assertSame('snapshots', Runner::listingName('drwxr-xr-x          4,096 2026/10/20 12:00:00 snapshots'));
        $this->assertSame('a b', Runner::listingName("-rw-r--r--              1 2026/10/20 12:00:00 a b\r"));
        $this->assertSame('snapshots', Runner::listingName('lrwxrwxrwx              9 2026/10/20 12:00:00 snapshots -> /x/y'));
        $this->assertSame('', Runner::listingName('rsync: change_dir failed: snapshots'));
        $this->assertSame('', Runner::listingName(''));
    }

    public function testPreHookTimeoutFailsTheRunAndSkipsThePairs(): void
    {
        $timeouts = [];
        Runner::$hookRunner = function (string $hook, array $env, $onOutput, int $timeoutSec = -9) use (&$timeouts): int {
            $this->trace[] = 'hook:' . $hook;
            $timeouts[$hook] = $timeoutSec;
            return $hook === 'PRE' ? Runner::HOOK_TIMED_OUT : 0;
        };
        $this->fakeRsync();
        $id  = $this->saveLocalJob('j-pre', ['preHook' => 'PRE', 'postHook' => 'POST']);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('prehook-timeout', $res['reason'] ?? '');
        $this->assertSame(124, $res['exitCode']);
        $this->assertSame(['hook:PRE', 'hook:POST'], $this->trace);
        $this->assertSame(['PRE' => 30 * 60, 'POST' => 60 * 60], $timeouts, 'defaults: 30 and 60 minutes');
        $this->assertStringContainsString('Pre-run hook timed out after 30 minute(s)', (string) file_get_contents($res['runLog']));
    }

    public function testPostHookTimeoutKeepsTheOutcomeButWarnsAndHonoursTheSetting(): void
    {
        $timeouts = [];
        Runner::$hookRunner = function (string $hook, array $env, $onOutput, int $timeoutSec = -9) use (&$timeouts): int {
            $timeouts[$hook] = $timeoutSec;
            return $hook === 'POST' ? Runner::HOOK_TIMED_OUT : 0;
        };
        $this->fakeRsync();
        $config = Config::load();
        $config['global']['preHookTimeout']  = 0;
        $config['global']['postHookTimeout'] = 5;
        Config::save($config);
        $id  = $this->saveLocalJob('j-pre', ['preHook' => 'PRE', 'postHook' => 'POST']);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_WARNING, $res['state']);
        $this->assertSame('posthook-timeout', $res['reason'] ?? '');
        $this->assertSame(0, $res['exitCode']);
        $this->assertSame(['PRE' => 0, 'POST' => 300], $timeouts, '0 = no limit');

        // A failed run stays FAILED.
        $this->fakeRsync();
        Rsync::$runner = static fn(array $argv, $onOutput): int => 12;
        $res = Runner::run($id, false);
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('posthook-timeout', $res['reason'] ?? '');
    }

    public function testPostHookTimeoutKeepsThePrimaryReason(): void
    {
        Runner::$hookRunner = function (string $hook, array $env, $onOutput, int $timeoutSec = -9): int {
            $this->trace[] = 'hook:' . $hook;
            return $hook === 'PRE' ? 1 : Runner::HOOK_TIMED_OUT;
        };
        $this->fakeRsync();
        $id  = $this->saveLocalJob('j-pre', ['preHook' => 'PRE', 'postHook' => 'POST']);
        $res = Runner::run($id, false);

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('prehook-failed', $res['reason'] ?? '');
        $this->assertSame(['hook:PRE', 'hook:POST'], $this->trace);
    }

    public function testRunHookProcessKillsAHookPastItsDeadline(): void
    {
        $out = '';
        $t0  = microtime(true);
        $code = Runner::runHookProcess('echo started; sleep 30', [], static function (string $c) use (&$out): void {
            $out .= $c;
        }, 1);
        $this->assertSame(Runner::HOOK_TIMED_OUT, $code);
        $this->assertLessThan(10, microtime(true) - $t0);
        $this->assertStringContainsString('started', $out);
    }

    public function testRunHookProcessEscalatesToSigkill(): void
    {
        $prev = Runner::$hookKillGraceSec;
        Runner::$hookKillGraceSec = 1;
        try {
            $t0   = microtime(true);
            $code = Runner::runHookProcess("trap '' TERM; while :; do sleep 0.2; done", [], static function (string $c): void {
            }, 1);
            $this->assertSame(Runner::HOOK_TIMED_OUT, $code);
            $this->assertLessThan(10, microtime(true) - $t0);
        } finally {
            Runner::$hookKillGraceSec = $prev;
        }
    }

    public function testRunHookProcessKillsTheWholeHookProcessGroup(): void
    {
        if (!is_executable('/usr/bin/setsid') && !is_executable('/bin/setsid')) {
            $this->markTestSkipped('needs util-linux setsid (present on Unraid and in CI)');
        }
        $pidFile = tempnam(sys_get_temp_dir(), 'ur-hook-');
        try {
            Runner::runHookProcess('sleep 60 & echo $! > ' . escapeshellarg($pidFile) . '; wait', [], static function (string $c): void {
            }, 1);
            $child = (int) trim((string) file_get_contents($pidFile));
            $this->assertGreaterThan(0, $child);
            $alive = true;
            for ($i = 0; $i < 30 && $alive; $i++) {
                $alive = @posix_kill($child, 0);
                usleep(100000);
            }
            $this->assertFalse($alive, 'the hook\'s own child is killed with it');
        } finally {
            @unlink($pidFile);
        }
    }

    public function testRunHookProcessStopsOnAbortAndPassesExitCodesThrough(): void
    {
        $noop = static function (string $c): void {
        };
        $this->assertSame(143, Runner::runHookProcess('sleep 30', [], $noop, 0, static fn(): bool => true));
        $this->assertSame(3, Runner::runHookProcess('exit 3', [], $noop, 60));
        $this->assertSame(0, Runner::runHookProcess('[ "$UR_X" = y ]', ['UR_X' => 'y'], $noop));
    }

    public function testAHookKilledByASignalReportsTheSignalNotExit1(): void
    {
        $noop = static function (string $c): void {
        };
        $this->assertSame(137, Runner::runHookProcess('kill -KILL $$', [], $noop, 60));
        $this->assertSame(143, Runner::runHookProcess('kill -TERM $$', [], $noop));
    }

    public function testRunnerBecomesASessionLeaderOnlyWhenItIsNotOne(): void
    {
        if (!defined('UR_RUNNER_TESTING')) {
            define('UR_RUNNER_TESTING', true);
        }
        require_once __DIR__ . '/../source/scripts/runner.php';

        $calls = 0;
        $posix = static function (int $sid) use (&$calls): array {
            return [
                'getsid' => static fn(): int => $sid,
                'setsid' => static function () use (&$calls): int {
                    $calls++;
                    return 4242;
                },
                'pid'    => 4242,
            ];
        };
        // Started by crond: some other process leads the session.
        $this->assertTrue(ur_runner_ensure_session_leader($posix(100)));
        $this->assertSame(1, $calls);
        // Started by the UI launcher under setsid: already the leader.
        $this->assertFalse(ur_runner_ensure_session_leader($posix(4242)));
        $this->assertSame(1, $calls);
    }

    /** @return array<string,array{0:string}> */
    public static function triggerProvider(): array
    {
        return ['manual' => ['manual'], 'schedule' => ['schedule']];
    }

    #[DataProvider('triggerProvider')]
    public function testAbortDuringAPairYieldsAbortedForEveryTrigger(string $trigger): void
    {
        $id = $this->saveLocalJob('j-abort-' . $trigger, ['postHook' => 'POST']);
        RunState::clearAbort($id);
        Rsync::$runner = function (array $argv, $onOutput) use ($id): int {
            RunState::requestAbort($id);
            return 143;
        };
        $res = Runner::run($id, false, $trigger);
        $this->assertSame(Rsync::STATE_ABORTED, $res['state']);
        $this->assertSame($trigger, Runner::readSummary($id)['trigger']);
        $this->assertContains('hook:POST:ABORTED', $this->trace);
        $this->assertFalse(RunState::abortRequested($id));
    }
}

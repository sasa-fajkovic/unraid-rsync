<?php

use PHPUnit\Framework\TestCase;

/**
 * Phase 6 tests for handler.php read-only GET pollers:
 *   - getStatus returns the correct per-job shape (running / state / lastRun /
 *     nextRun) with RUNNING overriding the summary and PENDING when none;
 *   - getJobLog returns an HTML-ESCAPED tail + the live running flag, and a
 *     traversing run id is rejected (400);
 *   - listRuns returns the last-N runs newest-first;
 *   - getPluginLog returns the HTML-escaped rolling log;
 *   - the GET pollers reject a POST (405);
 *   - state derivation (ur_derive_state) + the job-id whitelist (ur_safe_job_id).
 *
 * handler.php is included with UR_HANDLER_TESTING so its helpers never exit and
 * the front controller doesn't auto-dispatch. Logger is pointed at a per-test
 * runtime dir so run/plugin logs don't leak between tests.
 */
final class HandlerStatusTest extends TestCase
{
    private string $rtBase;

    public static function setUpBeforeClass(): void
    {
        if (!defined('UR_HANDLER_TESTING')) {
            define('UR_HANDLER_TESTING', true);
        }
        require_once __DIR__ . '/../source/include/handler.php';
    }

    protected function setUp(): void
    {
        $_POST = [];
        $_GET  = [];
        // REQUEST_METHOD is a global mutated by the dispatch tests; reset it each
        // test so a leftover value can't make a later ur_handle_request() flaky.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        // Reset the handler's intended-status-code test seam instead of calling
        // http_response_code(200), which warns under CLI/PHP 8.4 once output has
        // begun (failOnWarning would fail the test). See sendResponse.
        $GLOBALS['ur_last_response_code'] = 200;
        $GLOBALS['var'] = ['csrf_token' => 'test-token'];

        $this->rtBase = sys_get_temp_dir() . '/ur-hstatus-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Logger::$baseOverride = $this->rtBase;

        // Clean config + any run summaries from a previous test.
        Config::save(Config::defaults());
        $runsDir = rtrim(UR_CONFIG_BASE, '/') . '/runs';
        if (is_dir($runsDir)) {
            foreach (glob($runsDir . '/*.summary.json') as $f) {
                @unlink($f);
            }
        }
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

    private function runCapture(callable $fn): array
    {
        ob_start();
        $fn();
        $out = ob_get_clean();
        return [json_decode($out, true), (int) ($GLOBALS['ur_last_response_code'] ?? 200)];
    }

    /** Seed config with a job (LOCAL, enabled, valid cron) and return its id. */
    private function seedJob(string $name, array $overrides = []): string
    {
        $config = Config::load();
        $job = array_merge([
            'name'      => $name,
            'enabled'   => true,
            'schedule'  => '0 3 * * *',
            'transport' => 'LOCAL',
            'direction' => 'PUSH',
            'pairs'     => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']],
        ], $overrides);
        $job = Job::normalize($job);
        $config['jobs'][] = $job;
        Config::save($config);
        return $job['id'];
    }

    // ---- ur_safe_job_id whitelist -----------------------------------------

    public function testSafeJobIdAcceptsSlugs(): void
    {
        $this->assertSame('j-music', ur_safe_job_id('j-music'));
        $this->assertSame('j-Music_2.bak', ur_safe_job_id('j-Music_2.bak'));
    }

    /**
     * @dataProvider badJobIds
     */
    public function testSafeJobIdRejectsUnsafe(string $bad): void
    {
        $this->assertSame('', ur_safe_job_id($bad));
    }

    public static function badJobIds(): array
    {
        return [
            'traversal'  => ['../etc'],
            'slash'      => ['j/music'],
            'nul'        => ["j-music\0"],
            'dotdot'     => ['..'],
            'dot'        => ['.'],
            'space'      => ['j music'],
            'empty'      => [''],
        ];
    }

    // ---- ur_php_binary (detached-runner interpreter) ----------------------

    public function testPhpBinaryReturnsUsableInterpreter(): void
    {
        $php = ur_php_binary();
        $this->assertIsString($php);
        $this->assertNotSame('', $php);
        // If it returned an absolute path, that path must be executable; if it
        // returned a bare "php", it relies on PATH. Either way, never empty.
        if (strpos($php, '/') !== false) {
            $this->assertTrue(is_executable($php), "$php should be executable");
        }
        // The test process is CLI, so it should prefer the running CLI binary.
        if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && is_executable(PHP_BINARY)) {
            $this->assertSame(PHP_BINARY, $php);
        }
    }

    public function testRunnerClassLoadedByHandler(): void
    {
        // Regression guard for the live "Class Runner not found" 500: getStatus
        // calls Runner::readSummary, so handler.php must require Runner itself
        // (there is no autoloader). Asserting class_exists() in THIS process is
        // meaningless (the bootstrap already loaded Runner), so we load ONLY
        // handler.php in a fresh PHP process and check class_exists(...,false)
        // with autoload disabled - exactly the live condition.
        $repo    = dirname(__DIR__);
        $handler = $repo . '/source/include/handler.php';
        $code    = 'define("UR_HANDLER_TESTING", true); require ' . var_export($handler, true) . ';'
            . ' echo class_exists("Runner", false) ? "RUNNER_OK" : "RUNNER_MISSING";';
        $cmd = escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($code) . ' 2>&1';
        $out = (string) shell_exec($cmd);
        $this->assertStringContainsString('RUNNER_OK', $out, "handler.php must require Runner; got: $out");
    }

    public function testEnvDiagReportsEnvironmentFacts(): void
    {
        [$body, $code] = $this->runCapture('ur_action_env_diag');
        $this->assertSame(200, $code);
        $this->assertTrue($body['ok']);
        foreach (['phpSapi', 'resolvedPhpBinary', 'runnerScript', 'procOpenEnabled', 'updateCronPath', 'updateCronIsFile'] as $k) {
            $this->assertArrayHasKey($k, $body);
        }
        $this->assertNotSame('', $body['resolvedPhpBinary']);
    }

    // ---- ur_derive_state ---------------------------------------------------

    public function testDeriveStateRunningOverridesSummary(): void
    {
        $this->assertSame('RUNNING', ur_derive_state(true, ['state' => 'FAILED']));
        $this->assertSame('RUNNING', ur_derive_state(true, null));
    }

    public function testDeriveStateUsesSummaryWhenNotRunning(): void
    {
        $this->assertSame('SUCCESS', ur_derive_state(false, ['state' => 'SUCCESS']));
        $this->assertSame('WARNING', ur_derive_state(false, ['state' => 'WARNING']));
    }

    public function testDeriveStatePendingWhenNoSummary(): void
    {
        $this->assertSame('PENDING', ur_derive_state(false, null));
        $this->assertSame('PENDING', ur_derive_state(false, []));            // empty summary
        $this->assertSame('PENDING', ur_derive_state(false, ['state' => ''])); // blank state
    }

    // ---- getStatus ---------------------------------------------------------

    public function testGetStatusPendingJobNoSummary(): void
    {
        $id = $this->seedJob('Photos');
        [$body, $code] = $this->runCapture('ur_action_get_status');

        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertArrayHasKey($id, $body['jobs']);
        $entry = $body['jobs'][$id];
        $this->assertSame('Photos', $entry['name']); // name surfaced for the UI
        $this->assertTrue($entry['enabled']);         // enabled flag surfaced for the Next-run cell
        $this->assertFalse($entry['running']);
        $this->assertSame('PENDING', $entry['state']);
        $this->assertNull($entry['lastRun']);
        // Enabled job with a valid cron -> a numeric nextRun epoch.
        $this->assertIsInt($entry['nextRun']);
        $this->assertGreaterThan(time(), $entry['nextRun']);
    }

    public function testGetStatusCarriesEnabledFlag(): void
    {
        // The UI renders "disabled" in the Next-run cell off this flag (distinct
        // from an enabled job whose nextRun is null), so it must be present and
        // accurate for both enabled and disabled jobs.
        $onId  = $this->seedJob('On');
        $offId = $this->seedJob('Off', ['enabled' => false]);
        [$body] = $this->runCapture('ur_action_get_status');
        $this->assertTrue($body['jobs'][$onId]['enabled']);
        $this->assertFalse($body['jobs'][$offId]['enabled']);
    }

    public function testGetStatusReflectsLastRunSummary(): void
    {
        $id = $this->seedJob('Docs');
        Runner::writeSummary($id, [
            'state'       => 'SUCCESS',
            'startedAt'   => '2025-01-01T03:00:00Z',
            'finishedAt'  => '2025-01-01T03:05:00Z',
            'exitCode'    => 0,
            'durationSec' => 300,
            'dryRun'      => false,
        ]);

        [$body, $code] = $this->runCapture('ur_action_get_status');
        $this->assertSame(200, $code);
        $entry = $body['jobs'][$id];
        $this->assertSame('SUCCESS', $entry['state']);
        $this->assertIsArray($entry['lastRun']);
        $this->assertSame('SUCCESS', $entry['lastRun']['state']);
        $this->assertSame(0, $entry['lastRun']['exitCode']);
        $this->assertSame(300, $entry['lastRun']['durationSec']);
        $this->assertFalse($entry['lastRun']['dryRun']);
    }

    public function testGetStatusNextRunNullForDisabledJob(): void
    {
        $id = $this->seedJob('Off', ['enabled' => false]);
        [$body] = $this->runCapture('ur_action_get_status');
        $this->assertNull($body['jobs'][$id]['nextRun']);
    }

    public function testGetStatusHandlesPartialSummaryGracefully(): void
    {
        $id = $this->seedJob('Partial');
        // Write a deliberately partial/corrupt summary file directly.
        $runsDir = rtrim(UR_CONFIG_BASE, '/') . '/runs';
        @mkdir($runsDir, 0777, true);
        file_put_contents($runsDir . '/' . $id . '.summary.json', json_encode(['state' => 'WARNING']));

        [$body, $code] = $this->runCapture('ur_action_get_status');
        $this->assertSame(200, $code);
        $entry = $body['jobs'][$id];
        $this->assertSame('WARNING', $entry['state']);
        // Missing fields default safely.
        $this->assertSame('', $entry['lastRun']['startedAt']);
        $this->assertSame(0, $entry['lastRun']['exitCode']);
    }

    // ---- getJobLog ---------------------------------------------------------

    public function testGetJobLogReturnsEscapedTail(): void
    {
        $id = $this->seedJob('Logged');
        $path = Logger::openRun($id, 1750000000);
        Logger::append($path, '<script>alert(1)</script> rsync: done');

        $_GET = ['id' => $id];
        [$body, $code] = $this->runCapture('ur_action_get_job_log');
        $this->assertSame(200, $code);
        $this->assertTrue($body['ok']);
        $this->assertFalse($body['running']);
        $this->assertStringNotContainsString('<script>', $body['log']);
        $this->assertStringContainsString('&lt;script&gt;', $body['log']);
        $this->assertStringContainsString('rsync: done', $body['log']);
    }

    public function testGetJobLogServesSelectedRun(): void
    {
        $id = $this->seedJob('Multi');
        $old = Logger::openRun($id, 1750000000);
        Logger::append($old, 'OLD-RUN-MARKER');
        $new = Logger::openRun($id, 1750000600);
        Logger::append($new, 'NEW-RUN-MARKER');

        // Default (no run) -> latest run.
        $_GET = ['id' => $id];
        [$body] = $this->runCapture('ur_action_get_job_log');
        $this->assertStringContainsString('NEW-RUN-MARKER', $body['log']);
        $this->assertStringNotContainsString('OLD-RUN-MARKER', $body['log']);

        // Explicit older run id.
        $_GET = ['id' => $id, 'run' => basename($old)];
        [$body2] = $this->runCapture('ur_action_get_job_log');
        $this->assertStringContainsString('OLD-RUN-MARKER', $body2['log']);
        $this->assertSame(basename($old), $body2['run']);
        // The job isn't running, so the served (older) log is never "live".
        $this->assertFalse($body2['running']);
    }

    public function testGetJobLogNotLiveForNonRunningJob(): void
    {
        // Even with a stale state file naming a currentLog, a job that isn't
        // actually running must report running=false for its served log (the
        // log being returned is not being written).
        $id = $this->seedJob('Stale');
        $path = Logger::openRun($id, 1750000000);
        RunState::write($id, [
            'pid'        => 999999, // not our runner -> isRunning() self-heals to false
            'running'    => true,
            'dryRun'     => false,
            'startedAt'  => '2025-01-01T00:00:00Z',
            'currentLog' => $path,
        ]);
        $_GET = ['id' => $id];
        [$body] = $this->runCapture('ur_action_get_job_log');
        $this->assertFalse($body['running']);
        RunState::clear($id);
    }

    public function testGetJobLogRejectsTraversalRunId(): void
    {
        $id = $this->seedJob('Trav');
        $_GET = ['id' => $id, 'run' => '../../etc/passwd'];
        [$body, $code] = $this->runCapture('ur_action_get_job_log');
        $this->assertSame(400, $code);
        $this->assertArrayHasKey('error', $body);
    }

    public function testGetJobLogRejectsBadJobId(): void
    {
        $_GET = ['id' => '../evil'];
        [, $code] = $this->runCapture('ur_action_get_job_log');
        $this->assertSame(400, $code);
    }

    public function testGetJobLogMissingLogReturnsEmpty(): void
    {
        $id = $this->seedJob('NoLogYet');
        $_GET = ['id' => $id];
        [$body, $code] = $this->runCapture('ur_action_get_job_log');
        $this->assertSame(200, $code);
        $this->assertTrue($body['ok']);
        $this->assertSame('', $body['log']);
    }

    // ---- listRuns ----------------------------------------------------------

    public function testListRunsNewestFirstLastN(): void
    {
        $id = $this->seedJob('Runs');
        for ($i = 0; $i < 12; $i++) {
            Logger::openRun($id, 1750000000 + $i * 60);
        }
        $_GET = ['id' => $id, 'limit' => 5];
        [$body, $code] = $this->runCapture('ur_action_list_runs');
        $this->assertSame(200, $code);
        $this->assertCount(5, $body['runs']);
        // Newest first.
        $this->assertSame('run-' . gmdate('Ymd\THis\Z', 1750000000 + 11 * 60) . '.log', $body['runs'][0]['id']);
        for ($i = 1; $i < count($body['runs']); $i++) {
            $this->assertGreaterThanOrEqual($body['runs'][$i]['ts'], $body['runs'][$i - 1]['ts']);
        }
    }

    public function testListRunsRejectsBadJobId(): void
    {
        $_GET = ['id' => 'j/../x'];
        [, $code] = $this->runCapture('ur_action_list_runs');
        $this->assertSame(400, $code);
    }

    // ---- getPluginLog ------------------------------------------------------

    public function testGetPluginLogEscapes(): void
    {
        $path = Logger::openRun('j-x', 1750000000);
        Logger::event($path, 'j-x', '<b>danger</b> & co');
        [$body, $code] = $this->runCapture('ur_action_get_plugin_log');
        $this->assertSame(200, $code);
        $this->assertTrue($body['ok']);
        $this->assertStringNotContainsString('<b>', $body['log']);
        $this->assertStringContainsString('&lt;b&gt;', $body['log']);
        $this->assertStringContainsString('&amp;', $body['log']);
    }

    // ---- getRsyncStatus (FIX 3: presence check) ---------------------------

    public function testGetRsyncStatusPresent(): void
    {
        Rsync::$rsyncPathOverride = PHP_BINARY; // present
        try {
            [$body, $code] = $this->runCapture('ur_action_get_rsync_status');
            $this->assertSame(200, $code);
            $this->assertTrue($body['ok']);
            $this->assertTrue($body['available']);
            $this->assertSame(PHP_BINARY, $body['path']);
            $this->assertSame('', $body['message']);
        } finally {
            Rsync::$rsyncPathOverride = null;
        }
    }

    public function testGetRsyncStatusMissing(): void
    {
        Rsync::$rsyncPathOverride = '/nonexistent/path/to/rsync';
        try {
            [$body, $code] = $this->runCapture('ur_action_get_rsync_status');
            $this->assertSame(200, $code);
            $this->assertTrue($body['ok']);
            $this->assertFalse($body['available']);
            $this->assertSame('', $body['version']);
            $this->assertStringContainsString('rsync not found', $body['message']);
        } finally {
            Rsync::$rsyncPathOverride = null;
        }
    }

    // ---- dispatch: GET pollers reject POST --------------------------------

    public function testGetPollersRejectPost(): void
    {
        foreach (['getStatus', 'getJobLog', 'listRuns', 'getPluginLog', 'getRsyncStatus'] as $action) {
            $_POST = ['action' => $action];
            $_GET  = [];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            [, $code] = $this->runCapture('ur_handle_request');
            $this->assertSame(405, $code, "$action should require GET");
        }
    }

    public function testGetStatusViaDispatch(): void
    {
        $id = $this->seedJob('Dispatched');
        $_GET = ['action' => 'getStatus'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        [$body, $code] = $this->runCapture('ur_handle_request');
        $this->assertSame(200, $code);
        $this->assertTrue($body['ok']);
        $this->assertArrayHasKey($id, $body['jobs']);
    }

    public function testGetRsyncStatusViaDispatch(): void
    {
        Rsync::$rsyncPathOverride = PHP_BINARY;
        try {
            $_GET = ['action' => 'getRsyncStatus'];
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            [$body, $code] = $this->runCapture('ur_handle_request');
            $this->assertSame(200, $code);
            $this->assertTrue($body['ok']);
            $this->assertTrue($body['available']);
        } finally {
            Rsync::$rsyncPathOverride = null;
        }
    }

    // ---- run/abort POST id confinement (ur_safe_job_id) --------------------
    // The POST run/abort handlers now route the id through ur_safe_job_id() first
    // (symmetry with the GET pollers + early rejection), so a malformed/tampered
    // id is rejected as "A job id is required." (422) before any config or
    // filesystem lookup — it can never reach the exact-match step at all.

    /**
     * @dataProvider unsafeRunAbortIds
     */
    public function testRunJobRejectsUnsafeId(string $bad): void
    {
        $_POST = ['id' => $bad];
        [$body, $code] = $this->runCapture(function () { ur_action_run_job(false); });
        $this->assertSame(422, $code, json_encode($body));
        $this->assertArrayHasKey('error', $body);
    }

    /**
     * @dataProvider unsafeRunAbortIds
     */
    public function testAbortJobRejectsUnsafeId(string $bad): void
    {
        $_POST = ['id' => $bad];
        [$body, $code] = $this->runCapture('ur_action_abort_job');
        $this->assertSame(422, $code, json_encode($body));
        $this->assertArrayHasKey('error', $body);
    }

    public static function unsafeRunAbortIds(): array
    {
        return [
            'traversal' => ['../../etc/passwd'],
            'slash'     => ['j/music'],
            'nul'       => ["j-music\0"],
            'dotdot'    => ['..'],
            'empty'     => [''],
        ];
    }

    public function testRunJobValidIdStillReachesNotFound(): void
    {
        // A well-formed id that is not a configured job must pass the ur_safe_job_id
        // gate and fail at the exact-match step (404) — confirming the safe-id
        // routing did not break the real authority (exact match against config).
        $_POST = ['id' => 'j-does-not-exist'];
        [$body, $code] = $this->runCapture(function () { ur_action_run_job(false); });
        $this->assertSame(404, $code, json_encode($body));
    }

    public function testAbortJobValidUnknownIdReachesNotFound(): void
    {
        $_POST = ['id' => 'j-does-not-exist'];
        [$body, $code] = $this->runCapture('ur_action_abort_job');
        $this->assertSame(404, $code, json_encode($body));
    }

    // ---- abortJob orchestration (flag + signalling) ------------------------
    // GAP-FILL: the existing abort tests cover only id-confinement (422) and the
    // unknown-id 404. These cover the ACTION's contract for a KNOWN job:
    //   - the abort flag is set FIRST (so the runner's between-pairs poll stops
    //     the run even if no signal is sent);
    //   - when the job is NOT running, no signal is sent (signalled=false), 200;
    //   - an id that is not a configured job but HAS a live state file is still
    //     accepted (an in-flight run whose job row was edited away).
    // The actual posix_kill of a live runner pid is an integration concern (it
    // needs a real /proc cmdline match) and is intentionally not simulated here.

    public function testAbortKnownJobNotRunningSetsFlagAndReportsNotSignalled(): void
    {
        $id = $this->seedJob('to-abort');
        // No run state -> RunState::isRunning() is false -> no signal sent.
        RunState::clear($id);
        RunState::clearAbort($id);
        $this->assertFalse(RunState::abortRequested($id));

        $_POST = ['id' => $id];
        [$body, $code] = $this->runCapture('ur_action_abort_job');

        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertSame($id, $body['jobId']);
        $this->assertFalse($body['signalled'], 'a not-running job is not signalled');
        // The flag is set regardless: the runner polls it between pairs.
        $this->assertTrue(RunState::abortRequested($id), 'the abort flag is set even when not signalling');

        RunState::clearAbort($id);
    }

    public function testAbortUnknownIdWithLiveStateIsAccepted(): void
    {
        // An id that is NOT a configured job (e.g. the job row was just deleted)
        // but has a RUNTIME state file (a run is in flight) must still be
        // abortable -> 200 + flag set, rather than 404.
        $orphan = 'j-orphan-run';
        // Ensure it is not in config.
        $config = Config::load();
        foreach (($config['jobs'] ?? []) as $j) {
            $this->assertNotSame($orphan, (string) ($j['id'] ?? ''));
        }
        // Seed a runtime state file (no matching config job).
        RunState::write($orphan, ['pid' => 999999, 'running' => true, 'currentLog' => '/x']);
        RunState::clearAbort($orphan);

        try {
            $_POST = ['id' => $orphan];
            [$body, $code] = $this->runCapture('ur_action_abort_job');

            $this->assertSame(200, $code, json_encode($body));
            $this->assertTrue($body['ok']);
            $this->assertTrue(RunState::abortRequested($orphan), 'orphan in-flight run is abortable via its state file');
        } finally {
            RunState::clear($orphan);
            RunState::clearAbort($orphan);
        }
    }
}

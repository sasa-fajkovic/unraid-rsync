<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\DataProvider;

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
        TestSeams::reset();
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

    #[DataProvider('badJobIds')]
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

    public function testASkippedScheduledRunIsNotReportedAsAFailure(): void
    {
        $id = $this->seedJob('Busy', ['notifyMode' => 'off']);
        Runner::writeSummary($id, ['state' => 'SUCCESS', 'startedAt' => '2025-01-01T03:00:00Z', 'exitCode' => 0]);
        $held = RunState::acquireLock($id);
        try {
            $res = Runner::run($id, false, 'schedule');
        } finally {
            RunState::releaseLock($held);
            @unlink(History::path($id));
        }
        $this->assertSame('SKIPPED', $res['state']);

        [$body] = $this->runCapture('ur_action_get_status');
        $this->assertSame('SUCCESS', $body['jobs'][$id]['state']);
        $this->assertSame('SUCCESS', $body['jobs'][$id]['lastRun']['state']);
    }

    /** A stored snapshot job (SSH; getStatus does not validate it). */
    private function seedSnapshotJob(string $name): string
    {
        return $this->seedJob($name, [
            'transport' => 'SSH', 'connectionId' => 'c-1',
            'pairs'     => [['local' => '/mnt/user/a/', 'remote' => '', 'sub' => 'a']],
            'snapshot'  => ['enabled' => true, 'root' => '/mnt/backup/snapshots/a'],
        ]);
    }

    public function testGetStatusCarriesTheLastSnapshotOnlyForSnapshotJobs(): void
    {
        $snap   = $this->seedSnapshotJob('Snap');
        $mirror = $this->seedJob('Mirror');
        $never  = $this->seedSnapshotJob('Never');
        foreach ([$snap, $mirror] as $id) {
            Runner::writeSummary($id, ['state' => 'SUCCESS', 'startedAt' => '2026-10-21T03:00:00Z', 'finishedAt' => '2026-10-21T03:10:00Z',
                'snapshot' => '2026-10-21T030005Z', 'committed' => true]);
        }
        $prevTz = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            [$body] = $this->runCapture('ur_action_get_status');
        } finally {
            date_default_timezone_set($prevTz);
        }

        $this->assertSame(
            ['stamp' => '2026-10-21T030005Z', 'committed' => true, 'label' => 'last snapshot 2026-10-21 05:00:05 (committed)'],
            $body['jobs'][$snap]['snapshot'],
            'the label is system-local, the stamp UTC'
        );
        $this->assertNull($body['jobs'][$never]['snapshot'], 'a snapshot job with no summary yet');
        $this->assertSame(
            ['name', 'enabled', 'manualOnly', 'running', 'state', 'lastRun', 'lastReal', 'lastDryRun', 'nextRun', 'historyRev'],
            array_keys($body['jobs'][$mirror]),
            'a mirror entry keeps its shape, whatever its summary holds'
        );
        $this->assertSame(['state', 'startedAt', 'finishedAt', 'exitCode', 'durationSec', 'dryRun'], array_keys($body['jobs'][$snap]['lastRun']));
    }

    public function testGetStatusSaysNotCommittedAndNothingForARunWithoutAStamp(): void
    {
        $id = $this->seedSnapshotJob('Snap');
        Runner::writeSummary($id, ['state' => 'PARTIAL', 'snapshot' => '2026-10-21T030005Z', 'committed' => false]);
        [$body] = $this->runCapture('ur_action_get_status');
        $this->assertSame('last snapshot 2026-10-21 03:00:05 (not committed)', $body['jobs'][$id]['snapshot']['label']);
        $this->assertFalse($body['jobs'][$id]['snapshot']['committed']);

        // A dry run records '' but the line follows the last real run.
        Runner::writeSummary($id, ['state' => 'SUCCESS', 'dryRun' => true, 'snapshot' => '', 'committed' => false]);
        [$body] = $this->runCapture('ur_action_get_status');
        $this->assertSame('last snapshot 2026-10-21 03:00:05 (not committed)', $body['jobs'][$id]['snapshot']['label']);

        // A failure before the stamp was created records ''.
        foreach (['', '2026-02-30T030005Z', 'x'] as $none) {
            Runner::writeSummary($id, ['state' => 'FAILED', 'snapshot' => $none, 'committed' => false]);
            [$body] = $this->runCapture('ur_action_get_status');
            $this->assertNull($body['jobs'][$id]['snapshot'], var_export($none, true));
        }
    }

    public function testListHistoryPassesSnapshotFieldsForSnapshotRecordsOnly(): void
    {
        $id = $this->seedSnapshotJob('Snap');
        @unlink(History::path($id));
        History::append($id, ['startedAt' => '2026-10-20T03:00:00Z', 'state' => 'SUCCESS', 'logRef' => 'run-a.log']);
        History::append($id, ['startedAt' => '2026-10-21T03:00:00Z', 'state' => 'WARNING', 'logRef' => 'run-b.log', 'reason' => 'snapshot-suspect',
            'snapshot' => '2026-10-21T030005Z', 'committed' => true, 'suspect' => true, 'pruned' => 2]);

        $_GET = ['id' => $id, 'offset' => 0, 'limit' => 25];
        [$body] = $this->runCapture('ur_action_list_history');
        [$snapRow, $mirrorRow] = $body['runs'];
        $this->assertSame(['snapshot' => '2026-10-21T030005Z', 'committed' => true, 'suspect' => true, 'pruned' => 2], array_slice($snapRow, 10, 4));
        $this->assertSame('snapshot-suspect', $snapRow['reason']);
        foreach (['snapshot', 'committed', 'suspect', 'pruned', 'growthKB'] as $key) {
            $this->assertArrayNotHasKey($key, $mirrorRow, "a mirror record has no $key");
        }
        @unlink(History::path($id));
    }

    public function testGetStatusNextRunNullForDisabledJob(): void
    {
        $id = $this->seedJob('Off', ['enabled' => false]);
        [$body] = $this->runCapture('ur_action_get_status');
        $this->assertNull($body['jobs'][$id]['nextRun']);
    }

    /**
     * A MANUAL-ONLY job has no next run. It is still `enabled` (enabled +
     * manualOnly is how "run on demand" is stored) and keeps whatever schedule
     * string it was last saved with, so computing a next fire from that string
     * made the 1s poller overwrite the correct server-rendered "manual (on
     * demand)" cell with a cron time that never fires - live-observed on the
     * Overview and Jobs tabs. crond never sees the job either: Cron::build()
     * skips it.
     */
    public function testGetStatusNextRunNullForManualOnlyJob(): void
    {
        $manual = $this->seedJob('Manual', ['manualOnly' => true, 'schedule' => '0 3 * * *']);
        $cron   = $this->seedJob('Cron', ['schedule' => '0 3 * * *']);
        [$body] = $this->runCapture('ur_action_get_status');

        $this->assertTrue($body['jobs'][$manual]['enabled'], 'manual-only is still an enabled job');
        $this->assertTrue($body['jobs'][$manual]['manualOnly']);
        $this->assertNull($body['jobs'][$manual]['nextRun'], 'a manual-only job must claim no next run');

        // The flag is present and false for a scheduled job, so the UI can
        // distinguish "manual" from "enabled but uncomputable".
        $this->assertFalse($body['jobs'][$cron]['manualOnly']);
        $this->assertIsInt($body['jobs'][$cron]['nextRun']);
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

    // ---- downloadJobLog ----------------------------------------------------

    /** Capture a download action: [downloadMeta|null, jsonBody|null, code]. */
    private function runDownload(): array
    {
        $GLOBALS['ur_last_download'] = null;
        ob_start();
        ur_action_download_job_log();
        $out = ob_get_clean();
        return [
            $GLOBALS['ur_last_download'] ?? null,
            json_decode((string) $out, true),
            (int) ($GLOBALS['ur_last_response_code'] ?? 200),
        ];
    }

    public function testDownloadJobLogServesFullFileForSelectedRun(): void
    {
        $id  = $this->seedJob('Downloadable');
        $old = Logger::openRun($id, 1750000000);
        Logger::append($old, 'OLD-RUN-MARKER');
        $new = Logger::openRun($id, 1750000600);
        Logger::append($new, 'NEW-RUN-MARKER');

        // Explicit older run id -> serves THAT run's file (not the latest).
        $_GET = ['id' => $id, 'run' => basename($old)];
        [$dl, $json, $code] = $this->runDownload();
        $this->assertSame(200, $code);
        $this->assertNull($json, 'a download streams the file, not a JSON body.');
        $this->assertIsArray($dl);
        // Compare by basename: runLogPathById realpath-resolves the path, which on
        // macOS adds a /private prefix the raw openRun() path lacks.
        $this->assertSame(basename($old), basename($dl['path']));
        // Filename is slug-shaped (job + run id), safe charset only.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+\.log$/', $dl['filename']);
        $this->assertStringContainsString($id, $dl['filename']);
        $this->assertGreaterThan(0, $dl['size']);
        // Confirm the served file really holds the OLD run's full content.
        $this->assertStringContainsString('OLD-RUN-MARKER', (string) file_get_contents($dl['path']));
    }

    /**
     * The file is redacted by the runner only once a pair has finished (never if
     * the runner is SIGKILLed); the download must scrub on the way out like the
     * tail does.
     */
    public function testDownloadJobLogScrubsTmpfsSecretPaths(): void
    {
        $id   = $this->seedJob('DlScrub');
        $log  = Logger::openRun($id, 1750000000);
        $key  = Logger::base() . '/keys/c-1-4242-0123456789ab';
        $pass = Logger::base() . '/pass/c-1-4242-0123456789ab';
        file_put_contents($log, "opening connection using: ssh -i $key -p 22\nfailed to open password file $pass\n");

        $_GET = ['id' => $id];
        [$dl, , $code] = $this->runDownload();
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString($key, $dl['body']);
        $this->assertStringNotContainsString($pass, $dl['body']);
        $this->assertSame(
            "opening connection using: ssh -i [redacted] -p 22\nfailed to open password file [redacted]\n",
            $dl['body']
        );
    }

    public function testStreamScrubbedCatchesAPathSplitAcrossChunks(): void
    {
        $path = UR_RUNTIME_BASE . '/scrub-' . bin2hex(random_bytes(4)) . '.log';
        $text = "line one\n-e 'ssh -i " . Logger::base() . "/keys/tok-1 -o UserKnownHostsFile="
            . Logger::base() . "/known_hosts/tok-1'\nno newline at the end " . Logger::base() . '/pass/tok-1';
        file_put_contents($path, $text);
        try {
            foreach ([1, 2, 7, 13, 40, 65536] as $size) {
                $out = '';
                ur_stream_scrubbed($path, static function (string $s) use (&$out): void {
                    $out .= $s;
                }, $size);
                $this->assertSame(Logger::scrubSecretPaths($text), $out, "chunk size $size");
                $this->assertStringNotContainsString('tok-1', $out, "chunk size $size");
            }
        } finally {
            @unlink($path);
        }
    }

    public function testDownloadJobLogDefaultsToLatestRun(): void
    {
        $id  = $this->seedJob('DownloadLatest');
        Logger::append(Logger::openRun($id, 1750000000), 'OLD');
        $new = Logger::openRun($id, 1750000600);
        Logger::append($new, 'NEW');

        $_GET = ['id' => $id]; // no run -> latest
        [$dl, , $code] = $this->runDownload();
        $this->assertSame(200, $code);
        $this->assertSame(basename($new), basename($dl['path']));
    }

    public function testDownloadJobLogRejectsTraversalRunId(): void
    {
        $id = $this->seedJob('DlTrav');
        $_GET = ['id' => $id, 'run' => '../../etc/passwd'];
        [$dl, $json, $code] = $this->runDownload();
        $this->assertSame(400, $code);
        $this->assertNull($dl);
        $this->assertArrayHasKey('error', $json);
    }

    public function testDownloadJobLogRejectsBadJobId(): void
    {
        $_GET = ['id' => '../evil'];
        [, , $code] = $this->runDownload();
        $this->assertSame(400, $code);
    }

    public function testDownloadJobLogMissingLogIs404(): void
    {
        $id = $this->seedJob('DlNoLog'); // no run opened
        $_GET = ['id' => $id];
        [$dl, $json, $code] = $this->runDownload();
        $this->assertSame(404, $code);
        $this->assertNull($dl);
        $this->assertArrayHasKey('error', $json);
    }

    // ---- listHistory: live RUNNING row -------------------------------------

    public function testRunningHistoryRowShape(): void
    {
        // The pure helper that turns a RunState into a synthetic "RUNNING" row.
        $row = ur_running_history_row([
            'startedAt'  => '2026-06-15T12:00:00Z',
            'dryRun'     => true,
            'trigger'    => 'schedule',
            'currentLog' => '/tmp/ur/logs/j-x/run-20260615T120000Z.log',
        ]);
        $this->assertSame('RUNNING', $row['state']);
        $this->assertTrue($row['dryRun']);
        $this->assertSame('schedule', $row['trigger']);
        $this->assertSame('2026-06-15T12:00:00Z', $row['startedAt']);
        // logRef is a BASENAME only (so View/Download resolve it via runLogPathById).
        $this->assertSame('run-20260615T120000Z.log', $row['logRef']);
        $this->assertTrue($row['running']);
    }

    public function testListHistoryReportsIdleAndNoRunningRow(): void
    {
        $id = $this->seedJob('HistIdle');
        @unlink(History::path($id)); // isolate from any prior run
        History::append($id, [
            'startedAt' => '2026-06-15T10:00:00Z', 'state' => 'SUCCESS',
            'exitCode' => 0, 'durationSec' => 5, 'trigger' => 'manual', 'logRef' => 'run-a.log',
        ]);

        $_GET = ['id' => $id, 'offset' => 0, 'limit' => 25];
        [$body, $code] = $this->runCapture('ur_action_list_history');
        $this->assertSame(200, $code);
        $this->assertFalse($body['running'], 'an idle job must not report a running row');
        $this->assertSame(1, $body['total']);
        $this->assertSame('SUCCESS', $body['runs'][0]['state']);
        @unlink(History::path($id));
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

    public function testListRunsCarriesFinishTimeStateAndDryRunFromHistory(): void
    {
        $id = $this->seedJob('Runs');
        $old = basename(Logger::openRun($id, 1750000000));
        $new = basename(Logger::openRun($id, 1750000600));
        History::append($id, ['startedAt' => '2025-06-15T15:06:40Z', 'finishedAt' => '2025-06-15T15:09:40Z',
            'state' => 'FAILED', 'dryRun' => true, 'logRef' => $old]);
        $_GET = ['id' => $id];
        [$body, $code] = $this->runCapture('ur_action_list_runs');
        $this->assertSame(200, $code);

        $this->assertSame($new, $body['runs'][0]['id']);
        $this->assertArrayNotHasKey('finishedTs', $body['runs'][0], 'no History record yet (still running)');
        $this->assertSame($old, $body['runs'][1]['id']);
        $this->assertSame(1750000180, $body['runs'][1]['finishedTs']);
        $this->assertSame('FAILED', $body['runs'][1]['state']);
        $this->assertTrue($body['runs'][1]['dryRun']);
    }

    public function testListRunsKeepsTheSummaryStateOverHistory(): void
    {
        $runs = ur_runs_with_history(
            [['id' => 'run-a.log', 'ts' => 1, 'state' => 'RUNNING'], ['id' => 'run-b.log', 'ts' => 0, 'state' => '']],
            [['logRef' => 'run-a.log', 'state' => 'SUCCESS', 'finishedAt' => 'bad', 'dryRun' => false]]
        );
        $this->assertSame('RUNNING', $runs[0]['state']);
        $this->assertArrayNotHasKey('finishedTs', $runs[0], 'an unparseable finishedAt is left out');
        $this->assertSame(['id' => 'run-b.log', 'ts' => 0, 'state' => ''], $runs[1]);
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

    private function writePluginLog(string $bytes, bool $append = false): string
    {
        $path = Logger::pluginLogPath();
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $bytes, $append ? FILE_APPEND : 0);
        return $path;
    }

    private function pluginLogSince(int $since): array
    {
        $_GET = ['since' => (string) $since];
        try {
            return $this->runCapture('ur_action_get_plugin_log');
        } finally {
            $_GET = [];
        }
    }

    public function testGetPluginLogWithoutSinceKeepsTheTailShape(): void
    {
        $this->writePluginLog("one\ntwo\n");
        [$body, $code] = $this->runCapture('ur_action_get_plugin_log');
        $this->assertSame(200, $code);
        $this->assertSame(['ok' => true, 'log' => "one\ntwo\n"], $body);
    }

    public function testGetPluginLogSinceReturnsOnlyNewCompleteLinesEscaped(): void
    {
        $this->writePluginLog("first <b>\n");
        [$body] = $this->pluginLogSince(0);
        $this->assertSame("first &lt;b&gt;\n", $body['text']);
        $this->assertSame(10, $body['offset']);
        $this->assertSame(10, $body['size']);
        $this->assertFalse($body['reset']);
        $this->assertFalse($body['truncated']);

        // A half-written line is held back until its newline lands, so a
        // multi-byte character is never escaped in two halves.
        $this->writePluginLog("second\nthi\u{00e9}", true);
        [$body] = $this->pluginLogSince(10);
        $this->assertSame("second\n", $body['text']);
        $this->assertSame(17, $body['offset']);

        $this->writePluginLog("rd\n", true);
        [$body] = $this->pluginLogSince(17);
        $this->assertSame("thi\u{00e9}rd\n", $body['text']);

        [$body] = $this->pluginLogSince($body['offset']);
        $this->assertSame('', $body['text']);
    }

    public function testGetPluginLogSincePastTheEndResetsToTheStart(): void
    {
        $this->writePluginLog("fresh\n");
        [$body] = $this->pluginLogSince(5000);
        $this->assertTrue($body['reset']);
        $this->assertSame("fresh\n", $body['text']);
        $this->assertSame(6, $body['offset']);
    }

    public function testGetPluginLogSinceSkipsAGapLargerThanTheCap(): void
    {
        $line = str_repeat('x', 99) . "\n";
        $this->writePluginLog(str_repeat($line, (int) ceil(UR_GET_LOG_TAIL_BYTES / 100) + 50));
        [$body] = $this->pluginLogSince(0);
        $this->assertTrue($body['truncated']);
        $this->assertStringStartsWith("[... earlier output truncated ...]\n", $body['text']);
        $this->assertSame($body['size'], $body['offset']);
        $this->assertLessThanOrEqual(UR_GET_LOG_TAIL_BYTES + 64, strlen($body['text']));
    }

    public function testGetPluginLogNeverReturnsAPartOfAnOversizedUnfinishedLine(): void
    {
        // One line longer than the window, written in two halves that split a
        // two-byte character (é = C3 A9) between them.
        $this->writePluginLog("a\n" . str_repeat('x', UR_GET_LOG_TAIL_BYTES + 5000) . "\xC3");
        [$body] = $this->pluginLogSince(2);
        $this->assertSame('', $body['text']);
        $this->assertSame(2, $body['offset'], 'the offset must stay at the last completed line');

        $this->writePluginLog("\xA9 end\n", true);
        [$body] = $this->pluginLogSince($body['offset']);
        $this->assertSame("[... earlier output truncated ...]\n", $body['text'], 'the oversized line is skipped whole');
        $this->assertTrue($body['truncated']);
        $this->assertSame($body['size'], $body['offset']);
        $this->assertStringNotContainsString("\u{FFFD}", $body['text']);

        $this->writePluginLog("next\n", true);
        [$body] = $this->pluginLogSince($body['offset']);
        $this->assertSame("next\n", $body['text']);
    }

    public function testGetPluginLogNonNumericSinceFallsBackToTheTail(): void
    {
        $this->writePluginLog("x\n");
        $_GET = ['since' => '-1'];
        [$body] = $this->runCapture('ur_action_get_plugin_log');
        $this->assertSame(['ok' => true, 'log' => "x\n"], $body);
    }

    // ---- error envelopes ---------------------------------------------------

    public function testEveryErrorEnvelopeCarriesOkFalseAndError(): void
    {
        [$body, $code] = $this->runCapture(fn() => sendError('Nope.', 409, ['running' => true]));
        $this->assertSame(409, $code);
        $this->assertSame(['ok' => false, 'error' => 'Nope.', 'running' => true], $body);

        $_GET = ['action' => 'noSuchAction'];
        [$body, $code] = $this->runCapture('ur_handle_request');
        $this->assertSame(400, $code);
        $this->assertFalse($body['ok']);
        $this->assertSame('Unknown action.', $body['error']);
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
        foreach (['getStatus', 'getJobLog', 'downloadJobLog', 'listRuns', 'getPluginLog', 'getRsyncStatus'] as $action) {
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

    #[DataProvider('unsafeRunAbortIds')]
    public function testRunJobRejectsUnsafeId(string $bad): void
    {
        $_POST = ['id' => $bad];
        [$body, $code] = $this->runCapture(function () {
            ur_action_run_job(false);
        });
        $this->assertSame(422, $code, json_encode($body));
        $this->assertArrayHasKey('error', $body);
    }

    #[DataProvider('unsafeRunAbortIds')]
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
        [$body, $code] = $this->runCapture(function () {
            ur_action_run_job(false);
        });
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

    /** A SIGKILLed runner: its orphaned rsync holds the run lock, Abort must reach its group. */
    public function testAbortSignalsTheOrphanGroupOfASigkilledRunner(): void
    {
        $id  = $this->seedJob('orphan-rsync');
        $pid = 2147483647;
        RunState::write($id, ['pid' => $pid, 'running' => true, 'currentLog' => '/x']);
        RunState::clearAbort($id);
        $sent = [];
        RunState::$kill = static function (int $p, int $sig) use (&$sent): bool {
            $sent[] = $p;
            return true;
        };
        $orphan = RunState::acquireLock($id);
        try {
            $_POST = ['id' => $id];
            [$body, $code] = $this->runCapture('ur_action_abort_job');
            $this->assertSame(200, $code, json_encode($body));
            $this->assertTrue($body['signalled']);
            $this->assertSame([-$pid], $sent);
            $this->assertTrue(RunState::read($id)['running'], 'not reaped while the orphan holds the lock');
        } finally {
            RunState::releaseLock($orphan);
            RunState::clear($id);
            RunState::clearAbort($id);
        }
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

    // ---- P6: History filters, truncation flag, plugin-log download, tz ------

    /** Seed $n records cycling SUCCESS/FAILED/PARTIAL and alternating real/dry, oldest first. */
    private function seedHistory(string $id, int $n): void
    {
        @unlink(History::path($id));
        for ($i = 0; $i < $n; $i++) {
            History::append($id, [
                'startedAt' => sprintf('2026-10-01T%02d:00:00Z', $i),
                'state'     => ['SUCCESS', 'FAILED', 'PARTIAL'][$i % 3],
                'exitCode'  => [0, 11, 23][$i % 3],
                'dryRun'    => $i % 2 === 1,
                'logRef'    => "run-$i.log",
            ]);
        }
    }

    public function testListHistoryFiltersByStateAndTypeAndPagesTheFilteredList(): void
    {
        $id = $this->seedJob('HistFilter');
        $this->seedHistory($id, 12);
        try {
            $_GET = ['id' => $id, 'state' => 'problems', 'offset' => 0, 'limit' => 5];
            [$body, $code] = $this->runCapture('ur_action_list_history');
            $this->assertSame(200, $code);
            $this->assertSame(8, $body['total'], 'FAILED + PARTIAL of 12');
            $this->assertCount(5, $body['runs']);
            $_GET['offset'] = 5;
            [$body] = $this->runCapture('ur_action_list_history');
            $this->assertCount(3, $body['runs'], 'the last page of the FILTERED list');
            $this->assertNotContains('SUCCESS', array_column($body['runs'], 'state'));

            $_GET = ['id' => $id, 'state' => 'success', 'dryRun' => '0', 'limit' => 25];
            [$body] = $this->runCapture('ur_action_list_history');
            // i % 3 == 0 and i even: 0, 6.
            $this->assertSame(['run-6.log', 'run-0.log'], array_column($body['runs'], 'logRef'));
            $this->assertSame([$id, $id], array_column($body['runs'], 'jobId'));

            $_GET = ['id' => '', 'dryRun' => '1', 'limit' => 100];
            [$body] = $this->runCapture('ur_action_list_history');
            $mine = array_values(array_filter($body['runs'], static fn(array $r): bool => $r['jobId'] === $id));
            $this->assertCount(6, $mine);
            $this->assertSame([true], array_values(array_unique(array_column($body['runs'], 'dryRun'))));

            // Absent or unknown filters change nothing (old clients).
            $_GET = ['id' => $id, 'state' => 'bogus', 'dryRun' => 'x', 'limit' => 25];
            [$body] = $this->runCapture('ur_action_list_history');
            $this->assertSame(12, $body['total']);
        } finally {
            @unlink(History::path($id));
        }
    }

    public function testHistoryFilterHelperTreatsARunningRowLikeAnyOther(): void
    {
        $rows = [
            ['state' => 'RUNNING', 'dryRun' => true],
            ['state' => 'TIMEOUT', 'dryRun' => false],
            ['state' => 'ABORTED', 'dryRun' => true],
            ['state' => 'WARNING', 'dryRun' => false],
            ['state' => 'SKIPPED', 'dryRun' => false],
            ['state' => 'SUCCESS', 'dryRun' => false],
        ];
        $this->assertSame(['TIMEOUT', 'ABORTED', 'WARNING'], array_column(ur_filter_history($rows, 'problems', ''), 'state'));
        $this->assertSame(['RUNNING', 'ABORTED'], array_column(ur_filter_history($rows, '', '1'), 'state'));
        $this->assertSame($rows, ur_filter_history($rows, '', ''));
    }

    public function testGetJobLogSaysWhetherTheTailWasTruncated(): void
    {
        $id   = $this->seedJob('Trunc');
        $path = Logger::openRun($id, 1750000000);
        Logger::append($path, 'short');
        $_GET = ['id' => $id];
        [$body] = $this->runCapture('ur_action_get_job_log');
        $this->assertFalse($body['truncated']);

        file_put_contents($path, str_repeat(str_repeat('x', 99) . "\n", intdiv(UR_GET_LOG_TAIL_BYTES, 100) + 10), FILE_APPEND);
        [$body] = $this->runCapture('ur_action_get_job_log');
        $this->assertTrue($body['truncated']);
    }

    public function testDownloadPluginLogStreamsTheWholeLogAnd404sWhenEmpty(): void
    {
        $GLOBALS['ur_last_download'] = null;
        [$json, $code] = $this->runCapture('ur_action_download_plugin_log');
        $this->assertSame(404, $code);
        $this->assertArrayHasKey('error', $json);

        $path = $this->writePluginLog("[t] [j-a] one\n[t] [j-b] two\n");
        $_GET = ['action' => 'downloadPluginLog'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        [, $code] = $this->runCapture('ur_handle_request');
        $this->assertSame(200, $code);
        $this->assertSame(realpath($path), $GLOBALS['ur_last_download']['path']);
        $this->assertSame('unraid.rsync-plugin.log', $GLOBALS['ur_last_download']['filename']);
        $this->assertSame("[t] [j-a] one\n[t] [j-b] two\n", $GLOBALS['ur_last_download']['body'], 'streamed through ur_stream_scrubbed');

        $_POST = ['action' => 'downloadPluginLog'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        [, $code] = $this->runCapture('ur_handle_request');
        $this->assertSame(405, $code, 'a read: GET only');
    }

    public function testGetStatusCarriesTheServerTimezone(): void
    {
        $this->seedJob('Tz');
        [$body] = $this->runCapture('ur_action_get_status');
        $this->assertSame(Config::systemTimezone(), $body['tz']);
    }

    // ---- review: log reads never follow a symlink out of the logs dir --------

    public function testASymlinkedPluginLogIsRefusedAndNothingIsStreamedOrTailed(): void
    {
        $secret = $this->rtBase . '/credentials.json';
        @mkdir($this->rtBase, 0700, true);
        file_put_contents($secret, "PRIVATE-KEY-BYTES\n");
        $path = Logger::pluginLogPath();
        @mkdir(dirname($path), 0777, true);
        symlink($secret, $path);

        $GLOBALS['ur_last_download'] = null;
        [$json, $code] = $this->runCapture('ur_action_download_plugin_log');
        $this->assertSame(404, $code);
        $this->assertNull($GLOBALS['ur_last_download'], 'nothing streamed');
        $this->assertStringNotContainsString('PRIVATE-KEY-BYTES', (string) json_encode($json));

        [$body] = $this->runCapture('ur_action_get_plugin_log');
        $this->assertSame('', $body['log']);
        [$body] = $this->pluginLogSince(0);
        $this->assertSame('', $body['text']);
    }

    public function testASymlinkedRunLogIsRefused(): void
    {
        $id     = $this->seedJob('SymRun');
        $real   = Logger::openRun($id, 1750000000);
        $secret = $this->rtBase . '/outside.txt';
        file_put_contents($secret, "PRIVATE\n");
        $link = dirname($real) . '/run-20250615T000000Z.log';
        symlink($secret, $link);

        $_GET = ['id' => $id, 'run' => basename($link)];
        $GLOBALS['ur_last_download'] = null;
        ob_start();
        ur_action_download_job_log();
        ob_end_clean();
        $this->assertSame(400, (int) $GLOBALS['ur_last_response_code']);
        $this->assertNull($GLOBALS['ur_last_download']);
    }

    // ---- review: History refresh sees records that change no summary ---------

    public function testHistoryRevChangesOnASkippedAppendWithUnchangedLastRun(): void
    {
        $id = $this->seedJob('HistRev');
        @unlink(History::path($id));
        Runner::writeSummary($id, ['state' => 'SUCCESS', 'finishedAt' => '2026-10-01T03:00:00Z']);
        try {
            [$before] = $this->runCapture('ur_action_get_status');
            $this->assertSame('', $before['jobs'][$id]['historyRev'], 'no history file yet');

            History::append($id, ['startedAt' => '2026-10-02T03:00:00Z', 'state' => 'SKIPPED', 'trigger' => 'schedule']);
            [$after] = $this->runCapture('ur_action_get_status');
            $this->assertSame($before['jobs'][$id]['lastRun'], $after['jobs'][$id]['lastRun']);
            $this->assertSame($before['jobs'][$id]['running'], $after['jobs'][$id]['running']);
            $this->assertNotSame('', $after['jobs'][$id]['historyRev']);

            History::append($id, ['startedAt' => '2026-10-03T03:00:00Z', 'state' => 'SKIPPED', 'trigger' => 'schedule']);
            [$again] = $this->runCapture('ur_action_get_status');
            $this->assertNotSame($after['jobs'][$id]['historyRev'], $again['jobs'][$id]['historyRev']);
        } finally {
            @unlink(History::path($id));
        }
    }

    // ---- the launch generation (an Abort before the runner starts) ---------

    public function testAbortNamesTheLaunchItIsFor(): void
    {
        $id  = $this->seedJob('gen');
        $gen = RunState::beginLaunch($id);
        RunState::clearAbort($id);

        $_POST = ['id' => $id];
        [, $code] = $this->runCapture('ur_action_abort_job');

        $this->assertSame(200, $code);
        $this->assertSame(['by' => 'user', 'gen' => $gen], RunState::readAbort($id));
        $this->assertTrue(RunState::abortedByUser($id, $gen));
        $this->assertFalse(RunState::abortedByUser($id, 'another-launch'));
        RunState::clearAbort($id);
        RunState::clear($id);
    }

    public function testRunJobRefusesWhileTheRunLockIsHeld(): void
    {
        $id   = $this->seedJob('locked');
        $held = RunState::acquireLock($id);
        try {
            $_POST = ['id' => $id];
            [$body, $code] = $this->runCapture(static function (): void {
                ur_action_run_job(false);
            });
        } finally {
            RunState::releaseLock($held);
        }
        $this->assertSame(409, $code, json_encode($body));
        $this->assertTrue($body['running']);
        $this->assertArrayNotHasKey('gen', RunState::read($id) ?? [], 'nothing recorded for a launch that did not happen');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRunJobRecordsTheGenerationAndHandsItToTheRunner(): void
    {
        $dir  = sys_get_temp_dir() . '/ur-fake-runner-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($dir);
        $out  = $dir . '/argv.json';
        file_put_contents($dir . '/runner.php', '<?php file_put_contents(' . var_export($out, true) . ', json_encode($argv));');
        define('UR_RUNNER_PATH', $dir . '/runner.php');
        $id = $this->seedJob('launch');

        $_POST = ['id' => $id];
        [$body, $code] = $this->runCapture(static function (): void {
            ur_action_run_job(false);
        });
        $this->assertSame(200, $code, json_encode($body));
        for ($i = 0; $i < 100 && !is_file($out); $i++) {
            usleep(20000);
        }
        $argv = json_decode((string) @file_get_contents($out), true);
        @unlink($out);
        @unlink($dir . '/runner.php');
        @rmdir($dir);

        $gen = (string) (RunState::read($id)['gen'] ?? '');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $gen, 'recorded before the launch');
        $this->assertIsArray($argv, 'the runner was started');
        $this->assertContains('--job=' . $id, $argv);
        $this->assertContains('--gen=' . $gen, $argv);
        $this->assertFalse(RunState::read($id)['running'] ?? true, 'a pending launch is not a running one');
    }

    // ---- the last real run drives the badge; a dry run is a second line ----

    public function testGetStatusDerivesTheBadgeFromTheLastRealRunNotADryRun(): void
    {
        $id = $this->seedJob('Audiobooks');
        Runner::writeSummary($id, ['state' => 'FAILED', 'startedAt' => '2026-10-20T03:00:00Z', 'finishedAt' => '2026-10-20T03:05:00Z', 'exitCode' => 12]);
        Runner::writeSummary($id, ['state' => 'SUCCESS', 'startedAt' => '2026-10-21T09:00:00Z', 'finishedAt' => '2026-10-21T09:01:00Z',
            'exitCode' => 0, 'durationSec' => 60, 'dryRun' => true]);

        [$body] = $this->runCapture('ur_action_get_status');
        $entry = $body['jobs'][$id];

        $this->assertSame('FAILED', $entry['state']);
        $this->assertSame(['state' => 'SUCCESS', 'startedAt' => '2026-10-21T09:00:00Z', 'finishedAt' => '2026-10-21T09:01:00Z',
            'exitCode' => 0, 'durationSec' => 60, 'dryRun' => true], $entry['lastRun'], 'lastRun is unchanged: the last execution');
        $this->assertSame(['state' => 'FAILED', 'startedAt' => '2026-10-20T03:00:00Z', 'finishedAt' => '2026-10-20T03:05:00Z',
            'exitCode' => 12, 'reason' => ''], $entry['lastReal']);
        $this->assertSame(['label' => 'Dry-run OK', 'finishedAt' => '2026-10-21T09:01:00Z', 'state' => 'SUCCESS'], $entry['lastDryRun']);
    }

    public function testGetStatusShowsACrashedRunAndItsMissingExitCode(): void
    {
        $id = $this->seedJob('Crashy', ['notifyMode' => 'off']);
        Runner::writeSummary($id, ['state' => 'SUCCESS', 'startedAt' => '2026-10-17T03:00:00Z', 'exitCode' => 0]);
        Runner::reportCrashed(['jobId' => $id, 'pid' => 999999, 'startedAt' => '2026-10-21T03:00:00Z', 'trigger' => 'schedule']);

        [$body] = $this->runCapture('ur_action_get_status');
        $entry = $body['jobs'][$id];
        $this->assertSame('CRASHED', $entry['state']);
        $this->assertNull($entry['lastRun']['exitCode']);
        $this->assertNull($entry['lastDryRun']);
        @unlink(History::path($id));
    }
}

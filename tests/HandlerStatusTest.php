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
        http_response_code(200);
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
        return [json_decode($out, true), http_response_code()];
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
        $this->assertFalse($entry['running']);
        $this->assertSame('PENDING', $entry['state']);
        $this->assertNull($entry['lastRun']);
        // Enabled job with a valid cron -> a numeric nextRun epoch.
        $this->assertIsInt($entry['nextRun']);
        $this->assertGreaterThan(time(), $entry['nextRun']);
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

    // ---- dispatch: GET pollers reject POST --------------------------------

    public function testGetPollersRejectPost(): void
    {
        foreach (['getStatus', 'getJobLog', 'listRuns', 'getPluginLog'] as $action) {
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
}

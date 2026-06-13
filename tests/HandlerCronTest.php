<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests that the handler re-syncs the cron schedule (Cron::apply) on the config
 * changes that affect a job's schedule / enabled state:
 *   - saveConfig (jobs path) writes the cron file from the saved jobs and
 *     invokes update_cron (via the injected stub);
 *   - deleteConnection that disables dependent jobs re-applies cron so those
 *     jobs' schedules drop out.
 *
 * The update_cron exec is stubbed (Cron::$updateCronRunner) so nothing touches
 * the real /usr/local/sbin/update_cron; UR_CONFIG_BASE (bootstrap) points the
 * cron file at a temp dir. The stub is reset in tearDown so other handler test
 * classes are unaffected.
 */
final class HandlerCronTest extends TestCase
{
    /** @var array<int,array<int,string>> */
    private array $updateCronCalls = [];

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
        // Reset the handler's intended-status-code test seam instead of calling
        // http_response_code(200), which warns under CLI/PHP 8.4 once output has
        // begun (failOnWarning would fail the test). See sendResponse.
        $GLOBALS['ur_last_response_code'] = 200;
        $GLOBALS['var'] = ['csrf_token' => 'test-token'];

        foreach ([Config::path(), Credentials::path(), Cron::cronFilePath()] as $p) {
            if (is_file($p)) {
                unlink($p);
            }
        }

        $this->updateCronCalls = [];
        Cron::$updateCronRunner = function (array $argv): int {
            $this->updateCronCalls[] = $argv;
            return 0;
        };
        Cron::$updateCronPath = null;
    }

    protected function tearDown(): void
    {
        Cron::$updateCronRunner = null;
        Cron::$updateCronPath = null;
    }

    private function runCapture(callable $fn): array
    {
        ob_start();
        $fn();
        $out = ob_get_clean();
        return [json_decode($out, true), (int) ($GLOBALS['ur_last_response_code'] ?? 200)];
    }

    public function testSaveConfigAppliesCron(): void
    {
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs'       => [
                0 => ['name' => 'music', 'schedule' => '0 3 * * *', 'transport' => 'LOCAL',
                      'enabled' => '1',
                      'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']]],
            ],
        ];

        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);
        // No cron warning (the stub returned success).
        $this->assertSame([], $body['warnings']);

        // update_cron was invoked with the absolute-path argv.
        $this->assertCount(1, $this->updateCronCalls);
        $this->assertSame(['/usr/local/sbin/update_cron'], $this->updateCronCalls[0]);

        // The cron file carries exactly the saved job's line.
        $content = file_get_contents(Cron::cronFilePath());
        $this->assertStringContainsString(
            '0 3 * * * php /usr/local/emhttp/plugins/unraid.rsync/scripts/runner.php --job=j-music >/dev/null 2>&1',
            $content
        );
    }

    public function testSaveConfigDisabledJobNotScheduled(): void
    {
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs'       => [
                0 => ['name' => 'off', 'schedule' => '0 3 * * *', 'transport' => 'LOCAL',
                      'enabled' => '0',
                      'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']]],
            ],
        ];

        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(200, $code, json_encode($body));

        // No enabled jobs -> cron file absent, update_cron still called.
        $this->assertFileDoesNotExist(Cron::cronFilePath());
        $this->assertCount(1, $this->updateCronCalls);
    }

    public function testSaveConfigSurfacesCronFailureAsWarning(): void
    {
        // update_cron fails -> the save still succeeds, but a non-fatal warning
        // is appended (the schedule will be re-applied on the next array start).
        Cron::$updateCronRunner = fn(array $argv): int => 1;

        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs'       => [
                0 => ['name' => 'music', 'schedule' => '0 3 * * *', 'transport' => 'LOCAL',
                      'enabled' => '1',
                      'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']]],
            ],
        ];

        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertNotEmpty($body['warnings']);
        $this->assertStringContainsString('schedule', strtolower(implode(' ', $body['warnings'])));
    }

    public function testDeleteConnectionReappliesCronAfterDisablingJobs(): void
    {
        // A connection with one enabled SSH job referencing it.
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'rpi', 'host' => 'h', 'username' => 'u', 'authMethod' => 'PASSWORD',
        ]);
        Credentials::save($seed);

        $config = Config::defaults();
        $config['jobs'][] = Job::normalize(['name' => 'music', 'connectionId' => 'c-1', 'enabled' => true,
            'schedule' => '0 3 * * *', 'transport' => 'SSH',
            'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/srv/a/']]]);
        Config::save($config);

        // Seed the cron file as if the job were already scheduled.
        Cron::apply($config);
        $this->assertFileExists(Cron::cronFilePath());
        $this->updateCronCalls = []; // reset to isolate the delete's apply

        $_POST = ['action' => 'deleteConnection', 'csrf_token' => 'test-token', 'id' => 'c-1'];
        [$body, $code] = $this->runCapture(fn() => ur_action_delete_connection());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame(['music'], $body['disabledJobs']);

        // The disabled job dropped out of the schedule -> file removed, and
        // update_cron was invoked as part of the delete.
        $this->assertGreaterThanOrEqual(1, count($this->updateCronCalls));
        $this->assertFileDoesNotExist(Cron::cronFilePath());
    }

    public function testDeleteConnectionWithoutDependentsDoesNotTouchCron(): void
    {
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'x', 'host' => 'h', 'username' => 'u', 'authMethod' => 'PASSWORD',
        ]);
        Credentials::save($seed);

        $_POST = ['action' => 'deleteConnection', 'csrf_token' => 'test-token', 'id' => 'c-1'];
        [$body, $code] = $this->runCapture(fn() => ur_action_delete_connection());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([], $body['disabledJobs']);
        // No jobs disabled -> no cron re-apply.
        $this->assertCount(0, $this->updateCronCalls);
    }
}

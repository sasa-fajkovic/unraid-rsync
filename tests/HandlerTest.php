<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for handler.php: the saveConfig action, CSRF enforcement, the
 * nested-form ($_POST) round-trip into the config, and the JSON response
 * helpers.
 *
 * handler.php is included with UR_HANDLER_TESTING defined so its helper
 * functions do NOT call exit (which would abort PHPUnit) and the front
 * controller does not auto-dispatch.
 */
final class HandlerTest extends TestCase
{
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
        // Reset the handler's intended-status-code test seam. We do NOT call
        // http_response_code(200) here: under CLI on PHP 8.4+ that emits an
        // E_WARNING ("headers already sent") once PHPUnit has printed, which
        // failOnWarning would turn into an error. sendResponse records the code
        // it intended via $GLOBALS['ur_last_response_code'] instead.
        $GLOBALS['ur_last_response_code'] = 200;
        $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        $path = Config::path();
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Run a handler callable, capturing its echoed JSON and the HTTP status it
     * set. Returns [decodedBody, statusCode]. The status comes from the handler's
     * test seam ($GLOBALS['ur_last_response_code']) rather than
     * http_response_code(), which is unreliable under CLI/PHP 8.4 once output has
     * begun (see setUp).
     */
    private function runCapture(callable $fn): array
    {
        ob_start();
        $fn();
        $out = ob_get_clean();
        $body = json_decode($out, true);
        return [$body, (int) ($GLOBALS['ur_last_response_code'] ?? 200)];
    }

    public function testSendResponseSetsBodyAndCode(): void
    {
        [$body, $code] = $this->runCapture(function () {
            sendResponse(['ok' => true, 'n' => 3], 201);
        });
        $this->assertSame(201, $code);
        $this->assertTrue($body['ok']);
        $this->assertSame(3, $body['n']);
    }

    public function testSendErrorEnvelope(): void
    {
        [$body, $code] = $this->runCapture(function () {
            sendError('nope', 422, ['errors' => ['a', 'b']]);
        });
        $this->assertSame(422, $code);
        $this->assertSame('nope', $body['error']);
        $this->assertSame(['a', 'b'], $body['errors']);
    }

    public function testCsrfRejectedWhenMissing(): void
    {
        $_POST['csrf_token'] = '';
        [$body, $code] = $this->runCapture(function () {
            ur_check_csrf();
        });
        $this->assertSame(403, $code);
        $this->assertStringContainsString('CSRF', $body['error']);
    }

    public function testCsrfRejectedWhenWrong(): void
    {
        $_POST['csrf_token'] = 'wrong';
        [$body, $code] = $this->runCapture(function () {
            ur_check_csrf();
        });
        $this->assertSame(403, $code);
    }

    public function testCsrfAcceptedWhenMatching(): void
    {
        $_POST['csrf_token'] = 'test-token';
        [, $code] = $this->runCapture(function () {
            $this->assertTrue(ur_check_csrf());
        });
        $this->assertSame(200, $code);
    }

    public function testSaveConfigNestedFormRoundTrip(): void
    {
        // Simulate exactly the nested POST the form produces.
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'global'     => [
                'defaultRsyncOptions' => [
                    'archive'  => '1',
                    'compress' => '0',
                    'excludes' => ['*.tmp', ''],
                ],
            ],
            'jobs' => [
                0 => [
                    'id'        => '',
                    'name'      => 'Photos',
                    'enabled'   => '1',
                    'schedule'  => '30 2 * * *',
                    'transport' => 'LOCAL',
                    'direction' => 'PUSH',
                    'pairs'     => [
                        0 => ['local' => '/mnt/user/photos/', 'remote' => '/mnt/disk1/backup/photos/'],
                        1 => ['local' => '', 'remote' => ''], // empty template row, dropped
                    ],
                    'rsyncOptions' => [
                        'archive'  => '1',
                        'delete'   => '0',
                        'excludes' => ['thumbs/'],
                        'bwlimit'  => '2000',
                        'rsh'      => 'ssh -i /evil', // not whitelisted -> dropped
                    ],
                    'logLevel'   => 'verbose',
                    'notifyMode' => 'always',
                    'preHook'    => 'echo start',
                    'postHook'   => 'echo done',
                ],
            ],
        ];

        [$body, $code] = $this->runCapture(function () {
            ur_action_save_config();
        });

        $this->assertSame(200, $code, 'response: ' . json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertSame(1, $body['jobs']);

        // Verify what actually landed on disk.
        $cfg = Config::load();
        $this->assertCount(1, $cfg['jobs']);
        $job = $cfg['jobs'][0];
        $this->assertSame('Photos', $job['name']);
        $this->assertSame('j-photos', $job['id']);     // slugged from name
        $this->assertTrue($job['enabled']);
        $this->assertSame('LOCAL', $job['transport']);
        $this->assertSame('verbose', $job['logLevel']);
        $this->assertSame('always', $job['notifyMode']);
        $this->assertSame('echo start', $job['preHook']);
        // Empty template pair dropped -> exactly one pair.
        $this->assertCount(1, $job['pairs']);
        $this->assertSame('/mnt/user/photos/', $job['pairs'][0]['local']);
        // rsyncOptions whitelisted only.
        $this->assertArrayNotHasKey('rsh', $job['rsyncOptions']);
        $this->assertSame('2000', $job['rsyncOptions']['bwlimit']);
        $this->assertSame(['thumbs/'], $job['rsyncOptions']['excludes']);
        // Global defaults persisted + whitelisted.
        $this->assertTrue($cfg['global']['defaultRsyncOptions']['archive']);
        $this->assertSame(['*.tmp'], $cfg['global']['defaultRsyncOptions']['excludes']);
    }

    public function testSaveConfigRejectsInvalidJobWith422(): void
    {
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs' => [
                0 => [
                    'name'      => '',                 // missing name -> invalid
                    'schedule'  => 'not a cron',       // invalid cron
                    'transport' => 'LOCAL',
                    'pairs'     => [0 => ['local' => '/boot', 'remote' => '/mnt/disk1/x/']], // forbidden source
                ],
            ],
        ];

        [$body, $code] = $this->runCapture(function () {
            ur_action_save_config();
        });

        $this->assertSame(422, $code);
        $this->assertArrayHasKey('errors', $body);
        $this->assertNotEmpty($body['errors']);
        // Nothing should have been written.
        $this->assertFalse(is_file(Config::path()));
    }

    public function testSaveConfigDeduplicatesJobIds(): void
    {
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs' => [
                0 => ['name' => 'dup', 'schedule' => '0 3 * * *', 'transport' => 'LOCAL',
                      'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']]],
                1 => ['name' => 'dup', 'schedule' => '0 4 * * *', 'transport' => 'LOCAL',
                      'pairs' => [['local' => '/mnt/user/b/', 'remote' => '/mnt/disk1/b/']]],
            ],
        ];

        [$body, $code] = $this->runCapture(function () {
            ur_action_save_config();
        });

        $this->assertSame(200, $code, json_encode($body));
        $cfg = Config::load();
        $ids = array_column($cfg['jobs'], 'id');
        $this->assertSame($ids, array_unique($ids), 'job ids must be unique');
        $this->assertContains('j-dup', $ids);
        $this->assertContains('j-dup-2', $ids);
    }

    public function testSettingsOnlySaveDoesNotWipeJobs(): void
    {
        // Seed a config with a job.
        $seed = Config::defaults();
        $seed['jobs'][] = Job::normalize([
            'name' => 'keep', 'schedule' => '0 3 * * *', 'transport' => 'LOCAL',
            'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']],
        ]);
        Config::save($seed);

        // Submit ONLY the Global Settings section (no jobs[]).
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'global'     => ['defaultRsyncOptions' => ['archive' => '0', 'compress' => '1']],
        ];

        [$body, $code] = $this->runCapture(function () {
            ur_action_save_config();
        });

        $this->assertSame(200, $code, json_encode($body));
        $cfg = Config::load();
        // The job survives a settings-only save.
        $this->assertCount(1, $cfg['jobs']);
        $this->assertSame('keep', $cfg['jobs'][0]['name']);
        // And the global change landed.
        $this->assertFalse($cfg['global']['defaultRsyncOptions']['archive']);
        $this->assertTrue($cfg['global']['defaultRsyncOptions']['compress']);
    }

    public function testJobsOnlySaveDoesNotWipeGlobalDefaults(): void
    {
        // Seed a config with a non-default global option.
        $seed = Config::defaults();
        $seed['global']['defaultRsyncOptions']['bwlimit'] = '5000';
        Config::save($seed);

        // Submit ONLY the jobs section (no global[]).
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs'       => [
                0 => ['name' => 'j', 'schedule' => '0 3 * * *', 'transport' => 'LOCAL',
                      'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']]],
            ],
        ];

        [$body, $code] = $this->runCapture(function () {
            ur_action_save_config();
        });

        $this->assertSame(200, $code, json_encode($body));
        $cfg = Config::load();
        // The global default survives a jobs-only save.
        $this->assertSame('5000', $cfg['global']['defaultRsyncOptions']['bwlimit']);
        $this->assertCount(1, $cfg['jobs']);
    }

    public function testSaveRefusedWhenExistingConfigUnreadable(): void
    {
        // Newer schema on disk -> Config::load() throws -> save must refuse
        // rather than overwrite with defaults.
        file_put_contents(
            Config::path(),
            json_encode(['schemaVersion' => Config::SCHEMA_VERSION + 9, 'jobs' => [['x' => 1]]])
        );

        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs'       => [0 => ['name' => 'j', 'schedule' => '0 3 * * *', 'transport' => 'LOCAL',
                'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']]]],
        ];

        [$body, $code] = $this->runCapture(function () {
            ur_action_save_config();
        });

        $this->assertSame(409, $code);
        $this->assertArrayHasKey('error', $body);
        // The on-disk (newer) config is untouched.
        $raw = json_decode(file_get_contents(Config::path()), true);
        $this->assertSame(Config::SCHEMA_VERSION + 9, $raw['schemaVersion']);
    }

    public function testSaveWithNeitherSectionRejected(): void
    {
        $_POST = ['action' => 'saveConfig', 'csrf_token' => 'test-token'];
        [, $code] = $this->runCapture(function () {
            ur_action_save_config();
        });
        $this->assertSame(400, $code);
    }

    public function testEmptyJobsSentinelClearsJobsList(): void
    {
        // Seed a config with a job.
        $seed = Config::defaults();
        $seed['jobs'][] = Job::normalize([
            'name' => 'old', 'schedule' => '0 3 * * *', 'transport' => 'LOCAL',
            'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']],
        ]);
        Config::save($seed);

        // Submit the Jobs tab with the sentinel but NO jobs[] (user deleted all).
        $_POST = [
            'action'       => 'saveConfig',
            'csrf_token'   => 'test-token',
            'jobs_present' => '1',
        ];

        [$body, $code] = $this->runCapture(function () {
            ur_action_save_config();
        });

        $this->assertSame(200, $code, json_encode($body));
        $cfg = Config::load();
        $this->assertSame([], $cfg['jobs'], 'sentinel should allow clearing all jobs');
    }

    public function testSendResponseHandlesInvalidUtf8(): void
    {
        // An invalid UTF-8 byte sequence in a string would make a naive
        // json_encode() return false; the helper must still emit valid JSON.
        [$body, $code] = $this->runCapture(function () {
            sendResponse(['ok' => true, 'note' => "bad\xB1utf8"], 200);
        });
        $this->assertSame(200, $code);
        $this->assertIsArray($body, 'response body must be valid JSON');
        $this->assertTrue($body['ok']);
    }

    public function testUnknownActionRejected(): void
    {
        $_POST = ['action' => 'bogus', 'csrf_token' => 'test-token'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        [$body, $code] = $this->runCapture(function () {
            ur_handle_request();
        });
        $this->assertSame(400, $code);
        $this->assertStringContainsString('Unknown action', $body['error']);
    }
}

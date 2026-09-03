<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * A KeyTools double for the discoverHostKey action, so the transport-aware
 * failure note (Credentials::rsyncDaemonNote) can be asserted without a live
 * ssh-keyscan. Resolved by the handler through UR_KEYTOOLS_CLASS, which the one
 * test that uses it defines inside an isolated subprocess (the constant is
 * process-global and HandlerDiscoverHostKeyTest owns it in the shared process).
 */
final class StubDaemonNoteKeyTools extends KeyTools
{
    /** @var array{ok:bool,error?:string,timedOut?:bool,hostKey?:string} */
    public static $next = ['ok' => false, 'error' => 'No host key returned.'];

    public static function discoverHostKey(string $host, int $port = 22, int $timeout = 10): array
    {
        return self::$next;
    }
}

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
        // Credentials as well as config: the DAEMON-transport tests seed
        // connections, and a leftover credentials.json would make the next
        // test's Job::validate see a Connection it never asked for.
        foreach ([Config::path(), Credentials::path()] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    protected function tearDown(): void
    {
        // The daemon module-listing probe seam is a public static: never let one
        // test's stub leak into the next file's tests.
        Rsync::$daemonProbeRunner = null;
        if (is_file(Credentials::path())) {
            unlink(Credentials::path());
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

    public function testSaveConfigPersistsAndClampsRetention(): void
    {
        // A global-only save persists retention, clamped to [1,9999].
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'global'     => ['retention' => '50000'], // over max -> clamps to 9999
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame(9999, Config::load()['global']['retention']);

        // A valid value round-trips; Config::retention() reflects it.
        $_POST['global']['retention'] = '7';
        $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(7, Config::retention());

        // Non-numeric clamps to the default.
        $_POST['global']['retention'] = 'lots';
        $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(100, Config::load()['global']['retention']);
    }

    /**
     * The Global Settings tab posts `global[...]` with no `jobs`, which takes a
     * settings-only early-return path. An invalid rsync option value there must
     * still be rejected: a job left on "use global config" - the default for a
     * new job - takes these values verbatim, so a 200 here would report success
     * and then fail every such job at run time.
     */
    public function testSaveConfigRejectsAnInvalidGlobalRsyncOptionOnAGlobalOnlySave(): void
    {
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'global'     => ['defaultRsyncOptions' => ['remoteRsyncPath' => '/usr/bin/rsync; sudo sh']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(422, $code, json_encode($body));
        $this->assertNotEmpty(array_filter(
            $body['errors'] ?? [],
            static fn($e) => stripos($e, '--rsync-path') !== false
        ));
        // ...and nothing was persisted.
        $this->assertSame('', Config::load()['global']['defaultRsyncOptions']['remoteRsyncPath'] ?? 'MISSING');

        // A valid value on the same route still saves.
        $_POST['global']['defaultRsyncOptions']['remoteRsyncPath'] = '/usr/local/bin/rsync';
        [$ok, $okCode] = $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(200, $okCode, json_encode($ok));
        $this->assertSame(
            '/usr/local/bin/rsync',
            Config::load()['global']['defaultRsyncOptions']['remoteRsyncPath']
        );
    }

    public function testSaveConfigNestedFormRoundTrip(): void
    {
        // Simulate exactly the nested POST the form produces.
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'global'     => [
                'defaultRsyncOptions' => [
                    'archive'       => '1',
                    'compress'      => '0',
                    'omitDirTimes'  => '1',
                    'omitLinkTimes' => '0',
                    // Exactly what the form posts: parallel type[]/pattern[]
                    // arrays paired by index, with the empty starter row still
                    // in place (it must be dropped, not stored blank).
                    'filters'       => [
                        'type'    => ['exclude', 'exclude'],
                        'pattern' => ['*.tmp', ''],
                    ],
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
                        // Order is the whole point: the include must survive
                        // AHEAD of the exclude it is meant to override.
                        'filters'  => [
                            'type'    => ['include', 'exclude'],
                            'pattern' => ['keep/', 'thumbs/'],
                        ],
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
        // The parallel type[]/pattern[] arrays are zipped back into ordered
        // {type, pattern} entries, with the include still first.
        $this->assertSame([
            ['type' => 'include', 'pattern' => 'keep/'],
            ['type' => 'exclude', 'pattern' => 'thumbs/'],
        ], $job['rsyncOptions']['filters']);
        // Global defaults persisted + whitelisted.
        $this->assertTrue($cfg['global']['defaultRsyncOptions']['archive']);
        $this->assertTrue($cfg['global']['defaultRsyncOptions']['omitDirTimes']);
        $this->assertFalse($cfg['global']['defaultRsyncOptions']['omitLinkTimes']);
        // Blank starter row dropped.
        $this->assertSame(
            [['type' => 'exclude', 'pattern' => '*.tmp']],
            $cfg['global']['defaultRsyncOptions']['filters']
        );
    }

    /**
     * previewOptions must return the tokens the runner would actually build,
     * in order - that is the whole reason it exists rather than the form
     * reimplementing the whitelist in JavaScript.
     */
    public function testPreviewOptionsReturnsOrderedTokens(): void
    {
        $_POST = [
            'action'       => 'previewOptions',
            'csrf_token'   => 'test-token',
            'rsyncOptions' => [
                'archive' => '1',
                'perms'   => '0',   // unticked under -a -> must negate
                'filters' => [
                    'type'    => ['include', 'include', 'exclude'],
                    'pattern' => ['*/', 'A*', '*'],
                ],
            ],
        ];

        [$body, $code] = $this->runCapture(function () {
            ur_action_preview_options();
        });

        $this->assertSame(200, $code);
        $this->assertTrue($body['ok']);

        $tokens = $body['tokens'];
        // The issue-#128 ruleset, still in the order the user arranged it.
        $this->assertSame(
            ['--include=*/', '--include=A*', '--exclude=*'],
            array_values(array_filter(
                $tokens,
                static fn(string $t): bool => str_starts_with($t, '--include=') || str_starts_with($t, '--exclude=')
            ))
        );
        // And the unticked archive-implied option really is negated, after -a.
        $this->assertGreaterThan(
            array_search('-a', $tokens, true),
            array_search('--no-perms', $tokens, true)
        );
    }

    /**
     * The preview must not promise a flag the run drops. --contimeout is emitted
     * only on rsync daemon transport (rsync exits 1 for it anywhere else), so the
     * form sends the card's transport and the action honours it - otherwise an
     * SSH job with contimeout=30 previewed "--contimeout=30" and then never sent
     * it, while the preview's own note says these flags are what runs.
     *
     * @param string|null $transport
     */
    #[DataProvider('previewTransportProvider')]
    public function testPreviewOptionsHonoursTheSubmittedTransport(?string $transport, array $expected): void
    {
        $_POST = [
            'action'       => 'previewOptions',
            'csrf_token'   => 'test-token',
            'rsyncOptions' => ['contimeout' => '30'],
        ];
        if ($transport !== null) {
            $_POST['transport'] = $transport;
        }

        [$body, $code] = $this->runCapture(function () {
            ur_action_preview_options();
        });

        $this->assertSame(200, $code);
        $this->assertSame($expected, $body['tokens']);
    }

    /** @return array<string,array{0:?string,1:array<int,string>}> */
    public static function previewTransportProvider(): array
    {
        return [
            'daemon keeps it'      => ['DAEMON', ['--contimeout=30']],
            'ssh drops it'         => ['SSH', []],
            'local drops it'       => ['LOCAL', []],
            // The Global Settings block has no job and posts no transport: it is
            // shared by jobs of every transport, so showing the flag is the
            // honest answer there (and the global save warns about it).
            'no transport at all'  => [null, ['--contimeout=30']],
        ];
    }

    /** A non-string transport (a nested array) must not fatal or leak through. */
    public function testPreviewOptionsIgnoresANonStringTransport(): void
    {
        $_POST = [
            'action'       => 'previewOptions',
            'csrf_token'   => 'test-token',
            'transport'    => ['SSH'],
            'rsyncOptions' => ['contimeout' => '30'],
        ];

        [$body, $code] = $this->runCapture(function () {
            ur_action_preview_options();
        });

        $this->assertSame(200, $code);
        $this->assertSame(['--contimeout=30'], $body['tokens']);
    }

    public function testPreviewOptionsWritesNothing(): void
    {
        $_POST = [
            'action'       => 'previewOptions',
            'csrf_token'   => 'test-token',
            'rsyncOptions' => ['compress' => '1'],
        ];

        $this->runCapture(function () {
            ur_action_preview_options();
        });

        // It is a read-only action: it must not create or touch config.json.
        $this->assertFileDoesNotExist(Config::path());
    }

    public function testPreviewOptionsToleratesAnEmptySubmission(): void
    {
        $_POST = ['action' => 'previewOptions', 'csrf_token' => 'test-token'];

        [$body, $code] = $this->runCapture(function () {
            ur_action_preview_options();
        });

        $this->assertSame(200, $code);
        $this->assertTrue($body['ok']);
        // Missing block -> every option off/empty -> no flags at all.
        $this->assertSame([], $body['tokens']);
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

    public function testListHistoryReturnsPagedRecords(): void
    {
        $id = 'j-hist-handler-' . bin2hex(random_bytes(3));
        try {
            for ($i = 1; $i <= 3; $i++) {
                History::append($id, [
                    'startedAt' => '2026-06-14T12:0' . $i . ':00Z',
                    'state' => Rsync::STATE_SUCCESS, 'exitCode' => $i,
                    'trigger' => 'manual', 'dryRun' => false,
                    'logRef' => 'run-2026061412000' . $i . 'Z.log',
                ]);
            }
            $_GET = ['id' => $id, 'offset' => '0', 'limit' => '2'];
            [$body, $code] = $this->runCapture(fn() => ur_action_list_history());
            $this->assertSame(200, $code);
            $this->assertTrue($body['ok']);
            $this->assertSame(3, $body['total']);
            $this->assertSame(0, $body['offset']);
            $this->assertSame(2, $body['limit']);
            $this->assertCount(2, $body['runs']);
            // newest-first
            $this->assertSame(3, $body['runs'][0]['exitCode']);
        } finally {
            History::delete($id);
        }
    }

    public function testListHistoryRejectsInvalidJobId(): void
    {
        $_GET = ['id' => '../etc'];
        [$body, $code] = $this->runCapture(fn() => ur_action_list_history());
        $this->assertSame(400, $code);
        $this->assertStringContainsString('valid job id', $body['error']);
    }

    public function testListHistoryRequiresGet(): void
    {
        // A POST to a read-only GET poller must be 405.
        $prevMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_POST = ['action' => 'listHistory', 'csrf_token' => 'test-token'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            [$body, $code] = $this->runCapture(fn() => ur_handle_request());
            $this->assertSame(405, $code);
            $this->assertStringContainsString('requires GET', $body['error']);
        } finally {
            // Restore so test order can't leak a POST method into other tests.
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
        }
    }

    public function testListHistoryClampsLimit(): void
    {
        $_GET = ['id' => 'j-x', 'limit' => '9999'];
        [$body] = $this->runCapture(fn() => ur_action_list_history());
        $this->assertSame(100, $body['limit']);
    }

    public function testListHistoryAllJobsAggregatesNewestFirst(): void
    {
        // Hermetic: clear any history files other tests may have left.
        foreach (glob(rtrim(UR_CONFIG_BASE, '/') . '/runs/*.history.jsonl') ?: [] as $f) {
            @unlink($f);
        }
        History::append('j-a', ['startedAt' => '2026-06-14T12:00:00Z', 'state' => 'SUCCESS', 'jobName' => 'Alpha', 'logRef' => 'a1.log']);
        History::append('j-b', ['startedAt' => '2026-06-14T13:00:00Z', 'state' => 'FAILED',  'jobName' => 'Beta',  'logRef' => 'b1.log']);
        History::append('j-a', ['startedAt' => '2026-06-14T14:00:00Z', 'state' => 'SUCCESS', 'jobName' => 'Alpha', 'logRef' => 'a2.log']);
        try {
            // No id => all-jobs view (the default).
            $_GET = ['offset' => '0', 'limit' => '25'];
            [$body, $code] = $this->runCapture(fn() => ur_action_list_history());
            $this->assertSame(200, $code);
            $this->assertTrue($body['ok']);
            $this->assertTrue($body['allJobs']);
            $this->assertSame(3, $body['total']);
            // Newest-first across BOTH jobs, each row tagged with its job.
            $this->assertSame('a2.log', $body['runs'][0]['logRef']);
            $this->assertSame('j-a', $body['runs'][0]['jobId']);
            $this->assertSame('Alpha', $body['runs'][0]['jobName']);
            $this->assertSame('b1.log', $body['runs'][1]['logRef']);
            $this->assertSame('j-b', $body['runs'][1]['jobId']);
            $this->assertSame('a1.log', $body['runs'][2]['logRef']);
        } finally {
            History::delete('j-a');
            History::delete('j-b');
        }
    }

    public function testRemovingJobKeepsItsHistory(): void
    {
        // Seed a config with a job, then record a run for it.
        $seed = Config::defaults();
        $seed['jobs'][] = Job::normalize([
            'name' => 'doomed', 'schedule' => '0 3 * * *', 'transport' => 'LOCAL',
            'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']],
        ]);
        Config::save($seed);
        $jobId = Config::load()['jobs'][0]['id'];
        $this->assertNotSame('', $jobId);

        try {
            History::append($jobId, [
                'startedAt' => '2026-06-14T12:00:00Z',
                'state' => Rsync::STATE_SUCCESS, 'exitCode' => 0,
                'trigger' => 'manual', 'dryRun' => false,
                'logRef' => 'run-20260614T120000Z.log',
            ]);

            // Save a config that REMOVES the job (a different job in its place).
            $_POST = [
                'action'     => 'saveConfig',
                'csrf_token' => 'test-token',
                'jobs'       => [
                    0 => ['name' => 'replacement', 'schedule' => '0 4 * * *', 'transport' => 'LOCAL',
                          'pairs' => [['local' => '/mnt/user/b/', 'remote' => '/mnt/disk1/b/']]],
                ],
            ];
            [$body, $code] = $this->runCapture(fn() => ur_action_save_config());
            $this->assertSame(200, $code, json_encode($body));

            // The removed job's history must SURVIVE (history piles up; only
            // uninstall clears it). Regression guard for the removed purge.
            $page = History::list($jobId, 0, 25);
            $this->assertSame(1, $page['total']);
            $this->assertSame(0, $page['runs'][0]['exitCode']);
        } finally {
            History::delete($jobId);
        }
    }

    // =====================================================================
    // rsync DAEMON (rsyncd) transport - issue #139
    // =====================================================================

    /**
     * Like runCapture(), but also hands back the RAW response text. The
     * secret-leak assertions have to look at the bytes that actually reach the
     * browser, not at a decoded array.
     *
     * @return array{0:string,1:mixed,2:int} [rawJson, decodedBody, statusCode]
     */
    private function runCaptureRaw(callable $fn): array
    {
        ob_start();
        $fn();
        $out = ob_get_clean();
        return [$out, json_decode($out, true), (int) ($GLOBALS['ur_last_response_code'] ?? 200)];
    }

    /** Persist a credentials structure for the actions under test to read. */
    private function seedConnections(array $connections): void
    {
        $creds = Credentials::defaults();
        foreach ($connections as $conn) {
            $creds['connections'][] = Credentials::mergeConnection($conn);
        }
        Credentials::save($creds);
    }

    /** A complete, valid DAEMON connection record. */
    private function daemonConnection(string $id = 'c-nas', string $password = ''): array
    {
        return [
            'id'        => $id,
            'name'      => 'NAS',
            'transport' => 'DAEMON',
            'host'      => 'nas.local',
            'username'  => 'moduser',
            'password'  => ($password !== '') ? Credentials::obfuscate($password) : '',
        ];
    }

    /** A complete, valid SSH connection record. */
    private function sshConnection(string $id = 'c-ssh'): array
    {
        return [
            'id'          => $id,
            'name'        => 'Tower',
            'transport'   => 'SSH',
            'host'        => 'tower.local',
            'username'    => 'root',
            'authMethod'  => 'KEYFILE',
            'keyFilePath' => '/root/.ssh/id_ed25519',
        ];
    }

    /**
     * The exact 40-key rsyncOptions block a job stores when the form posts no
     * rsyncOptions at all (every checkbox unticked, every scalar blank), with
     * $overrides applied. Written out rather than derived from
     * Config::defaultRsyncOptions() so the whole-job assertions below really are
     * whole-array assertions: a new, removed or reordered key fails them.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function storedOptions(array $overrides = []): array
    {
        return array_merge([
            'recursive'       => false,
            'archive'         => false,
            'compress'        => false,
            'humanReadable'   => false,
            'times'           => false,
            'omitDirTimes'    => false,
            'omitLinkTimes'   => false,
            'perms'           => false,
            'owner'           => false,
            'group'           => false,
            'devices'         => false,
            'xattrs'          => false,
            'acls'            => false,
            'symlinks'        => false,
            'hardlinks'       => false,
            'sparse'          => false,
            'numericIds'      => false,
            'partial'         => false,
            'inplace'         => false,
            'checksum'        => false,
            'update'          => false,
            'wholeFile'       => false,
            'sizeOnly'        => false,
            'ignoreExisting'  => false,
            'delete'          => false,
            'deleteExcluded'  => false,
            'mkpath'          => false,
            'filters'         => [],
            'maxDelete'       => '',
            'bwlimit'         => '',
            'timeout'         => '',
            'contimeout'      => '',
            'maxSize'         => '',
            'minSize'         => '',
            'chmod'           => '',
            'tempDir'         => '',
            'backupDir'       => '',
            'compressLevel'   => '',
            'modifyWindow'    => '',
            'remoteRsyncPath' => '',
        ], $overrides);
    }

    /** The POST a Jobs-tab save of one DAEMON job produces. */
    private function daemonJobPost(string $remote, string $connectionId = 'c-nas'): array
    {
        return [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs'       => [
                0 => [
                    'name'         => 'NAS pull',
                    'schedule'     => '30 2 * * *',
                    'transport'    => 'DAEMON',
                    'direction'    => 'PULL',
                    'connectionId' => $connectionId,
                    'pairs'        => [0 => ['local' => '/mnt/user/backup/', 'remote' => $remote]],
                ],
            ],
        ];
    }

    // --- saving a DAEMON job through the front controller -------------------

    /**
     * The happy path, end to end: a DAEMON job whose pair remote side is a
     * MODULE REFERENCE saves and lands on disk with the module reference intact
     * (no leading slash added, no host prefixed) and direction PULL preserved
     * (Job::normalize forces PUSH only for LOCAL).
     */
    public function testSaveConfigAcceptsADaemonJobWithAModuleReference(): void
    {
        $this->seedConnections([$this->daemonConnection()]);

        // Through the front controller, not the action directly: the routing and
        // the CSRF gate are part of "end to end".
        $prevMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $_POST = $this->daemonJobPost('rsync_bkp/photos');
            [$body, $code] = $this->runCapture(fn() => ur_handle_request());
        } finally {
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
        }

        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertSame(1, $body['jobs']);

        $jobs = Config::load()['jobs'];
        $this->assertCount(1, $jobs);
        $this->assertSame([
            'id'                => 'j-nas-pull',
            'name'              => 'NAS pull',
            'enabled'           => true,
            'manualOnly'        => false,
            'schedule'          => '30 2 * * *',
            'transport'         => 'DAEMON',
            'connectionId'      => 'c-nas',
            'direction'         => 'PULL',
            'pairs'             => [['local' => '/mnt/user/backup/', 'remote' => 'rsync_bkp/photos']],
            'useGlobalDefaults' => false,
            'rsyncOptions'      => $this->storedOptions(),
            'logLevel'          => 'normal',
            'preHook'           => '',
            'postHook'          => '',
            'notifyMode'        => 'failure-only',
        ], $jobs[0]);
    }

    /**
     * A bare module root is a legal DAEMON target (exact parity with "/data" on
     * SSH), and a trailing slash is preserved verbatim - it is meaningful to
     * rsync, so the guardrails must not eat it.
     */
    public function testSaveConfigAcceptsABareDaemonModuleRootAndKeepsItsTrailingSlash(): void
    {
        $this->seedConnections([$this->daemonConnection()]);

        $_POST = $this->daemonJobPost('rsync_bkp/');
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame(
            [['local' => '/mnt/user/backup/', 'remote' => 'rsync_bkp/']],
            Config::load()['jobs'][0]['pairs']
        );
    }

    /**
     * The paste a user migrating from an rsync command line actually makes: the
     * whole "host::module" (or rsync:// URL, or the absolute path the module
     * points at) typed into the module box. Each must be rejected with its own
     * message, and NOTHING may be persisted.
     */
    public function testSaveConfigRejectsADaemonModuleReferenceThatCarriesTheHostOrAPath(): void
    {
        $this->seedConnections([$this->daemonConnection()]);

        $cases = [
            'nas.local::rsync_bkp' => "Job \"NAS pull\": Pair #1 source (module) 'nas.local::rsync_bkp' includes "
                . "the daemon host. The host, port and username come from the job's Connection, so enter only "
                . 'the module reference here (for example rsync_bkp or rsync_bkp/photos).',
            'rsync://nas.local/rsync_bkp' => "Job \"NAS pull\": Pair #1 source (module) 'rsync://nas.local/rsync_bkp' "
                . "includes the daemon host. The host, port and username come from the job's Connection, so enter "
                . 'only the module reference here (for example rsync_bkp or rsync_bkp/photos).',
            '/volume1/Backup' => "Job \"NAS pull\": Pair #1 source (module) '/volume1/Backup' must not begin with "
                . '"/". An rsync daemon path is relative to the module, so enter the module reference (for example '
                . 'rsync_bkp or rsync_bkp/photos), not an absolute filesystem path.',
            'nas.local:873/rsync_bkp' => "Job \"NAS pull\": Pair #1 source (module) 'nas.local:873/rsync_bkp' includes "
                . "a host or port. The host, port and username come from the job's Connection, so enter only the "
                . 'module reference here.',
        ];

        foreach ($cases as $paste => $expected) {
            $_POST = $this->daemonJobPost((string) $paste);
            [$body, $code] = $this->runCapture(fn() => ur_action_save_config());

            $this->assertSame(422, $code, "paste: $paste => " . json_encode($body));
            $this->assertSame([$expected], $body['errors'], "paste: $paste");
            $this->assertFileDoesNotExist(Config::path(), "paste: $paste must persist nothing");
        }
    }

    /**
     * The transport cross-check, both ways. A DAEMON job pointed at an SSH
     * Connection (and the reverse) is a 422 at save time - the Runner would
     * otherwise refuse it much later, mid-run.
     */
    public function testSaveConfigRejectsAJobWhoseConnectionTransportDisagrees(): void
    {
        $this->seedConnections([$this->daemonConnection(), $this->sshConnection()]);

        // DAEMON job -> SSH connection.
        $_POST = $this->daemonJobPost('rsync_bkp', 'c-ssh');
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(422, $code, json_encode($body));
        $this->assertSame([
            'Job "NAS pull": This job uses rsync daemon transport, but the selected Connection uses SSH '
            . 'transport. Pick a Connection whose Transport is "rsync daemon (rsyncd)".',
        ], $body['errors']);

        // SSH job -> DAEMON connection.
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs'       => [
                0 => [
                    'name'         => 'Push',
                    'schedule'     => '0 3 * * *',
                    'transport'    => 'SSH',
                    'direction'    => 'PUSH',
                    'connectionId' => 'c-nas',
                    'pairs'        => [0 => ['local' => '/mnt/user/a/', 'remote' => '/volume1/b/']],
                ],
            ],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(422, $code, json_encode($body));
        $this->assertSame([
            'Job "Push": This job uses SSH transport, but the selected Connection uses rsync daemon (rsyncd) '
            . 'transport. Pick a Connection whose Transport is "SSH".',
        ], $body['errors']);

        $this->assertFileDoesNotExist(Config::path());
    }

    /** A DAEMON job with no Connection selected gets the daemon-specific message. */
    public function testSaveConfigRejectsADaemonJobWithNoConnection(): void
    {
        $_POST = $this->daemonJobPost('rsync_bkp', '');
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());

        $this->assertSame(422, $code, json_encode($body));
        $this->assertSame(['Job "NAS pull": An rsync daemon job must select a Connection.'], $body['errors']);
    }

    // --- no breaking change for existing SSH jobs ---------------------------

    /**
     * THE upgrade case. credentials.json written by a pre-daemon build has no
     * `transport` key on any connection at all; the job cross-check must read it
     * as SSH (?? 'SSH') rather than report a mismatch, or every existing SSH job
     * in the wild would 422 on the first save after the update.
     */
    public function testSaveConfigAcceptsAnSshJobWhoseStoredConnectionPredatesTheTransportField(): void
    {
        // Written RAW, bypassing Credentials::save() - which would merge the new
        // key in and destroy the very thing under test.
        file_put_contents(Credentials::path(), json_encode([
            'schemaVersion' => 1,
            'keys'          => [],
            'connections'   => [[
                'id'          => 'c-legacy',
                'name'        => 'Legacy',
                'host'        => 'tower.local',
                'username'    => 'root',
                'port'        => 22,
                'authMethod'  => 'KEYFILE',
                'keyFilePath' => '/root/.ssh/id_ed25519',
            ]],
        ]));
        $this->assertArrayNotHasKey(
            'transport',
            json_decode((string) file_get_contents(Credentials::path()), true)['connections'][0],
            'precondition: the on-disk record must carry no transport key'
        );

        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs'       => [
                0 => [
                    'name'         => 'Nightly',
                    'schedule'     => '15 2 * * *',
                    'transport'    => 'SSH',
                    'direction'    => 'PUSH',
                    'connectionId' => 'c-legacy',
                    'pairs'        => [0 => ['local' => '/mnt/user/data/', 'remote' => '/volume1/backup/data/']],
                ],
            ],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame('c-legacy', Config::load()['jobs'][0]['connectionId']);
    }

    /**
     * An ordinary SSH job save stores exactly what it stored before the daemon
     * transport existed: the same 15 job keys in the same order, the same
     * 40-key options block, nothing added.
     */
    public function testSaveConfigStoresAnSshJobExactlyAsBefore(): void
    {
        $this->seedConnections([$this->sshConnection()]);

        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs'       => [
                0 => [
                    'name'         => 'Nightly',
                    'schedule'     => '15 2 * * *',
                    'transport'    => 'SSH',
                    'direction'    => 'PUSH',
                    'connectionId' => 'c-ssh',
                    'pairs'        => [0 => ['local' => '/mnt/user/data/', 'remote' => '/volume1/backup/data/']],
                    'rsyncOptions' => ['archive' => '1', 'compress' => '1', 'bwlimit' => '2000'],
                    'logLevel'     => 'normal',
                    'notifyMode'   => 'failure-only',
                ],
            ],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());

        $this->assertSame(200, $code, json_encode($body));
        $job = Config::load()['jobs'][0];
        $this->assertSame([
            'id'                => 'j-nightly',
            'name'              => 'Nightly',
            'enabled'           => true,
            'manualOnly'        => false,
            'schedule'          => '15 2 * * *',
            'transport'         => 'SSH',
            'connectionId'      => 'c-ssh',
            'direction'         => 'PUSH',
            'pairs'             => [['local' => '/mnt/user/data/', 'remote' => '/volume1/backup/data/']],
            'useGlobalDefaults' => false,
            'rsyncOptions'      => $this->storedOptions(['archive' => true, 'compress' => true, 'bwlimit' => '2000']),
            'logLevel'          => 'normal',
            'preHook'           => '',
            'postHook'          => '',
            'notifyMode'        => 'failure-only',
        ], $job);
        // The no-breaking-change ledger: no new key on either level.
        $this->assertCount(15, $job);
        $this->assertCount(40, $job['rsyncOptions']);
    }

    /**
     * --contimeout is a hard rsync failure off daemon transport, so the save
     * warns (never errors) that the stored value is inert - and says nothing on
     * a DAEMON job, where rsync accepts it.
     */
    public function testSaveConfigWarnsThatContimeoutIsInertOffDaemonTransport(): void
    {
        $this->seedConnections([$this->daemonConnection(), $this->sshConnection()]);

        $expected = 'Job "Nightly": The --contimeout option only applies to rsync daemon (rsyncd) transport; '
            . 'rsync rejects it outright on SSH and Local transfers, so it is not sent for this job.';

        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'jobs'       => [
                0 => [
                    'name'         => 'Nightly',
                    'schedule'     => '15 2 * * *',
                    'transport'    => 'SSH',
                    'direction'    => 'PUSH',
                    'connectionId' => 'c-ssh',
                    'pairs'        => [0 => ['local' => '/mnt/user/a/', 'remote' => '/volume1/b/']],
                    'rsyncOptions' => ['contimeout' => '15'],
                ],
            ],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertContains($expected, $body['warnings']);
        // A warning, not an error: the value is still stored.
        $this->assertSame('15', Config::load()['jobs'][0]['rsyncOptions']['contimeout']);

        // Same option on a DAEMON job: no warning, because rsync accepts it there.
        $_POST = $this->daemonJobPost('rsync_bkp');
        $_POST['jobs'][0]['rsyncOptions'] = ['contimeout' => '15'];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertNotContains(
            'Job "NAS pull": The --contimeout option only applies to rsync daemon (rsyncd) transport; '
            . 'rsync rejects it outright on SSH and Local transfers, so it is not sent for this job.',
            $body['warnings']
        );
    }

    /**
     * The one path with NO signal at all before this: Job::validate's contimeout
     * warning reads the JOB's own options, and a job on "use global config"
     * stores its own contimeout as '' - so setting the value once, in Global
     * Settings (the natural place for a connect timeout), warned nowhere while
     * Rsync::buildArgv silently dropped it for every SSH and Local job that
     * inherited it. Warn at the field.
     */
    public function testGlobalSettingsWarnsThatContimeoutIsDaemonOnly(): void
    {
        $expected = 'Global settings: the --contimeout option only applies to rsync daemon (rsyncd) '
            . 'transport; rsync rejects it outright on SSH and Local transfers, so it is not sent for '
            . 'jobs on those transports.';

        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'global'     => ['defaultRsyncOptions' => ['archive' => '1', 'contimeout' => '30']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertContains($expected, $body['warnings']);
        // A warning, never an error: the value is still stored.
        $this->assertSame('30', Config::load()['global']['defaultRsyncOptions']['contimeout']);

        // ...and a job that inherits those defaults is the reason it matters: it
        // carries no contimeout of its own, so nothing else would ever mention it.
        $this->assertSame(
            '',
            Job::normalizeRsyncOptions([])['contimeout'],
            'a useGlobalDefaults job stores an empty contimeout, which is why Job::validate stays silent'
        );
    }

    /** No contimeout in the global defaults, no warning. */
    public function testGlobalSettingsWithNoContimeoutIsSilent(): void
    {
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'global'     => ['defaultRsyncOptions' => ['archive' => '1']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());

        $this->assertSame(200, $code, json_encode($body));
        foreach ($body['warnings'] as $w) {
            $this->assertStringNotContainsString('--contimeout', $w);
        }
    }

    // --- testConnection: the daemon module-listing probe ---------------------

    /**
     * The daemon branch of testConnection: the probe argv is exactly the pinned
     * one (no --password-file - a listing is answered BEFORE authentication, so
     * rsync would never read it), the parsed module names come back, and the
     * message says out loud that the listing verifies neither the username nor
     * the secret.
     *
     * Also the secret-leak guard: the connection HAS a stored module secret and
     * the response must contain neither it, nor its obfuscated form, nor any
     * tmpfs secret path.
     */
    public function testDaemonTestConnectionListsModulesAndLeaksNoSecretOrTmpfsPath(): void
    {
        $secret = 's3cret-module-pw';
        $this->seedConnections([$this->daemonConnection('c-nas', $secret)]);
        $this->assertNotSame(
            '',
            (string) Credentials::load()['connections'][0]['password'],
            'precondition: the connection must really hold a secret, or the leak assertions are vacuous'
        );

        $seen = [];
        Rsync::$daemonProbeRunner = static function (array $argv) use (&$seen): array {
            $seen[] = $argv;
            return [0, "@RSYNCD: 31.0\nrsync_bkp      \tBackups\nphotos         \tPhotos\n@RSYNCD: EXIT\n"];
        };

        $_POST = ['action' => 'testConnection', 'csrf_token' => 'test-token', 'id' => 'c-nas'];
        [$raw, $body, $code] = $this->runCaptureRaw(fn() => ur_action_test_connection());

        $this->assertSame(200, $code, $raw);
        $this->assertSame([[
            Rsync::rsyncPath(),
            '--contimeout=' . Rsync::DAEMON_PROBE_TIMEOUT,
            '--timeout=' . Rsync::DAEMON_PROBE_TIMEOUT,
            '--port=873',
            '--',
            'moduser@nas.local::',
        ]], $seen);
        $this->assertSame([
            'ok'      => true,
            'reason'  => 'ok',
            'message' => 'Connected to the rsync daemon and listed 2 module(s): rsync_bkp, photos.'
                . ' NOTE: a module listing is answered BEFORE authentication, so this does NOT verify'
                . ' the username or the module secret. Run a dry-run to test those.',
            'modules' => ['rsync_bkp', 'photos'],
        ], $body);

        $this->assertStringNotContainsString($secret, $raw);
        $this->assertStringNotContainsString(Credentials::obfuscate($secret), $raw);
        $this->assertStringNotContainsString(rtrim(Ssh::$runtimeBase, '/'), $raw);
    }

    /**
     * An SSH connection must never reach the daemon probe, and its response must
     * still carry the `modules` key so the page has ONE renderer for both
     * branches. The connection is deliberately incomplete so Ssh::testConnection
     * short-circuits in its config arm and spawns no ssh.
     */
    public function testSshTestConnectionNeverProbesTheDaemonAndStillCarriesModules(): void
    {
        $this->seedConnections([[
            'id' => 'c-blank', 'name' => 'Blank', 'transport' => 'SSH', 'host' => '', 'username' => '',
        ]]);

        $probed = 0;
        Rsync::$daemonProbeRunner = static function (array $argv) use (&$probed): array {
            $probed++;
            return [0, ''];
        };

        $_POST = ['action' => 'testConnection', 'csrf_token' => 'test-token', 'id' => 'c-blank'];
        [$raw, $body, $code] = $this->runCaptureRaw(fn() => ur_action_test_connection());

        $this->assertSame(200, $code, $raw);
        $this->assertSame(0, $probed, 'an SSH connection must not reach the daemon probe');
        $this->assertSame([
            'ok'      => false,
            'reason'  => 'config',
            'message' => 'Host and username are required to test a connection.',
            'modules' => [],
        ], $body);
    }

    // --- CSRF on the actions the daemon transport changed --------------------

    /**
     * testConnection now spawns an rsync daemon probe. The CSRF gate must run
     * BEFORE it - a 403 alone would not prove that, so we also assert the probe
     * seam was never called.
     */
    public function testDaemonTestConnectionIsRejectedWithoutCsrfAndNeverProbes(): void
    {
        $this->seedConnections([$this->daemonConnection()]);

        $probed = 0;
        Rsync::$daemonProbeRunner = static function (array $argv) use (&$probed): array {
            $probed++;
            return [0, "mod\tx\n"];
        };

        $prevMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            foreach (['' => 'missing', 'wrong-token' => 'wrong'] as $token => $what) {
                $_POST = ['action' => 'testConnection', 'id' => 'c-nas', 'csrf_token' => (string) $token];
                [$body, $code] = $this->runCapture(fn() => ur_handle_request());
                $this->assertSame(403, $code, "a $what token must 403");
                $this->assertSame('Invalid or missing CSRF token.', $body['error']);
            }
            $this->assertSame(0, $probed, 'the probe must not run before CSRF passes');
        } finally {
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
        }
    }

    /**
     * ...and the match-ANY path still admits it: a token that matches ONLY the
     * canonical var.ini value (with a stale $GLOBALS['var'] token also present,
     * which the old "first source wins" logic let mask it) reaches the probe.
     */
    public function testDaemonTestConnectionAcceptsACsrfTokenThatOnlyVarIniKnows(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $this->seedConnections([$this->daemonConnection()]);

        $probed = 0;
        Rsync::$daemonProbeRunner = static function (array $argv) use (&$probed): array {
            $probed++;
            return [0, "rsync_bkp      \tBackups\n"];
        };

        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "csrf_token=\"canonical-token\"\n");
        $prevMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $GLOBALS['var'] = ['csrf_token' => 'stale-var-token'];
            $_POST = ['action' => 'testConnection', 'id' => 'c-nas', 'csrf_token' => 'canonical-token'];
            [$body, $code] = $this->runCapture(fn() => ur_handle_request());

            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame(1, $probed);
            $this->assertSame(['rsync_bkp'], $body['modules']);
        } finally {
            @unlink($path);
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
            $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        }
    }

    /** Saving a DAEMON job without a CSRF token 403s and writes no config. */
    public function testDaemonJobSaveIsRejectedWithoutCsrfAndWritesNothing(): void
    {
        $this->seedConnections([$this->daemonConnection()]);

        $prevMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $_POST = $this->daemonJobPost('rsync_bkp');
            unset($_POST['csrf_token']);
            [$body, $code] = $this->runCapture(fn() => ur_handle_request());

            $this->assertSame(403, $code);
            $this->assertSame('Invalid or missing CSRF token.', $body['error']);
            $this->assertFileDoesNotExist(Config::path());
        } finally {
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
        }
    }

    /** Saving a DAEMON connection without a CSRF token 403s and stores nothing. */
    public function testDaemonConnectionSaveIsRejectedWithoutCsrfAndStoresNothing(): void
    {
        $prevMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $_POST = [
                'action'              => 'saveCredentials',
                'csrf_token'          => 'wrong-token',
                'connections_present' => '1',
                'connections'         => [
                    0 => [
                        'name' => 'NAS', 'transport' => 'DAEMON', 'host' => 'nas.local',
                        'username' => 'moduser', 'password' => 'never-stored', 'port' => '',
                    ],
                ],
            ];
            [$body, $code] = $this->runCapture(fn() => ur_handle_request());

            $this->assertSame(403, $code);
            $this->assertSame('Invalid or missing CSRF token.', $body['error']);
            $this->assertFileDoesNotExist(Credentials::path());
        } finally {
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
        }
    }

    // --- saveCredentials: the daemon connection round-trip -------------------

    /**
     * A DAEMON connection saves with the rsyncd port defaulted (an empty port
     * field must become 873, not 22), the SSH-only fields cleared, and the
     * module secret stored obfuscated - while the RESPONSE carries neither the
     * plaintext secret nor its obfuscated form. The unencrypted-protocol warning
     * is emitted verbatim.
     */
    public function testSaveCredentialsStoresADaemonConnectionAndNeverEchoesItsSecret(): void
    {
        $secret = 's3cret-module-pw';
        $prevMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        try {
            $_POST = [
                'action'              => 'saveCredentials',
                'csrf_token'          => 'test-token',
                'connections_present' => '1',
                'connections'         => [
                    0 => [
                        'name'          => 'NAS',
                        'transport'     => 'DAEMON',
                        'host'          => 'nas.local',
                        'username'      => 'moduser',
                        'password'      => $secret,
                        // The card's hidden SSH controls still POST: display:none
                        // does not suppress submission. None may be stored.
                        'authMethod'    => 'KEYFILE',
                        'keyFilePath'   => '/root/.ssh/id_ed25519',
                        'remoteHostKey' => 'nas.local ssh-ed25519 AAAAstale',
                        'port'          => '',
                    ],
                ],
            ];
            [$raw, $body, $code] = $this->runCaptureRaw(fn() => ur_handle_request());

            $this->assertSame(200, $code, $raw);
            $this->assertContains(
                'NAS: The rsync daemon protocol is not encrypted. Only a challenge/response (MD4 with old '
                . 'peers) protects the module secret, and file names and file contents travel in clear. Use '
                . 'SSH transport on any untrusted network.',
                $body['warnings']
            );

            $stored = Credentials::load()['connections'][0];
            $this->assertSame('DAEMON', $stored['transport']);
            $this->assertSame(873, $stored['port'], 'a blank port must default to the rsyncd port, not 22');
            $this->assertSame('', $stored['keyFilePath']);
            $this->assertSame('', $stored['keyId']);
            $this->assertSame('', $stored['remoteHostKey']);
            // The secret really was stored (so the leak assertions below are not
            // vacuous) - obfuscated, never in the clear.
            $this->assertNotSame('', $stored['password']);
            $this->assertNotSame($secret, $stored['password']);
            $this->assertSame($secret, Credentials::deobfuscate($stored['password']));

            $this->assertStringNotContainsString($secret, $raw);
            $this->assertStringNotContainsString($stored['password'], $raw);
            $this->assertStringNotContainsString(rtrim(Ssh::$runtimeBase, '/'), $raw);
        } finally {
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
        }
    }

    /**
     * Flipping an existing Connection's transport does not re-validate the jobs
     * that reference it, so the save warns (and names them) instead of silently
     * leaving a set of jobs that can only fail.
     */
    public function testSaveCredentialsWarnsWhenAConnectionTransportChangesUnderExistingJobs(): void
    {
        $this->seedConnections([$this->daemonConnection('c-nas')]);
        $cfg = Config::defaults();
        $cfg['jobs'][] = Job::normalize([
            'name' => 'NAS pull', 'schedule' => '0 3 * * *', 'transport' => 'DAEMON',
            'connectionId' => 'c-nas', 'direction' => 'PULL',
            'pairs' => [['local' => '/mnt/user/backup/', 'remote' => 'rsync_bkp']],
        ]);
        Config::save($cfg);

        $_POST = [
            'action'              => 'saveCredentials',
            'csrf_token'          => 'test-token',
            'connections_present' => '1',
            'connections'         => [
                0 => [
                    'id' => 'c-nas', 'name' => 'NAS', 'transport' => 'SSH', 'host' => 'nas.local',
                    'username' => 'moduser', 'password' => '', 'authMethod' => 'KEYFILE',
                    'keyFilePath' => '/root/.ssh/id_ed25519', 'port' => '22',
                ],
            ],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertContains(
            'NAS: Transport changed to SSH. These jobs still reference this Connection and will fail until '
            . 'their own Transport matches: NAS pull.',
            $body['warnings']
        );
    }

    // --- discoverHostKey: the transport-aware note ---------------------------

    /**
     * discoverHostKey keeps its two-argument rsyncDaemonNote() call and inherits
     * the reworded text: keyscanning port 873 (or a pasted daemon address) now
     * points the user at the Connection's new Transport setting instead of
     * telling them the plugin cannot speak rsyncd at all.
     *
     * Isolated: UR_KEYTOOLS_CLASS is a process-global constant that
     * HandlerDiscoverHostKeyTest claims in the shared process, and without it
     * this action would spawn a real ssh-keyscan.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDiscoverHostKeyFailureNoteIsTransportAware(): void
    {
        if (defined('UR_KEYTOOLS_CLASS')) {
            $this->markTestSkipped('UR_KEYTOOLS_CLASS already claimed in this process');
        }
        define('UR_KEYTOOLS_CLASS', StubDaemonNoteKeyTools::class);
        StubDaemonNoteKeyTools::$next = ['ok' => false, 'error' => 'No host key returned.'];

        // Port 873 on an SSH keyscan: the rsyncd port note.
        $_POST = ['host' => 'nas.local', 'port' => '873'];
        [$body, $code] = $this->runCapture(fn() => ur_action_discover_host_key());
        $this->assertSame(422, $code);
        $this->assertSame(
            'No host key returned. Port 873 is the rsync daemon (rsyncd) port, which is a different protocol '
            . 'from rsync-over-SSH. Either enable SSH on the remote host and use its SSH port (usually 22), '
            . 'or set this Connection\'s Transport to "rsync daemon (rsyncd)".',
            $body['error']
        );

        // A daemon address pasted into the Host field.
        $_POST = ['host' => 'nas.local::rsync_bkp', 'port' => '22'];
        [$body, $code] = $this->runCapture(fn() => ur_action_discover_host_key());
        $this->assertSame(422, $code);
        $this->assertSame(
            'No host key returned. This looks like an rsync daemon address (rsync:// or host::module). '
            . 'This Connection uses SSH transport, so enter just the hostname or IP here and put the remote '
            . 'path on the job\'s pair. To use an rsync daemon instead, set this Connection\'s Transport to '
            . '"rsync daemon (rsyncd)".',
            $body['error']
        );
    }
}

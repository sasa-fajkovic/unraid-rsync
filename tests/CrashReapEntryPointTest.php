<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('UR_REAP_CRASHED_TESTING')) {
    define('UR_REAP_CRASHED_TESTING', true);
}
require_once __DIR__ . '/../source/scripts/reap-crashed.php';

/**
 * The plugin-start crash reap: scripts/reap-crashed.php (started detached by
 * event/started, after a delay) reaps a run a reboot cut off, and
 * scripts/apply-cron.php - run by the .plg install step at boot, before the
 * notification agents are up - no longer does.
 */
final class CrashReapEntryPointTest extends TestCase
{
    /** @var array<int,string> */
    private array $captured = [];

    private string $fakeBin = '';

    protected function setUp(): void
    {
        $this->fakeBin = sys_get_temp_dir() . '/ur-reap-notify-' . getmypid() . '-' . bin2hex(random_bytes(4));
        file_put_contents($this->fakeBin, "#!/bin/sh\nexit 0\n");
        chmod($this->fakeBin, 0755);
        Notify::$notifyPath = $this->fakeBin;
        Notify::$runner = function (string $command): int {
            $this->captured[] = $command;
            return 0;
        };
        Config::save(Config::defaults());
        array_map('unlink', glob(rtrim(UR_CONFIG_BASE, '/') . '/runs/*.inflight.json') ?: []);
    }

    protected function tearDown(): void
    {
        TestSeams::reset();
        putenv('UR_CRASH_REAP_DELAY');
        @unlink($this->fakeBin);
    }

    /** Save an always-notify job and leave only its /boot in-flight record, as a reboot would. */
    private function rebootedJob(): string
    {
        $id     = 'j-reap-' . bin2hex(random_bytes(4));
        $config = Config::load();
        $config['jobs'][] = array_merge(Config::defaultJob(), ['id' => $id, 'name' => $id, 'notifyMode' => 'always']);
        Config::save($config);
        RunState::writeInflight($id, [
            'pid'        => 4242,
            'trigger'    => 'schedule',
            'startedAt'  => '2026-10-20T03:00:00Z',
            'currentLog' => UR_RUNTIME_BASE . '/gone-after-reboot/run-20261020T030000Z.log',
        ]);
        return $id;
    }

    public function testEntryPointReapsARunARebootCutOff(): void
    {
        $id = $this->rebootedJob();
        $this->assertNull(RunState::read($id), 'no tmpfs state survives a reboot');

        ur_reap_crashed();

        $this->assertCount(1, $this->captured);
        $this->assertStringContainsString("Unraid Rsync: $id: previous run died without finishing", $this->captured[0]);
        $this->assertSame('crashed', History::list($id)['runs'][0]['reason']);
        $this->assertFileDoesNotExist(RunState::inflightPath($id));

        ur_reap_crashed();
        $this->assertCount(1, $this->captured, 'once');
    }

    public function testEntryPointStillReapsWhenTheConfigIsUnreadable(): void
    {
        $id = $this->rebootedJob();
        file_put_contents(rtrim(UR_CONFIG_BASE, '/') . '/config.json', 'not json');
        try {
            ur_reap_crashed();
        } finally {
            Config::save(Config::defaults());
        }
        $this->assertCount(1, $this->captured, 'the record names its job');
        $this->assertFileDoesNotExist(RunState::inflightPath($id));
    }

    public function testDelayDefaultsTo180AndTakesAWholeNumberOverride(): void
    {
        putenv('UR_CRASH_REAP_DELAY');
        $this->assertSame(180, ur_reap_crash_delay());
        putenv('UR_CRASH_REAP_DELAY=0');
        $this->assertSame(0, ur_reap_crash_delay());
        putenv('UR_CRASH_REAP_DELAY=600');
        $this->assertSame(600, ur_reap_crash_delay());
        foreach (['', 'abc', '-5', '1.5'] as $junk) {
            putenv('UR_CRASH_REAP_DELAY=' . $junk);
            $this->assertSame(180, ur_reap_crash_delay(), "junk '$junk'");
        }
    }

    public function testApplyCronNoLongerReaps(): void
    {
        // Run the real CLI entry (its main block) against this suite's bases,
        // with update_cron pointed nowhere so the live crontab is never touched.
        $id   = $this->rebootedJob();
        $dir  = realpath(__DIR__ . '/../source');
        $code = sprintf(
            'define("UR_CONFIG_BASE", %s); define("UR_RUNTIME_BASE", %s); require %s;'
            . ' Cron::$updateCronPath = "/nonexistent/update_cron"; require %s;',
            var_export(UR_CONFIG_BASE, true),
            var_export(UR_RUNTIME_BASE, true),
            var_export($dir . '/include/Cron.php', true),
            var_export($dir . '/scripts/apply-cron.php', true)
        );
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out);

        $this->assertStringContainsString('cron', implode("\n", $out), 'the cron apply still ran');
        $this->assertFileExists(RunState::inflightPath($id), 'left for the delayed reap');
        $this->assertSame(0, History::list($id)['total']);
    }

    public function testEventStartedLaunchesTheReapDetached(): void
    {
        $hook = (string) file_get_contents(__DIR__ . '/../source/event/started');
        $this->assertMatchesRegularExpression('#setsid "\$PHP_BIN" "\$REAP" </dev/null >/dev/null 2>&1 &#', $hook);
        $this->assertStringContainsString('scripts/reap-crashed.php', $hook);
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PageHelpers: the display rules the Jobs/Overview tabs, getStatus and (through
 * window.urUtil) the browser share. The next-run clause order is load-bearing
 * (AGENTS.md): manual-only, then disabled, then em-dash, then the time.
 */
final class PageHelpersTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private const NOW = 1767230640; // 2026-01-01 01:24:00 UTC

    public function testConfigIsParsedOncePerRequestAndALoadErrorRepeats(): void
    {
        $cfg = Config::defaults();
        $cfg['jobs'][] = array_merge(Config::defaultJob(), ['id' => 'j-once', 'name' => 'Once']);
        Config::save($cfg);
        PageHelpers::resetRequest();
        try {
            $this->assertSame('Once', PageHelpers::config()['jobs'][0]['name']);
            file_put_contents(Config::path(), '{not json');
            $this->assertSame('Once', PageHelpers::config()['jobs'][0]['name'], 'one parse per request');

            PageHelpers::resetRequest();
            $messages = [];
            for ($i = 0; $i < 2; $i++) {
                try {
                    PageHelpers::config();
                } catch (Throwable $e) {
                    $messages[] = $e->getMessage();
                }
            }
            $this->assertCount(2, $messages, 'every tab sees the load error');
            $this->assertSame($messages[0], $messages[1]);
        } finally {
            @unlink(Config::path());
            PageHelpers::resetRequest();
        }
    }

    public function testRunningStateIsCheckedOncePerJobPerRequest(): void
    {
        $id  = 'j-memo';
        $cfg = Config::defaults();
        $cfg['jobs'][] = array_merge(Config::defaultJob(), ['id' => $id, 'name' => $id, 'notifyMode' => 'off']);
        Config::save($cfg);
        Notify::$runner = static fn(string $command): int => 0;
        $dead = ['pid' => 4242, 'trigger' => 'schedule', 'startedAt' => '2026-10-20T03:00:00Z'];
        PageHelpers::resetRequest();
        try {
            RunState::writeInflight($id, $dead);
            $this->assertFalse(PageHelpers::running($id));
            $this->assertFileDoesNotExist(RunState::inflightPath($id), 'the first check reaps the dead run');

            RunState::writeInflight($id, $dead);
            $this->assertFalse(PageHelpers::running($id));
            $this->assertFileExists(RunState::inflightPath($id), 'a second check in the same request is not a second isRunning');

            PageHelpers::resetRequest();
            PageHelpers::running($id);
            $this->assertFileDoesNotExist(RunState::inflightPath($id));
        } finally {
            Notify::$runner = null;
            @unlink(RunState::inflightPath($id));
            @unlink(Config::path());
            PageHelpers::resetRequest();
        }
    }

    public function testPageBodiesGoThroughTheRequestScopedAccessors(): void
    {
        foreach (glob(__DIR__ . '/../source/pages/*.php') ?: [] as $page) {
            $src = (string) file_get_contents($page);
            $this->assertStringNotContainsString('Config::load(', $src, basename($page));
            $this->assertStringNotContainsString('Config::secretsDir(', $src, basename($page));
            $this->assertDoesNotMatchRegularExpression('/RunState::isRunning\(\$/', $src, basename($page));
        }
    }

    public function testManualOnlyWinsOverDisabledAndSchedule(): void
    {
        $job = ['manualOnly' => true, 'enabled' => false, 'schedule' => '0 3 * * *'];
        $this->assertSame('manual (on demand)', PageHelpers::nextRunLabel($job, self::NOW));
        $job['enabled'] = true;
        $this->assertSame('manual (on demand)', PageHelpers::nextRunLabel($job, self::NOW));
    }

    public function testDisabledWinsOverAComputableSchedule(): void
    {
        $this->assertSame('disabled', PageHelpers::nextRunLabel(['enabled' => false, 'schedule' => '0 3 * * *'], self::NOW));
    }

    public function testEnabledWithNoComputableNextIsAnEmDash(): void
    {
        $this->assertSame('—', PageHelpers::nextRunLabel(['enabled' => true, 'schedule' => ''], self::NOW));
        $this->assertSame('—', PageHelpers::nextRunLabel(['enabled' => true, 'schedule' => 'not a cron'], self::NOW));
    }

    public function testEnabledShowsServerLocalTimeAndRelativeHint(): void
    {
        $this->assertSame(
            '2026-01-01 03:00 (in 1h 36m)',
            PageHelpers::nextRunLabel(['enabled' => true, 'schedule' => '0 3 * * *'], self::NOW)
        );
    }

    /** @return array<string,array{int,string}> */
    public static function relativeCases(): array
    {
        return [
            'future h+m'          => [5760, 'in 1h 36m'],
            'past d+h'            => [-(6 * 86400 + 7 * 3600 + 7 * 60), '6d 7h ago'],
            'zero second unit'    => [-(6 * 86400 + 7 * 60), '6d ago'],
            'seconds only'        => [-45, '45s ago'],
            'minutes + seconds'   => [-90, '1m 30s ago'],
            'exact day'           => [86400, 'in 1d'],
            'now'                 => [0, 'now'],
        ];
    }

    #[DataProvider('relativeCases')]
    public function testRelativeTimeUsesTwoLargestAdjacentUnits(int $delta, string $expected): void
    {
        $this->assertSame($expected, PageHelpers::relativeTime(self::NOW + $delta, self::NOW));
    }

    public function testLastRunLabel(): void
    {
        $this->assertSame('—', PageHelpers::lastRunLabel(null, self::NOW));
        $this->assertSame('—', PageHelpers::lastRunLabel(['finishedAt' => 'garbage'], self::NOW));
        $this->assertSame(
            '2026-01-01 01:23 (45s ago)',
            PageHelpers::lastRunLabel(['finishedAt' => '2026-01-01T01:23:15Z'], self::NOW)
        );
        // A finishedAt ahead of the clock reads "now", never "in …".
        $this->assertSame(
            '2026-01-01 01:30 (now)',
            PageHelpers::lastRunLabel(['finishedAt' => '2026-01-01T01:30:00Z'], self::NOW)
        );
    }

    public function testDeriveState(): void
    {
        $this->assertSame('RUNNING', PageHelpers::deriveState(true, ['state' => 'FAILED']));
        $this->assertSame('SUCCESS', PageHelpers::deriveState(false, ['state' => 'SUCCESS']));
        $this->assertSame('PENDING', PageHelpers::deriveState(false, null));
        $this->assertSame('PENDING', PageHelpers::deriveState(false, ['state' => '']));
    }

    public function testStateVocabulary(): void
    {
        $this->assertSame(['cls' => 'warning', 'label' => 'Timeout'], PageHelpers::state('timeout'));
        $this->assertSame(['cls' => 'pending', 'label' => 'Never run'], PageHelpers::state('PENDING'));
        $this->assertSame(['cls' => 'pending', 'label' => 'Skipped'], PageHelpers::state('SKIPPED'));
        $this->assertSame(['cls' => 'pending', 'label' => 'Never run'], PageHelpers::state('SOMETHING-NEW'));
        $rsyncStates = array_filter(
            (new ReflectionClass(Rsync::class))->getConstants(),
            fn($k) => str_starts_with($k, 'STATE_'),
            ARRAY_FILTER_USE_KEY
        );
        foreach ($rsyncStates as $s) {
            $this->assertArrayHasKey($s, PageHelpers::STATES, "badge vocabulary misses $s");
        }
    }

    public function testHookLineCount(): void
    {
        $this->assertSame(2, PageHelpers::hookLineCount('', "a\nb"));
        $this->assertSame(1, PageHelpers::hookLineCount("one\n\n", 'x'));
        $this->assertSame(3, PageHelpers::hookLineCount("a\nb\nc", ''));
    }

    public function testDurationRuleIsRunners(): void
    {
        $this->assertSame('1h 2m 3s', Runner::formatDuration(3723));
        $this->assertSame('2m 5s', Runner::formatDuration(125));
        $this->assertSame('45s', Runner::formatDuration(45));
    }

    public function testPreviewTokensDropOnlyTheFlagsArchiveImpliesAndKeepTheOrder(): void
    {
        $tokens = ['-r', '-a', '--no-D', '-z', '-h', '-t', '-p', '-o', '-g', '-l', '--max-delete=25', '--exclude=-r', '--include=*/'];
        $this->assertSame(['-a', '--no-D', '-z', '-h', '--max-delete=25', '--exclude=-r', '--include=*/'], PageHelpers::previewTokens($tokens));
        // Without -a every token is meaningful on its own.
        $plain = ['-r', '-t', '--delete'];
        $this->assertSame($plain, PageHelpers::previewTokens($plain));
    }

    public function testPreviewTokensMatchTheRealMapperForTheDefaults(): void
    {
        $tokens = Rsync::optionTokens(Config::defaultRsyncOptions());
        $shown  = PageHelpers::previewTokens($tokens);
        // Display only: the argv still carries everything, and what is shown is
        // the same tokens in the same relative order.
        $this->assertSame(array_values(array_intersect($tokens, $shown)), $shown);
        foreach (array_intersect_key(Rsync::BOOL_FLAGS, Rsync::ARCHIVE_IMPLIED) as $flag) {
            $this->assertNotContains($flag, in_array('-a', $tokens, true) ? $shown : []);
        }
    }

    /** UXB-8: every exit code the History tooltip must explain has a meaning. */
    public function testRsyncExitMeaningsCoverTheHistoryTooltipCodes(): void
    {
        foreach ([0, 1, 2, 3, 5, 10, 11, 12, 13, 14, 20, 21, 22, 23, 24, 25, 30, 35, 255] as $code) {
            $this->assertNotSame('', Rsync::EXIT_MEANINGS[$code] ?? '', "exit $code");
        }
    }

    public function testADryRunIsANeutralSecondLineNamedByItsOutcome(): void
    {
        $this->assertNull(PageHelpers::lastDryRun(null));
        $this->assertNull(PageHelpers::lastDryRun(['state' => 'FAILED', 'dryRun' => false]));
        $cases = ['SUCCESS' => 'Dry-run OK', 'FAILED' => 'Dry-run failed', 'ABORTED' => 'Dry-run aborted',
            'WARNING' => 'Dry-run warning', 'CRASHED' => 'Dry-run crashed'];
        foreach ($cases as $state => $label) {
            $this->assertSame(
                ['label' => $label, 'finishedAt' => '2026-01-01T01:23:15Z'],
                PageHelpers::lastDryRun(['state' => $state, 'dryRun' => true, 'finishedAt' => '2026-01-01T01:23:15Z'])
            );
        }
        $this->assertSame('', PageHelpers::dryRunLine(null, self::NOW));
        $this->assertSame(
            'Dry-run OK · 2026-01-01 01:23 (45s ago)',
            PageHelpers::dryRunLine(['label' => 'Dry-run OK', 'finishedAt' => '2026-01-01T01:23:15Z'], self::NOW)
        );
    }

    public function testTransportAndAuthAreNamedInWordsNotCodes(): void
    {
        $this->assertSame(['SSH', 'DAEMON', 'LOCAL'], array_keys(PageHelpers::TRANSPORT_LABELS));
        $this->assertSame('Key file on disk', PageHelpers::authLabel(['transport' => 'SSH', 'authMethod' => 'KEYFILE']));
        $this->assertSame('Managed key', PageHelpers::authLabel(['transport' => 'SSH', 'authMethod' => 'KEY']));
        $this->assertSame('Password', PageHelpers::authLabel(['authMethod' => 'PASSWORD']));
        // A daemon's authMethod is whatever its hidden select posted: never shown.
        $this->assertSame('Module secret', PageHelpers::authLabel(['transport' => 'DAEMON', 'authMethod' => 'KEYFILE', 'password' => 'x']));
        $this->assertSame('None', PageHelpers::authLabel(['transport' => 'DAEMON', 'authMethod' => 'PASSWORD', 'password' => '']));
        $this->assertSame('ODD', PageHelpers::authLabel(['authMethod' => 'odd']));
    }

    public function testConnectionOptionsHideTheOtherTransportButNeverDropOne(): void
    {
        $conns = [
            ['id' => 'c-legacy', 'name' => 'Legacy'],              // predates the transport key: SSH
            ['id' => 'c-d', 'name' => 'Daemon', 'transport' => 'daemon'],
            ['id' => '', 'name' => 'no id'],
            'not a record',
        ];
        $ssh = PageHelpers::connectionOptions($conns, 'SSH', '');
        $this->assertSame(['c-legacy', 'c-d'], array_column($ssh, 'id'));
        $this->assertSame([false, true], array_column($ssh, 'hidden'));
        $this->assertSame(['Legacy', 'Daemon [rsyncd]'], array_column($ssh, 'label'));
        $this->assertSame(['SSH', 'DAEMON'], array_column($ssh, 'transport'));

        // The selected one stays visible, marked as a mismatch.
        $sel = PageHelpers::connectionOptions($conns, 'DAEMON', 'c-legacy');
        $this->assertSame([false, false], array_column($sel, 'hidden'));
        $this->assertSame([true, false], array_column($sel, 'mismatch'));

        // A Local job uses no connection: nothing filtered or marked.
        $local = PageHelpers::connectionOptions($conns, 'LOCAL', 'c-d');
        $this->assertSame([false, false], array_column($local, 'hidden'));
        $this->assertSame([false, false], array_column($local, 'mismatch'));
    }

    public function testOptionsLineIsThePreviewOfTheTransportsArgv(): void
    {
        $opts = Config::defaultRsyncOptions();
        $this->assertSame(
            implode(' ', PageHelpers::previewTokens(Rsync::optionTokens(Config::mergeRsyncOptions($opts), 'SSH'))),
            PageHelpers::optionsLine($opts, 'SSH')
        );
        $opts['delete'] = true;
        $opts['timeout'] = '';
        $this->assertStringContainsString('--delete', PageHelpers::optionsLine($opts, 'SSH'));
        // A blank timeout means 900 s on a daemon only (Rsync::DAEMON_DEFAULT_TIMEOUT).
        $this->assertStringContainsString('--timeout=900', PageHelpers::optionsLine($opts, 'DAEMON'));
        $this->assertStringNotContainsString('--timeout=', PageHelpers::optionsLine($opts, 'SSH'));
    }

    public function testHostKeyFingerprintsMatchSshKeygen(): void
    {
        // ssh-keygen -lf of this key: 256 SHA256:+ZjfDhL77345365Qi0LcRjDa7c+ztg8TCGjlwLbhKx4 (ED25519)
        $key = 'AAAAC3NzaC1lZDI1NTE5AAAAIFH++n/GvCAHHKUzjnBGVlq/ldPq0biex6u5ivgysR44';
        $this->assertSame(
            [['type' => 'ssh-ed25519', 'fingerprint' => 'SHA256:+ZjfDhL77345365Qi0LcRjDa7c+ztg8TCGjlwLbhKx4']],
            PageHelpers::hostKeyFingerprints("# nas.local:22 SSH-2.0-OpenSSH\r\nnas.local ssh-ed25519 $key\r\n")
        );
        // A bare "type key" line, a hashed host and a marker all parse; junk does not.
        $multi = PageHelpers::hostKeyFingerprints("ssh-ed25519 $key\n|1|abc=|def= ssh-ed25519 $key\n@revoked h ssh-ed25519 $key\nnas.local ssh-ed25519 !!notbase64\njunk");
        $this->assertCount(3, $multi);
        $this->assertSame([], PageHelpers::hostKeyFingerprints(''));
    }
}

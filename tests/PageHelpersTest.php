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
}

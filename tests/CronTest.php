<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for Cron.php:
 *   - apply(): cron-file line generation (enabled jobs only; the EXACT
 *     "runner.php --job=<id>" spelling + the /usr/local/emhttp/... path; atomic
 *     rewrite; update_cron invoked via an injected stub with the absolute path;
 *     empty/removed when there are no enabled jobs).
 *   - nextRun(): pure 5-field cron evaluation across many expressions, including
 *     the standard day-of-month/day-of-week OR semantics, month rollover,
 *     end-of-month / February, and malformed -> null.
 *
 * Everything is driven through the injectable seams so nothing touches the real
 * /usr/local/sbin/update_cron or the live crontab: Cron::$updateCronRunner is a
 * stub recording the argv it was handed, and UR_CONFIG_BASE (set by bootstrap)
 * points the cron file at a per-process temp dir.
 */
final class CronTest extends TestCase
{
    /** @var array<int,array<int,string>> argv arrays the stub was called with */
    private array $updateCronCalls = [];

    protected function setUp(): void
    {
        // Clean any cron file from a prior test.
        $path = Cron::cronFilePath();
        if (is_file($path)) {
            unlink($path);
        }
        // Reset config so apply(null) sees a known state if a test uses it.
        $cfg = Config::path();
        if (is_file($cfg)) {
            unlink($cfg);
        }

        // Stub update_cron: record the argv, report success.
        $this->updateCronCalls = [];
        Cron::$updateCronRunner = function (array $argv): int {
            $this->updateCronCalls[] = $argv;
            return 0;
        };
        Cron::$updateCronPath = null; // use the documented default path
    }

    protected function tearDown(): void
    {
        // Critical: clear the static seam so other test classes (e.g. the
        // handler tests) are not affected by our stub.
        Cron::$updateCronRunner = null;
        Cron::$updateCronPath = null;
    }

    /** Build a minimal enabled job. */
    private function job(string $id, string $schedule, bool $enabled = true): array
    {
        return [
            'id'       => $id,
            'name'     => $id,
            'enabled'  => $enabled,
            'schedule' => $schedule,
        ];
    }

    private function configWith(array $jobs): array
    {
        $cfg = Config::defaults();
        $cfg['jobs'] = $jobs;
        return $cfg;
    }

    // =====================================================================
    // apply() - cron file generation
    // =====================================================================

    public function testApplyWritesOneLinePerEnabledJob(): void
    {
        $config = $this->configWith([
            $this->job('j-music', '0 3 * * *', true),
            $this->job('j-docs', '*/15 * * * *', true),
            $this->job('j-off', '0 4 * * *', false),    // disabled -> skipped
        ]);

        $res = Cron::apply($config);

        $this->assertTrue($res['ok']);
        $this->assertSame(2, $res['enabledJobs']);
        $this->assertTrue($res['wrote']);
        $this->assertFalse($res['removed']);

        $content = file_get_contents(Cron::cronFilePath());
        $lines = array_values(array_filter(explode("\n", trim($content)), fn($l) => $l !== ''));

        // Header + 2 job lines.
        $this->assertSame('# Unraid Rsync', $lines[0]);
        $this->assertCount(3, $lines);

        // The disabled job must not appear.
        $this->assertStringNotContainsString('j-off', $content);

        // Exact line shape incl. the load-bearing spelling + absolute path.
        $this->assertSame(
            '0 3 * * * php /usr/local/emhttp/plugins/unraid.rsync/scripts/runner.php --job=j-music >/dev/null 2>&1',
            $lines[1]
        );
        $this->assertSame(
            '*/15 * * * * php /usr/local/emhttp/plugins/unraid.rsync/scripts/runner.php --job=j-docs >/dev/null 2>&1',
            $lines[2]
        );
    }

    public function testApplyInvokesUpdateCronWithAbsolutePathArgv(): void
    {
        $config = $this->configWith([$this->job('j-a', '0 3 * * *')]);
        Cron::apply($config);

        $this->assertCount(1, $this->updateCronCalls);
        // argv ARRAY (not a shell string), element 0 is the absolute path.
        $this->assertSame(['/usr/local/sbin/update_cron'], $this->updateCronCalls[0]);
    }

    public function testApplyWithNoEnabledJobsRemovesFileButStillRunsUpdateCron(): void
    {
        // First write a file with an enabled job.
        Cron::apply($this->configWith([$this->job('j-a', '0 3 * * *')]));
        $this->assertFileExists(Cron::cronFilePath());

        // Now apply with everything disabled -> file removed, update_cron still run.
        $res = Cron::apply($this->configWith([$this->job('j-a', '0 3 * * *', false)]));

        $this->assertTrue($res['ok']);
        $this->assertSame(0, $res['enabledJobs']);
        $this->assertTrue($res['removed']);
        $this->assertFileDoesNotExist(Cron::cronFilePath());
        // update_cron called BOTH times (write, then clear).
        $this->assertCount(2, $this->updateCronCalls);
    }

    public function testApplyWithZeroJobsWritesNothingAndStillRunsUpdateCron(): void
    {
        $res = Cron::apply($this->configWith([]));
        $this->assertTrue($res['ok']);
        $this->assertSame(0, $res['enabledJobs']);
        $this->assertFalse($res['wrote']);
        // No prior file -> nothing to remove, but update_cron still runs.
        $this->assertFalse($res['removed']);
        $this->assertFileDoesNotExist(Cron::cronFilePath());
        $this->assertCount(1, $this->updateCronCalls);
    }

    public function testApplyIsAtomicRewrite(): void
    {
        // A pre-existing cron file is fully replaced (not appended to).
        file_put_contents(Cron::cronFilePath(), "# stale\nGARBAGE LINE\n");
        Cron::apply($this->configWith([$this->job('j-new', '0 1 * * *')]));

        $content = file_get_contents(Cron::cronFilePath());
        $this->assertStringNotContainsString('GARBAGE', $content);
        $this->assertStringNotContainsString('# stale', $content);
        $this->assertStringContainsString('--job=j-new', $content);
        // Single header line.
        $this->assertSame(1, substr_count($content, '# Unraid Rsync'));
    }

    public function testApplySkipsEnabledJobWithEmptySchedule(): void
    {
        $config = $this->configWith([
            $this->job('j-good', '0 3 * * *', true),
            $this->job('j-blank', '', true),   // enabled but no schedule -> skipped
        ]);
        $res = Cron::apply($config);
        $this->assertSame(1, $res['enabledJobs']);
        $content = file_get_contents(Cron::cronFilePath());
        $this->assertStringContainsString('--job=j-good', $content);
        $this->assertStringNotContainsString('j-blank', $content);
    }

    public function testApplyCollapsesWhitespaceInSchedule(): void
    {
        // A schedule with tabs/multiple spaces must not shift the command tokens.
        $config = $this->configWith([$this->job('j-ws', "0\t3   *  * *", true)]);
        Cron::apply($config);
        $content = file_get_contents(Cron::cronFilePath());
        $this->assertStringContainsString(
            '0 3 * * * php /usr/local/emhttp/plugins/unraid.rsync/scripts/runner.php --job=j-ws >/dev/null 2>&1',
            $content
        );
    }

    public function testApplySkipsJobWithUnsafeId(): void
    {
        // A crafted/legacy id with shell metacharacters must NEVER be emitted -
        // the cron line is run by /bin/sh, so this would be command injection.
        $config = $this->configWith([
            $this->job('j-ok', '0 3 * * *', true),
            $this->job('j-a; rm -rf /', '0 4 * * *', true),
            $this->job('j-b && curl evil', '0 5 * * *', true),
            $this->job('j-c$(whoami)', '0 6 * * *', true),
        ]);
        $res = Cron::apply($config);

        $this->assertSame(1, $res['enabledJobs']); // only the safe one
        $content = file_get_contents(Cron::cronFilePath());
        $this->assertStringContainsString('--job=j-ok', $content);
        $this->assertStringNotContainsString('rm -rf', $content);
        $this->assertStringNotContainsString('curl evil', $content);
        $this->assertStringNotContainsString('whoami', $content);
    }

    public function testApplyAcceptsSafeIdCharacters(): void
    {
        // Dots, underscores, hyphens, digits and letters are all allowed.
        $config = $this->configWith([$this->job('j-My_job.2-3', '0 3 * * *', true)]);
        $res = Cron::apply($config);
        $this->assertSame(1, $res['enabledJobs']);
        $this->assertStringContainsString('--job=j-My_job.2-3', file_get_contents(Cron::cronFilePath()));
    }

    public function testApplySkipsJobWithMalformedSchedule(): void
    {
        // A malformed schedule would shift the command tokens / be a junk crontab
        // line; only well-formed 5-field expressions are emitted.
        $config = $this->configWith([
            $this->job('j-ok', '0 3 * * *', true),
            $this->job('j-bad', 'not a cron', true),
            $this->job('j-short', '0 3 * *', true),    // only 4 fields
            $this->job('j-range', '99 3 * * *', true), // minute out of range
        ]);
        $res = Cron::apply($config);
        $this->assertSame(1, $res['enabledJobs']);
        $content = file_get_contents(Cron::cronFilePath());
        $this->assertStringContainsString('--job=j-ok', $content);
        $this->assertStringNotContainsString('j-bad', $content);
        $this->assertStringNotContainsString('j-short', $content);
        $this->assertStringNotContainsString('j-range', $content);
    }

    public function testApplyReportsUpdateCronFailure(): void
    {
        Cron::$updateCronRunner = fn(array $argv): int => 3; // non-zero
        $res = Cron::apply($this->configWith([$this->job('j-a', '0 3 * * *')]));
        $this->assertFalse($res['ok']);
        $this->assertSame(3, $res['updateCronCode']);
        // File was still written (the failure was in the crontab rebuild).
        $this->assertTrue($res['wrote']);
    }

    public function testApplyLoadsConfigWhenNoneSupplied(): void
    {
        // Persist a config, then call apply(null) -> it should read from disk.
        $cfg = $this->configWith([$this->job('j-disk', '0 5 * * *')]);
        Config::save($cfg);
        $res = Cron::apply();
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['enabledJobs']);
        $this->assertStringContainsString('--job=j-disk', file_get_contents(Cron::cronFilePath()));
    }

    // =====================================================================
    // nextRun() - cron expression evaluation
    // =====================================================================

    /** Fixed reference: 2026-06-13 12:34:00 local time (a Saturday). */
    private function from(): int
    {
        return mktime(12, 34, 0, 6, 13, 2026);
    }

    public function testNextRunDaily(): void
    {
        // 0 3 * * *  -> next 03:00. From 12:34 on the 13th -> 03:00 on the 14th.
        $next = Cron::nextRun('0 3 * * *', $this->from());
        $this->assertNotNull($next);
        $this->assertSame('2026-06-14 03:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunEveryFifteenMinutes(): void
    {
        // */15 * * * * from 12:34 -> 12:45.
        $next = Cron::nextRun('*/15 * * * *', $this->from());
        $this->assertSame('2026-06-13 12:45', date('Y-m-d H:i', $next));
    }

    public function testNextRunHourlyAtZero(): void
    {
        // 0 * * * * from 12:34 -> 13:00.
        $next = Cron::nextRun('0 * * * *', $this->from());
        $this->assertSame('2026-06-13 13:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunList(): void
    {
        // 0,30 * * * * from 12:34 -> 13:00 (next of {00,30}).
        $next = Cron::nextRun('0,30 * * * *', $this->from());
        $this->assertSame('2026-06-13 13:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunMinuteListSameHour(): void
    {
        // 40,50 12 * * * from 12:34 -> 12:40 same day.
        $next = Cron::nextRun('40,50 12 * * *', $this->from());
        $this->assertSame('2026-06-13 12:40', date('Y-m-d H:i', $next));
    }

    public function testNextRunRange(): void
    {
        // 0 9-17 * * * from 12:34 -> 13:00 (next hour in 9..17).
        $next = Cron::nextRun('0 9-17 * * *', $this->from());
        $this->assertSame('2026-06-13 13:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunRangeStep(): void
    {
        // 0 0-12/6 * * *  -> hours {0,6,12}. From 12:34 -> next is 00:00 next day.
        $next = Cron::nextRun('0 0-12/6 * * *', $this->from());
        $this->assertSame('2026-06-14 00:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunWeekdayOnly(): void
    {
        // 0 0 * * 1  (Mondays). From Sat 2026-06-13 -> Mon 2026-06-15 00:00.
        $next = Cron::nextRun('0 0 * * 1', $this->from());
        $this->assertSame('2026-06-15 00:00', date('Y-m-d H:i', $next));
        $this->assertSame('1', date('w', $next)); // Monday
    }

    public function testNextRunWeekdayNamed(): void
    {
        // Named weekday: "mon" must behave like 1.
        $next = Cron::nextRun('0 0 * * mon', $this->from());
        $this->assertSame('2026-06-15 00:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunSundayAsSeven(): void
    {
        // dow 7 == Sunday. From Sat 2026-06-13 -> Sun 2026-06-14 00:00.
        $next = Cron::nextRun('0 0 * * 7', $this->from());
        $this->assertSame('2026-06-14 00:00', date('Y-m-d H:i', $next));
        $this->assertSame('0', date('w', $next));
    }

    public function testNextRunDayOfMonthOnly(): void
    {
        // 0 0 15 * * (the 15th). From the 13th -> 2026-06-15 00:00.
        $next = Cron::nextRun('0 0 15 * *', $this->from());
        $this->assertSame('2026-06-15 00:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunDomDowOrSemantics(): void
    {
        // 0 0 13 * 1 : BOTH dom(13) and dow(Mon) restricted -> fires when EITHER
        // matches. From Sat 2026-06-13 12:34 the NEXT match is the next Monday
        // (2026-06-15), not next month's 13th, because dow Monday comes first.
        $next = Cron::nextRun('0 0 13 * 1', $this->from());
        $this->assertSame('2026-06-15 00:00', date('Y-m-d H:i', $next));

        // And a day that is the 13th but NOT a Monday still matches via dom: take
        // a reference just after midnight on 2026-07-12 (a Sunday); the 13th
        // (Monday) matches both, but to prove OR we use 2026-09 where the 13th is
        // a Sunday -> still matches via dom.
        $refSep = mktime(0, 5, 0, 9, 1, 2026); // 2026-09-01
        $nextSep = Cron::nextRun('0 0 13 * 1', $refSep);
        // 2026-09-07 is the first Monday in Sep (dow match) -> comes before the
        // 13th, so OR picks the Monday.
        $this->assertSame('2026-09-07 00:00', date('Y-m-d H:i', $nextSep));
    }

    public function testNextRunWildcardStepDayIsUnrestrictedForOrRule(): void
    {
        // vixie-cron decides the dom/dow OR rule by the FIRST char of the day
        // field. "*/01" is star-prefixed -> dom is UNRESTRICTED, so only the dow
        // (Mondays) constrains the day. Without this, dom would wrongly count as
        // restricted, the OR rule would kick in, dom would match every day, and
        // the weekday constraint would be ignored.
        // From Sat 2026-06-13 12:34 -> next Monday 2026-06-15 00:00.
        $next = Cron::nextRun('0 0 */01 * 1', $this->from());
        $this->assertSame('2026-06-15 00:00', date('Y-m-d H:i', $next));
        $this->assertSame('1', date('w', $next)); // Monday, not just "next day"

        // Leading-zero variant behaves identically to "*".
        $this->assertSame(
            Cron::nextRun('0 0 * * 1', $this->from()),
            Cron::nextRun('0 0 */01 * 1', $this->from())
        );
    }

    public function testNextRunWildcardStepTwoIsUnrestrictedForOrRule(): void
    {
        // "*/2" is also star-prefixed -> unrestricted for the OR rule (matching
        // real cron, which keys only on the leading "*"). dow Monday therefore
        // constrains the day on its own.
        $next = Cron::nextRun('0 0 */2 * 1', $this->from());
        $this->assertSame('2026-06-15 00:00', date('Y-m-d H:i', $next));
        $this->assertSame('1', date('w', $next));
    }

    public function testNextRunDomRestrictedDowStarUsesDomOnly(): void
    {
        // 0 0 15 * *  : only dom restricted -> dow ignored. The 15th regardless
        // of weekday.
        $ref = mktime(0, 0, 0, 6, 16, 2026); // just after the 15th
        $next = Cron::nextRun('0 0 15 * *', $ref);
        $this->assertSame('2026-07-15 00:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunMonthRollover(): void
    {
        // 0 0 1 * *  (1st of month). From 2026-06-13 -> 2026-07-01 00:00.
        $next = Cron::nextRun('0 0 1 * *', $this->from());
        $this->assertSame('2026-07-01 00:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunYearRollover(): void
    {
        // 0 0 1 1 *  (Jan 1). From 2026-06-13 -> 2027-01-01 00:00.
        $next = Cron::nextRun('0 0 1 1 *', $this->from());
        $this->assertSame('2027-01-01 00:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunNamedMonth(): void
    {
        // 0 0 1 jan * behaves like month 1.
        $next = Cron::nextRun('0 0 1 jan *', $this->from());
        $this->assertSame('2027-01-01 00:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunEndOfMonth(): void
    {
        // 0 0 31 * *  (the 31st) skips months without a 31st. From 2026-06-13
        // (June has 30 days) -> 2026-07-31 00:00.
        $next = Cron::nextRun('0 0 31 * *', $this->from());
        $this->assertSame('2026-07-31 00:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunFebruary29LeapYear(): void
    {
        // 0 0 29 2 *  (Feb 29). 2026/2027 are not leap years; 2028 is.
        $next = Cron::nextRun('0 0 29 2 *', $this->from());
        $this->assertSame('2028-02-29 00:00', date('Y-m-d H:i', $next));
    }

    public function testNextRunImpossibleExpressionReturnsNull(): void
    {
        // 0 0 30 2 *  : Feb never has a 30th -> no match within the horizon.
        $this->assertNull(Cron::nextRun('0 0 30 2 *', $this->from()));
    }

    public function testNextRunIsStrictlyAfterReference(): void
    {
        // When the reference is EXACTLY a fire minute, the next run is the
        // following occurrence, never "now".
        $ref = mktime(3, 0, 0, 6, 13, 2026); // 03:00 exactly
        $next = Cron::nextRun('0 3 * * *', $ref);
        $this->assertSame('2026-06-14 03:00', date('Y-m-d H:i', $next));
    }

    // --- malformed -> null --------------------------------------------------

    /**
     * @dataProvider malformedExpressions
     */
    public function testNextRunMalformedReturnsNull(string $expr): void
    {
        $this->assertNull(Cron::nextRun($expr, $this->from()), "expected null for: $expr");
    }

    public static function malformedExpressions(): array
    {
        return [
            'empty'             => [''],
            'too few fields'    => ['0 3 * *'],
            'too many fields'   => ['0 3 * * * *'],
            'minute out range'  => ['60 3 * * *'],
            'hour out range'    => ['0 24 * * *'],
            'dom zero'          => ['0 0 0 * *'],
            'month out range'   => ['0 0 1 13 *'],
            'dow out range'     => ['0 0 * * 8'],
            'bad token'         => ['0 0 * * funday'],
            'zero step'         => ['*/0 * * * *'],
            'reversed range'    => ['0 17-9 * * *'],
            'garbage'           => ['not a cron at all'],
            'empty list elem'   => ['0,,30 * * * *'],
        ];
    }
}

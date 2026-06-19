<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Drift-guard for the 5-field cron grammar across its two ENTRY POINTS:
 *
 *   1. Job::isValidCron - structural validation run when a job is saved
 *      (rejects clearly-malformed schedules).
 *   2. Cron::parseExpression / Cron::nextRun - the pure next-run calculator
 *      used to display "Next run" and (conceptually) to reason about when a
 *      schedule fires.
 *
 * As of CQ-04 these share ONE parser: Job::isValidCron now delegates to
 * Cron::isValidExpression (i.e. parseExpression($expr) !== null), so by construction they
 * cannot disagree. This test predates that unification and is kept as a
 * belt-and-suspenders guard: should a future refactor ever re-split the grammar,
 * it still asserts the two entry points AGREE on a representative corpus:
 *
 *   - For every VALID expression: Job::isValidCron === true AND
 *     Cron::nextRun(expr, fromTs) returns a non-null, strictly-future ts.
 *   - For every INVALID expression: Job::isValidCron === false AND
 *     Cron::nextRun(expr, fromTs) returns null.
 *
 * Timestamps are FIXED (passed in), so there is no time() / "now"
 * nondeterminism in the assertions.
 *
 * If a real disagreement is ever discovered, do NOT fix the parser here - mark
 * the offending case incomplete and report it for separate triage. (None is
 * known at the time of writing.)
 */
final class CronConsistencyTest extends TestCase
{
    /**
     * A fixed reference instant. 2026-06-14 12:00:00 UTC. Chosen so every
     * expression in the valid corpus has a well-defined strictly-future next
     * run regardless of the day-of-week/day-of-month rules. The exact value
     * does not matter for the agreement property; it just must be deterministic.
     */
    private function from(): int
    {
        return gmmktime(12, 0, 0, 6, 14, 2026); // 2026-06-14 12:00:00 UTC
    }

    /**
     * Valid expressions that BOTH parsers must accept. Covers: minute/hour
     * specifics, "*", step values ("*\/n"), lists, ranges, range-steps, named
     * months (jan..dec) and named weekdays (sun..sat), and the 0/7 == Sunday
     * day-of-week aliasing. These are exactly the syntactic features the two
     * implementations both document supporting.
     *
     * @return array<int,array{0:string}>
     */
    public static function validExpressions(): array
    {
        $exprs = [
            '0 3 * * *',        // daily at 03:00 (task example)
            '*/15 * * * *',     // every 15 minutes (task example)
            '0 0 1 * *',        // 1st of the month at midnight (task example)
            '30 2 * * 1-5',     // 02:30 on weekdays Mon-Fri (task example)
            '0 */6 * * *',      // every 6 hours (task example)
            '0 0 * * 0',        // weekly, Sunday midnight (dow 0)
            '0 0 * * 7',        // weekly, Sunday midnight (dow 7 alias)
            '0,30 * * * *',     // top + half of every hour (list)
            '0 9-17 * * *',     // hourly during business hours (range)
            '0 0-12/6 * * *',   // range-step on hours
            '0 0 1 jan *',      // named month: January 1st
            '0 0 1 jan-mar *',  // named-month range
            '0 0 * * mon',      // named weekday
            '0 0 * * mon-fri',  // named-weekday range
            '0 0 1,15 * *',     // 1st and 15th
            '59 23 31 12 *',    // last minute of the year
            '* * * * *',        // every minute
            '5 4 * * sun',      // named Sunday
        ];
        return array_map(static fn(string $e): array => [$e], $exprs);
    }

    /**
     * Clearly-invalid expressions that BOTH parsers must reject. Covers: wrong
     * field count, out-of-range values per field, malformed steps/ranges, and
     * pure garbage.
     *
     * @return array<int,array{0:string}>
     */
    public static function invalidExpressions(): array
    {
        $exprs = [
            '',                 // empty
            '0 3 * *',          // 4 fields (too few)
            '0 3 * * * *',      // 6 fields (too many)
            '60 * * * *',       // minute out of range (0-59)
            '* 24 * * *',       // hour out of range (0-23)
            '* * 32 * *',       // day-of-month out of range (1-31)
            '* * 0 * *',        // day-of-month out of range (min 1)
            '* * * 13 *',       // month out of range (1-12)
            '* * * 0 *',        // month out of range (min 1)
            '* * * * 8',        // day-of-week out of range (0-7)
            '*/0 * * * *',      // zero step
            '5-3 * * * *',      // inverted range (lo > hi)
            '0 0 * * xyz',      // bogus weekday name
            '0 0 * foo *',      // bogus month name
            'not a cron',       // garbage tokens, wrong count
            '@daily',           // nickname not supported (single field)
            'a b c d e',        // 5 non-numeric garbage fields
        ];
        return array_map(static fn(string $e): array => [$e], $exprs);
    }

    #[DataProvider('validExpressions')]
    public function testValidExpressionsAcceptedByBothParsers(string $expr): void
    {
        $from = $this->from();

        $this->assertTrue(
            Job::isValidCron($expr),
            "Job::isValidCron should ACCEPT valid expression: '$expr'"
        );

        $next = Cron::nextRun($expr, $from);
        $this->assertNotNull(
            $next,
            "Cron::nextRun should return a non-null next run for valid expression: '$expr'"
        );
        $this->assertGreaterThan(
            $from,
            $next,
            "Cron::nextRun should return a STRICTLY-FUTURE timestamp for valid expression: '$expr'"
        );
    }

    #[DataProvider('invalidExpressions')]
    public function testInvalidExpressionsRejectedByBothParsers(string $expr): void
    {
        $this->assertFalse(
            Job::isValidCron($expr),
            "Job::isValidCron should REJECT invalid expression: '$expr'"
        );

        $this->assertNull(
            Cron::nextRun($expr, $this->from()),
            "Cron::nextRun should return null for invalid expression: '$expr'"
        );
    }
}

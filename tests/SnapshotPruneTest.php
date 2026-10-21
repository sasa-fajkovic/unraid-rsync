<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Snapshot::planPrune / pruneSanity: every §6 safety rule of
 * docs/design/snapshot-mode.md. Listings go through Snapshot::parseHelper so
 * the planner sees exactly the shape the helper's `list` produces.
 */
final class SnapshotPruneTest extends TestCase
{
    /**
     * A parsed `list` reply. $dirs: complete or suffixed directory names, a
     * value of true pins it (.ur-keep).
     *
     * @param array<string,bool> $dirs
     * @param array<int,string>  $extra more records (non-directory entries, stampish, …)
     * @return array<string,mixed>
     */
    private static function listing(array $dirs, string $latest = '', bool $hold = false, array $extra = []): array
    {
        $records = [];
        foreach ($dirs as $name => $pinned) {
            $records[] = "entry $name d " . ($pinned ? '1' : '0') . ' 1';
        }
        $records   = array_merge($records, $extra);
        $records[] = 'latest ' . ($latest === '' ? 'none' : $latest);
        $records[] = 'hold ' . ($hold ? '1' : '0');
        $records[] = 'foreign 0';
        $records[] = 'df 1000 100 900';
        $r = Snapshot::parseHelper("ur-snapshot 1\n" . implode("\n", $records) . "\nok\n", 'list');
        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return $r;
    }

    /** @return array<string,mixed> */
    private static function policy(int $daily, int $weekly, int $monthly, int $cap = 20, bool $dryRun = false): array
    {
        return (array) Config::normalizeSnapshot([
            'enabled'        => true,
            'root'           => '/mnt/disk1/snapshots/app',
            'keepDaily'      => $daily,
            'keepWeekly'     => $weekly,
            'keepMonthly'    => $monthly,
            'maxPrunePerRun' => $cap,
            'pruneDryRun'    => $dryRun,
        ]);
    }

    /**
     * $count stamps $stepDays apart at 03:00 UTC, the newest on $newest.
     *
     * @return array<int,string> oldest first
     */
    private static function series(string $newest, int $count, int $stepDays = 1): array
    {
        $t   = new DateTimeImmutable($newest . ' 03:00:00', new DateTimeZone('UTC'));
        $out = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $out[] = Snapshot::stamp($t->modify('-' . ($i * $stepDays) . ' days'));
        }
        return $out;
    }

    /**
     * @param array<int,string> $names
     * @return array<string,bool>
     */
    private static function dirs(array $names): array
    {
        return array_fill_keys($names, false);
    }

    private static function now(string $stamp): DateTimeImmutable
    {
        return new DateTimeImmutable(preg_replace('/^(\d{4}-\d\d-\d\d)T(\d\d)(\d\d)(\d\d)Z$/', '$1T$2:$3:$4Z', $stamp));
    }

    /**
     * Run prune passes, applying each batch, until nothing is left to delete.
     *
     * @param array<string,bool> $dirs
     * @param array<string,mixed> $policy
     * @return array{0:array<string,bool>,1:int} the remaining dirs and the number of passes that deleted something
     */
    private function converge(array $dirs, array $policy, string $current, string $latest = '', array $protected = []): array
    {
        $runs = 0;
        for ($i = 0; $i < 200; $i++) {
            $plan = Snapshot::planPrune(self::listing($dirs, $latest), $policy, self::now($current), $current);
            $this->assertNull($plan['abort']);
            if ($plan['delete'] === []) {
                $this->assertSame(0, $plan['backlog']);
                return [$dirs, $runs];
            }
            $this->assertLessThanOrEqual($policy['maxPrunePerRun'], count($plan['delete']));
            foreach ($plan['delete'] as $name) {
                $this->assertNotContains($name, $protected, "$name is protected");
                $this->assertArrayHasKey($name, $dirs);
                unset($dirs[$name]);
            }
            $runs++;
        }
        $this->fail('prune did not converge');
    }

    public function testSevenDailyFourWeeklyOver108DailySnapshots(): void
    {
        // 2026-07-06 .. 2026-10-21 (a Wednesday). Daily: Oct 15-21. Weekly
        // (ISO weeks): Oct 21, 18, 11, 4. The floor of 7+4=11 then keeps the
        // two newest of the rest, Oct 13 and 14.
        $names = self::series('2026-10-21', 108);
        $this->assertSame('2026-07-06T030000Z', $names[0]);
        $current = end($names);

        $plan = Snapshot::planPrune(self::listing(self::dirs($names)), self::policy(7, 4, 0, 3), self::now($current), $current);
        $this->assertSame(['2026-07-06T030000Z', '2026-07-07T030000Z', '2026-07-08T030000Z'], $plan['delete']);
        $this->assertSame(94, $plan['backlog']);
        $this->assertSame(['daily', 'weekly', 'newest', 'current'], $plan['keep']['2026-10-21T030000Z']);
        $this->assertSame(['daily', 'weekly'], $plan['keep']['2026-10-18T030000Z']);
        $this->assertSame(['weekly'], $plan['keep']['2026-10-04T030000Z']);
        $this->assertSame(['floor'], $plan['keep']['2026-10-13T030000Z']);

        [$left, $runs] = $this->converge(self::dirs($names), self::policy(7, 4, 0, 3), $current);
        $this->assertSame([
            '2026-10-04T030000Z', '2026-10-11T030000Z', '2026-10-13T030000Z', '2026-10-14T030000Z',
            '2026-10-15T030000Z', '2026-10-16T030000Z', '2026-10-17T030000Z', '2026-10-18T030000Z',
            '2026-10-19T030000Z', '2026-10-20T030000Z', '2026-10-21T030000Z',
        ], array_keys($left));
        $this->assertSame(33, $runs, '97 deletions at 3 per run');
    }

    public function testSevenDailyFourWeeklyThreeMonthlyOver108DailySnapshots(): void
    {
        // Monthly adds Sep 30 and Aug 31 (Oct 21 is already kept); the floor of
        // 14 adds Oct 12, 13 and 14.
        $names   = self::series('2026-10-21', 108);
        $current = end($names);
        [$left, $runs] = $this->converge(self::dirs($names), self::policy(7, 4, 3, 3), $current);
        $this->assertSame([
            '2026-08-31T030000Z', '2026-09-30T030000Z', '2026-10-04T030000Z', '2026-10-11T030000Z',
            '2026-10-12T030000Z', '2026-10-13T030000Z', '2026-10-14T030000Z', '2026-10-15T030000Z',
            '2026-10-16T030000Z', '2026-10-17T030000Z', '2026-10-18T030000Z', '2026-10-19T030000Z',
            '2026-10-20T030000Z', '2026-10-21T030000Z',
        ], array_keys($left));
        $this->assertSame(32, $runs, '94 deletions at 3 per run');
    }

    public function testWeeklyOnlyHistory(): void
    {
        // 30 Sundays, 2026-03-29 .. 2026-10-18. Weekly: Oct 18, 11. Monthly: the
        // last Sunday of Oct..May. The floor of 0+2+6=8 adds Oct 4.
        $names   = self::series('2026-10-18', 30, 7);
        $current = end($names);
        $this->assertSame('2026-03-29T030000Z', $names[0]);
        [$left] = $this->converge(self::dirs($names), self::policy(0, 2, 6, 3), $current);
        $this->assertSame([
            '2026-05-31T030000Z', '2026-06-28T030000Z', '2026-07-26T030000Z', '2026-08-30T030000Z',
            '2026-09-27T030000Z', '2026-10-04T030000Z', '2026-10-11T030000Z', '2026-10-18T030000Z',
        ], array_keys($left));
    }

    public function testAGapDoesNotUseUpTheDailyQuota(): void
    {
        $names = ['2026-09-01T030000Z', '2026-09-02T030000Z', '2026-10-01T030000Z', '2026-10-20T030000Z', '2026-10-21T030000Z'];
        $plan  = Snapshot::planPrune(self::listing(self::dirs($names)), self::policy(3, 0, 0), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertSame(['2026-09-01T030000Z', '2026-09-02T030000Z'], $plan['delete']);
        $this->assertSame(['daily'], $plan['keep']['2026-10-01T030000Z']);
    }

    public function testOnlyTheNewestOfADayIsKeptForThatDay(): void
    {
        $names = ['2026-10-20T010000Z', '2026-10-20T020000Z', '2026-10-20T230000Z', '2026-10-21T010000Z', '2026-10-21T030000Z'];
        $plan  = Snapshot::planPrune(self::listing(self::dirs($names)), self::policy(2, 0, 0, 2), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertSame(['2026-10-20T010000Z', '2026-10-20T020000Z'], $plan['delete']);
        $this->assertSame(1, $plan['backlog'], '2026-10-21T010000Z waits for the next run');
        $this->assertArrayNotHasKey('2026-10-21T010000Z', $plan['keep']);
        $this->assertSame(['daily'], $plan['keep']['2026-10-20T230000Z']);
    }

    public function testSelectionIsNeverByPosition(): void
    {
        // The old `ls | sort | head -n -N` keeps the last N names in sort order
        // and deletes the rest - here that includes a mirror that happens to
        // sort first and a stale .inprogress.
        $names = ['0-old-mirror', '2026-10-19T030000Z', '2026-10-19T040000Z.inprogress', '2026-10-20T030000Z', '2026-10-21T030000Z'];
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $byPosition = array_slice($sorted, 0, -2);
        $this->assertContains('0-old-mirror', $byPosition);
        $this->assertContains('2026-10-19T040000Z.inprogress', $byPosition);

        $plan = Snapshot::planPrune(
            self::listing(self::dirs(array_slice($names, 1))),
            self::policy(2, 0, 0),
            self::now('2026-10-21T030000Z'),
            '2026-10-21T030000Z'
        );
        $this->assertSame(['2026-10-19T030000Z'], $plan['delete']);
        $this->assertNotContains('2026-10-19T040000Z.inprogress', array_keys($plan['keep']));
    }

    public function testDecoysAreNeverCandidates(): void
    {
        $dirs = self::dirs([
            '2026-10-01T030000Z.inprogress', '2026-10-02T030000Z.rolledback',
            '2026-10-10T030000Z', '2026-10-11T030000Z', '2026-10-20T030000Z', '2026-10-21T030000Z',
        ]);
        $extra = [
            'entry 2026-10-03T030000Z f 0 0',  // a file with a stamp name
            'entry 2026-10-04T030000Z l 0 0',  // a symlink with a stamp name
            'entry 2026-10-05T030000Z o 0 0',
            'stampish 2026-10-06T030000Z',     // 2026-10-06T030000Z.partial
        ];
        $plan = Snapshot::planPrune(self::listing($dirs, '', false, $extra), self::policy(2, 0, 0), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertNull($plan['abort']);
        $this->assertSame(['2026-10-10T030000Z', '2026-10-11T030000Z'], $plan['delete']);
        $this->assertSame(['2026-10-20T030000Z', '2026-10-21T030000Z'], array_keys($plan['keep']));
    }

    public function testAnImpossibleDateDirectoryAbortsThePrune(): void
    {
        $dirs = self::dirs(['2026-02-30T030000Z', '2026-10-19T030000Z', '2026-10-20T030000Z', '2026-10-21T030000Z']);
        $plan = Snapshot::planPrune(self::listing($dirs), self::policy(1, 0, 0), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertStringContainsString('2026-02-30T030000Z', (string) $plan['abort']);
        $this->assertSame([], $plan['delete']);
    }

    public function testTheNewestCompleteSnapshotIsKeptEvenWhenTheCurrentOneIsOlder(): void
    {
        $names = ['2026-10-19T030000Z', '2026-10-20T030000Z', '2026-10-21T030000Z', '2026-10-21T120000Z'];
        $plan  = Snapshot::planPrune(self::listing(self::dirs($names)), self::policy(1, 0, 0), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertSame(['daily', 'newest'], $plan['keep']['2026-10-21T120000Z']);
        $this->assertSame(['current'], $plan['keep']['2026-10-21T030000Z']);
        $this->assertSame(['2026-10-19T030000Z', '2026-10-20T030000Z'], $plan['delete']);
    }

    public function testTheLatestTargetIsKept(): void
    {
        $names = self::series('2026-10-21', 12);
        $plan  = Snapshot::planPrune(self::listing(self::dirs($names), '2026-10-16T030000Z'), self::policy(1, 0, 0, 2), self::now(end($names)), end($names));
        $this->assertSame(['latest'], $plan['keep']['2026-10-16T030000Z']);
        $this->assertNotContains('2026-10-16T030000Z', $plan['delete']);
    }

    public function testPinnedSnapshotsAreKeptAndNeverTouchedWhileConverging(): void
    {
        // 15 daily snapshots, keep 3, cap 3, one old pin: 11 deletions in 4 runs.
        $names = self::series('2026-10-21', 15);
        $dirs  = self::dirs($names);
        $dirs['2026-10-08T030000Z'] = true;
        $current = end($names);

        $first = Snapshot::planPrune(self::listing($dirs), self::policy(3, 0, 0, 3), self::now($current), $current);
        $this->assertSame(['pinned'], $first['keep']['2026-10-08T030000Z']);
        $this->assertSame(8, $first['backlog']);

        [$left, $runs] = $this->converge($dirs, self::policy(3, 0, 0, 3), $current, '', ['2026-10-08T030000Z', '2026-10-19T030000Z', '2026-10-20T030000Z', '2026-10-21T030000Z']);
        $this->assertSame(4, $runs);
        $this->assertSame(['2026-10-08T030000Z', '2026-10-19T030000Z', '2026-10-20T030000Z', '2026-10-21T030000Z'], array_keys($left));
    }

    public function testASingleSnapshotIsNeverDeleted(): void
    {
        $plan = Snapshot::planPrune(self::listing(self::dirs(['2026-10-21T030000Z'])), self::policy(1, 0, 0), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertSame([], $plan['delete']);
        $this->assertNull($plan['abort']);
    }

    public function testTheFloorDropsTheNewestOfTheBatch(): void
    {
        // Five snapshots on one day: every bucket keeps the same newest one,
        // but 1+1+1 = 3 must remain.
        $names = ['2026-10-21T000000Z', '2026-10-21T010000Z', '2026-10-21T020000Z', '2026-10-21T025000Z', '2026-10-21T030000Z'];
        $plan  = Snapshot::planPrune(self::listing(self::dirs($names)), self::policy(1, 1, 1), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertSame(['2026-10-21T000000Z', '2026-10-21T010000Z'], $plan['delete']);
        $this->assertSame(['floor'], $plan['keep']['2026-10-21T020000Z']);
        $this->assertSame(['floor'], $plan['keep']['2026-10-21T025000Z']);
        $this->assertSame(['daily', 'weekly', 'monthly', 'newest', 'current'], $plan['keep']['2026-10-21T030000Z']);
    }

    public function testABatchOfMoreThanHalfAborts(): void
    {
        $names = ['2026-10-21T010000Z', '2026-10-21T020000Z', '2026-10-21T030000Z'];
        $plan  = Snapshot::planPrune(self::listing(self::dirs($names)), self::policy(1, 0, 0), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertStringContainsString('more than half', (string) $plan['abort']);
        $this->assertSame([], $plan['delete']);
        $this->assertSame(0, $plan['backlog']);
    }

    public function testExactlyHalfIsAllowed(): void
    {
        $names = ['2026-10-18T030000Z', '2026-10-19T030000Z', '2026-10-20T030000Z', '2026-10-21T030000Z'];
        $plan  = Snapshot::planPrune(self::listing(self::dirs($names)), self::policy(2, 0, 0), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertNull($plan['abort']);
        $this->assertSame(['2026-10-18T030000Z', '2026-10-19T030000Z'], $plan['delete']);
    }

    public function testLeftoverDeletingDirectoriesGoFirstAndCountTowardTheCap(): void
    {
        $names = self::series('2026-10-21', 10);
        $dirs  = self::dirs($names) + ['2026-09-01T030000Z.deleting' => false, '2026-08-01T030000Z.deleting' => false];
        $plan  = Snapshot::planPrune(
            self::listing($dirs, '', false, ['entry 2026-07-01T030000Z.deleting f 0 0']),
            self::policy(5, 0, 0, 3),
            self::now(end($names)),
            end($names)
        );
        $this->assertSame(['2026-08-01T030000Z.deleting', '2026-09-01T030000Z.deleting', '2026-10-12T030000Z'], $plan['delete']);
        $this->assertSame(4, $plan['backlog']);
    }

    public function testDryRunPlanEqualsTheRealPlan(): void
    {
        $names   = self::series('2026-10-21', 40);
        $listing = self::listing(self::dirs($names), $names[30]);
        $now     = self::now(end($names));
        $this->assertSame(
            Snapshot::planPrune($listing, self::policy(7, 4, 0, 5, false), $now, end($names)),
            Snapshot::planPrune($listing, self::policy(7, 4, 0, 5, true), $now, end($names))
        );
    }

    public function testASecondPassDeletesNothing(): void
    {
        $names = self::series('2026-10-21', 12);
        $dirs  = self::dirs($names);
        $now   = self::now(end($names));
        $plan  = Snapshot::planPrune(self::listing($dirs), self::policy(7, 0, 0), $now, end($names));
        $this->assertCount(5, $plan['delete']);
        foreach ($plan['delete'] as $name) {
            unset($dirs[$name]);
        }
        $again = Snapshot::planPrune(self::listing($dirs), self::policy(7, 0, 0), $now, end($names));
        $this->assertSame([], $again['delete']);
        $this->assertSame(0, $again['backlog']);
        $this->assertSame(array_keys($plan['keep']), array_keys($again['keep']));
    }

    public function testFutureNamesAreNeitherKeptNorDeleted(): void
    {
        $names = ['2026-10-19T030000Z', '2026-10-20T030000Z', '2026-10-21T030000Z', '2026-10-23T000000Z'];
        $plan  = Snapshot::planPrune(self::listing(self::dirs($names)), self::policy(1, 0, 0), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertSame(['2026-10-23T000000Z'], $plan['future']);
        $this->assertArrayNotHasKey('2026-10-23T000000Z', $plan['keep']);
        $this->assertNotContains('2026-10-23T000000Z', $plan['delete']);
        $this->assertSame(['daily', 'newest', 'current'], $plan['keep']['2026-10-21T030000Z']);
    }

    public function testAHoldDeletesNothingButStillPlansTheKeepSet(): void
    {
        $names = self::series('2026-10-21', 10);
        $plan  = Snapshot::planPrune(self::listing(self::dirs($names) + ['2026-09-01T030000Z.deleting' => false], '', true), self::policy(2, 0, 0), self::now(end($names)), end($names));
        $this->assertTrue($plan['held']);
        $this->assertSame([], $plan['delete']);
        $this->assertSame(0, $plan['backlog']);
        $this->assertSame(['2026-10-20T030000Z', '2026-10-21T030000Z'], array_keys($plan['keep']));
    }

    public function testAListingWithoutTheCurrentStampAborts(): void
    {
        $names = self::series('2026-10-20', 10);
        $plan  = Snapshot::planPrune(self::listing(self::dirs($names)), self::policy(2, 0, 0), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z');
        $this->assertStringContainsString('does not contain the new snapshot', (string) $plan['abort']);
        $this->assertSame([], $plan['delete']);

        $inprogress = self::listing(self::dirs($names) + ['2026-10-21T030000Z.inprogress' => false]);
        $this->assertNotNull(Snapshot::planPrune($inprogress, self::policy(2, 0, 0), self::now('2026-10-21T030000Z'), '2026-10-21T030000Z')['abort']);
    }

    public function testAnInvalidPolicyAborts(): void
    {
        $listing = self::listing(self::dirs(self::series('2026-10-21', 5)));
        $now     = self::now('2026-10-21T030000Z');
        foreach ([['keepDaily', -1], ['keepWeekly', 261], ['maxPrunePerRun', 0], ['keepMonthly', '3']] as [$key, $value]) {
            $policy       = self::policy(1, 0, 0);
            $policy[$key] = $value;
            $this->assertStringContainsString($key, (string) Snapshot::planPrune($listing, $policy, $now, '2026-10-21T030000Z')['abort']);
        }
        $this->assertStringContainsString('keep no snapshot', (string) Snapshot::planPrune($listing, self::policy(0, 0, 0), $now, '2026-10-21T030000Z')['abort']);
    }

    // --- pruneSanity, checked independently of the planner ----------------------

    public function testSanityRefusesProtectedNamesInATamperedBatch(): void
    {
        $names   = self::series('2026-10-21', 10);
        $dirs    = self::dirs($names);
        $dirs['2026-10-14T030000Z'] = true;
        $listing = self::listing($dirs, '2026-10-15T030000Z');
        $current = '2026-10-20T030000Z';

        $this->assertNull(Snapshot::pruneSanity(['2026-10-12T030000Z'], $listing, $current));
        $this->assertStringContainsString('newest complete', (string) Snapshot::pruneSanity(['2026-10-21T030000Z'], $listing, $current));
        $this->assertStringContainsString('target of latest', (string) Snapshot::pruneSanity(['2026-10-15T030000Z'], $listing, $current));
        $this->assertStringContainsString('new snapshot', (string) Snapshot::pruneSanity([$current], $listing, $current));
        $this->assertStringContainsString('pinned', (string) Snapshot::pruneSanity(['2026-10-14T030000Z'], $listing, $current));
        $six = ['2026-10-12T030000Z', '2026-10-13T030000Z', '2026-10-16T030000Z', '2026-10-17T030000Z', '2026-10-18T030000Z', '2026-10-19T030000Z'];
        $this->assertStringContainsString('more than half', (string) Snapshot::pruneSanity($six, $listing, $current));
        $this->assertNull(Snapshot::pruneSanity(array_slice($six, 0, 5), $listing, $current));
    }

    public function testSanityRefusesAnythingThatIsNotAListedSnapshotDirectory(): void
    {
        $listing = self::listing(
            self::dirs(['2026-10-12T030000Z.inprogress', '2026-10-13T030000Z.rolledback', '2026-10-20T030000Z', '2026-10-21T030000Z']),
            '',
            false,
            ['entry 2026-10-14T030000Z l 0 0']
        );
        foreach (['2026-10-12T030000Z.inprogress', '2026-10-13T030000Z.rolledback', '2026-10-14T030000Z', '2026-10-01T030000Z', 'appdata', '../etc'] as $name) {
            $this->assertStringContainsString('not a listed snapshot directory', (string) Snapshot::pruneSanity([$name], $listing, '2026-10-21T030000Z'), $name);
        }
    }
}

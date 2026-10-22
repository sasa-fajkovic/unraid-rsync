<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure parts of Snapshot.php: stamps, name classification, the
 * monotonic / future / skew rules, rsync stats parsing, the helper protocol
 * parser, run options, helper-file verification, the remote command string
 * (executed through a real `sh -c`) and the runRemote exec seam. The prune
 * planner has its own SnapshotPruneTest; the helper script SnapshotHelperTest.
 */
final class SnapshotTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ur-snap-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
    }

    protected function tearDown(): void
    {
        TestSeams::reset();
        exec('chmod -R u+rwx ' . escapeshellarg($this->tmp) . ' && rm -rf ' . escapeshellarg($this->tmp));
    }

    // --- stamps ---------------------------------------------------------------

    public function testStampIsUtcInTheSpecFormat(): void
    {
        $t = new DateTimeImmutable('2026-10-21 05:04:03', new DateTimeZone('Europe/Berlin'));
        $this->assertSame('2026-10-21T030403Z', Snapshot::stamp($t));
        $this->assertTrue(Snapshot::isStamp(Snapshot::stamp($t)));
    }

    public function testNowUsesTheProviderAsADateOrAnEpoch(): void
    {
        Snapshot::$nowProvider = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('America/New_York'));
        $this->assertSame('2026-01-02T080405Z', Snapshot::stamp(Snapshot::now()));
        $this->assertSame('UTC', Snapshot::now()->getTimezone()->getName());

        Snapshot::$nowProvider = static fn(): int => 1792540800;
        $this->assertSame('2026-10-21T000000Z', Snapshot::stamp(Snapshot::now()));
    }

    public function testNowDefaultsToTheSystemClock(): void
    {
        $this->assertEqualsWithDelta(time(), Snapshot::now()->getTimestamp(), 5);
    }

    /** @return array<string,array{0:string}> */
    public static function invalidStamps(): array
    {
        return [
            'impossible date'   => ['2026-02-30T120000Z'],
            'hour 25'           => ['2026-10-21T250000Z'],
            'minute 60'         => ['2026-10-21T126000Z'],
            'second 60'         => ['2026-10-21T120060Z'],
            'month 13'          => ['2026-13-01T120000Z'],
            'too short'         => ['2026-10-21T12000Z'],
            'too long'          => ['2026-10-21T1200000Z'],
            'leading space'     => [' 2026-10-21T120000Z'],
            'trailing space'    => ['2026-10-21T120000Z '],
            'tilde suffix'      => ['2026-10-21T120000Z~'],
            'trailing newline'  => ["2026-10-21T120000Z\n"],
            'embedded newline'  => ["2026-10-21\nT120000Z"],
            'lowercase z'       => ['2026-10-21T120000z'],
            'colons'            => ['2026-10-21T12:00:00Z'],
            'unicode digit'     => ["2026-10-2\u{0661}T120000Z"],
            'suffixed'          => ['2026-10-21T120000Z.inprogress'],
            'empty'             => [''],
        ];
    }

    #[DataProvider('invalidStamps')]
    public function testIsStampRejects(string $s): void
    {
        $this->assertFalse(Snapshot::isStamp($s));
    }

    public function testIsStampAcceptsRealTimesIncludingALeapDay(): void
    {
        $this->assertTrue(Snapshot::isStamp('2028-02-29T235959Z'));
        $this->assertTrue(Snapshot::isStamp('2026-01-01T000000Z'));
        $this->assertFalse(Snapshot::isStamp('2026-02-29T000000Z'));
    }

    // --- classification -------------------------------------------------------

    /** @return array<string,array{0:string,1:string}> */
    public static function names(): array
    {
        return [
            'complete'           => ['2026-10-21T030000Z', 'complete'],
            'inprogress'         => ['2026-10-21T030000Z.inprogress', 'inprogress'],
            'deleting'           => ['2026-10-21T030000Z.deleting', 'deleting'],
            'rolledback'         => ['2026-10-21T030000Z.rolledback', 'rolledback'],
            'partial'            => ['2026-10-21T030000Z.partial', 'foreign'],
            'double suffix'      => ['2026-10-21T030000Z.inprogress.deleting', 'foreign'],
            'suffix with space'  => ['2026-10-21T030000Z.inprogress ', 'foreign'],
            'impossible date'    => ['2026-02-30T030000Z.inprogress', 'foreign'],
            'bare suffix'        => ['.inprogress', 'foreign'],
            'mirror'             => ['appdata', 'foreign'],
            'lost+found'         => ['lost+found', 'foreign'],
            'snapshots'          => ['snapshots', 'foreign'],
            'latest'             => ['latest', 'foreign'],
            'dot'                => ['.', 'foreign'],
            'dotdot'             => ['..', 'foreign'],
            'traversal'          => ['2026-10-21T030000Z/../x', 'foreign'],
        ];
    }

    #[DataProvider('names')]
    public function testClassify(string $name, string $kind): void
    {
        $this->assertSame($kind, Snapshot::classify($name));
    }

    // --- monotonic, future, skew ------------------------------------------------

    public function testMonotonicPassesWhenTheNewStampSortsAfterEverything(): void
    {
        $this->assertNull(Snapshot::checkMonotonic('2026-10-21T030000Z', []));
        $this->assertNull(Snapshot::checkMonotonic('2026-10-21T030000Z', [
            '2026-10-20T030000Z', '2026-10-20T040000Z.inprogress', 'appdata', 'zzz', 'latest',
        ]));
    }

    /** @return array<string,array{0:string}> */
    public static function laterNames(): array
    {
        return [
            'equal complete'    => ['2026-10-21T030000Z'],
            'later complete'    => ['2026-10-21T030001Z'],
            'later inprogress'  => ['2026-10-22T000000Z.inprogress'],
            'later deleting'    => ['2026-10-22T000000Z.deleting'],
            'later rolledback'  => ['2026-10-22T000000Z.rolledback'],
            'later, any suffix' => ['2026-10-22T000000Z.partial'],
            'impossible future' => ['9999-99-99T999999Z'],
        ];
    }

    #[DataProvider('laterNames')]
    public function testMonotonicRefusesANameThatDoesNotSortBefore(string $name): void
    {
        $why = Snapshot::checkMonotonic('2026-10-21T030000Z', ['2026-10-01T030000Z', $name]);
        $this->assertNotNull($why);
        $this->assertStringContainsString('clock', (string) $why);
    }

    public function testMonotonicRefusesAnInvalidNewStamp(): void
    {
        $this->assertStringContainsString('not a valid', (string) Snapshot::checkMonotonic('2026-02-30T030000Z', []));
    }

    public function testFutureNamesAreThoseMoreThanADayAhead(): void
    {
        $now = new DateTimeImmutable('2026-10-21T03:00:00Z');
        $names = [
            '2026-10-21T030000Z',
            '2026-10-22T030000Z',             // exactly one day: not future
            '2026-10-22T030001Z',             // one second more
            '2027-01-01T000000Z.inprogress',
            '2030-01-01T000000Z.partial',
            'appdata',
        ];
        $this->assertSame(
            ['2026-10-22T030001Z', '2027-01-01T000000Z.inprogress', '2030-01-01T000000Z.partial'],
            Snapshot::futureNames($names, $now)
        );
    }

    public function testSkewWithinTheLimitPasses(): void
    {
        $this->assertNull(Snapshot::checkSkew(1000, 1000));
        $this->assertNull(Snapshot::checkSkew(1120, 1000));
        $this->assertNull(Snapshot::checkSkew(880, 1000));
    }

    public function testSkewBeyondTheLimitFailsInEitherDirection(): void
    {
        $this->assertStringContainsString('121 s ahead', (string) Snapshot::checkSkew(1121, 1000));
        $this->assertStringContainsString('300 s behind', (string) Snapshot::checkSkew(700, 1000));
    }

    // --- free space, anomalies ----------------------------------------------------

    public function testFreeSpaceNeedsTheLargerOfFivePercentAndTenGiB(): void
    {
        $this->assertNull(Snapshot::freeSpaceError(['size' => 100000000, 'used' => 0, 'avail' => 10485760]));
        $this->assertNotNull(Snapshot::freeSpaceError(['size' => 100000000, 'used' => 0, 'avail' => 10485759]));
        $this->assertNull(Snapshot::freeSpaceError(['size' => 1000000000, 'used' => 0, 'avail' => 50000000]));
        $this->assertStringContainsString('needs at least 50000000 KB', (string) Snapshot::freeSpaceError(['size' => 1000000000, 'used' => 0, 'avail' => 49999999]));
    }

    /** @return array<string,mixed> */
    private static function pair(string $sub, ?int $files, ?int $bytes, bool $basisMissing = false): array
    {
        return ['sub' => $sub, 'files' => $files, 'bytes' => $bytes, 'basisMissing' => $basisMissing];
    }

    public function testAnomaliesFlagHalvedFilesAndBytesAboveTheMinimums(): void
    {
        $meta = ['subs' => [
            'a' => ['files' => 100, 'bytes' => 1073741824],
            'b' => ['files' => 99, 'bytes' => 1073741823],
        ]];
        $r = Snapshot::anomalies([self::pair('a', 49, 536870911), self::pair('b', 1, 1)], $meta, null, []);
        $this->assertSame(['a: files dropped from 100 to 49', 'a: bytes dropped from 1073741824 to 536870911'], $r['suspect']);

        $r = Snapshot::anomalies([self::pair('a', 50, 536870912)], $meta, null, []);
        $this->assertSame([], $r['suspect'], 'exactly half is not suspect');

        $r = Snapshot::anomalies([self::pair('a', null, 2000000000), self::pair('new', 1, 1)], $meta, null, []);
        $this->assertSame([], $r['suspect']);
        $this->assertContains("a: the files count could not be read from rsync's statistics", $r['skipped']);
        $this->assertContains('new: no baseline in the previous snapshot', $r['skipped']);
    }

    public function testAnomaliesWithoutABaselineSkipTheCountsButNotTheMissingBasis(): void
    {
        $r = Snapshot::anomalies([self::pair('a', 1, 1, true)], null, 999999999, [1, 1, 1]);
        $this->assertSame(["a: rsync reported that the previous snapshot's copy does not exist"], $r['suspect']);
        $this->assertSame(['no baseline (the previous snapshot has no metadata), so the file, size and growth checks were skipped'], $r['skipped']);
    }

    public function testAnomaliesGrowthUsesTheMedianOfTheLastSevenOnceThreeExist(): void
    {
        $meta = ['subs' => []];
        $min  = Snapshot::GROWTH_MIN_KB;
        $this->assertSame([], Snapshot::anomalies([], $meta, $min * 100, [1, 1])['suspect'], 'fewer than 3 values: skipped');
        $this->assertSame([], Snapshot::anomalies([], $meta, $min, [1, 1, 1])['suspect'], 'the 5 GiB floor');
        $this->assertNotSame([], Snapshot::anomalies([], $meta, $min + 1, [1, 1, 1])['suspect']);
        // median of [min, 2min, 3min, 4min] = 2.5min -> limit 7.5min
        $past = [$min, 2 * $min, 3 * $min, 4 * $min];
        $this->assertSame([], Snapshot::anomalies([], $meta, (int) (7.5 * $min), $past)['suspect']);
        $this->assertNotSame([], Snapshot::anomalies([], $meta, (int) (7.5 * $min) + 1, $past)['suspect']);
        // Only the newest seven count: an old huge value falls out of the window.
        $this->assertNotSame([], Snapshot::anomalies([], $meta, $min + 1, [1, 1, 1, 1, 1, 1, 1, 100 * $min, 100 * $min])['suspect']);
        $this->assertContains('growth was not measured', Snapshot::anomalies([], $meta, null, [1, 1, 1])['skipped']);
    }

    // --- rsync stats ------------------------------------------------------------

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/rsync-stats/' . $name);
    }

    /** @return array<string,array{0:string,1:?int,2:?int}> */
    public static function statsFixtures(): array
    {
        // Captured from real rsync 3.2.7 (Debian bookworm) and 3.5.0 (Debian
        // trixie): 1234 one-byte files plus one 1,234,567-byte file.
        $out = [];
        foreach (['3.2.7', '3.5.0'] as $v) {
            $out["$v plain"]          = ["rsync-$v-plain.txt", 1235, 1235801];
            $out["$v -h"]             = ["rsync-$v-h.txt", 1235, 1240000];
            $out["$v dot locale"]     = ["rsync-$v-dot-locale.txt", 1235, 1235801];
            $out["$v dot locale -h"]  = ["rsync-$v-dot-locale-h.txt", 1235, 1240000];
            $out["$v no regular file"] = ["rsync-$v-nreg.txt", 0, 0];
            $out["$v link-dest"]      = ["rsync-$v-linkdest-missing.txt", 1, 1234567];
        }
        return $out;
    }

    #[DataProvider('statsFixtures')]
    public function testParseStatsReadsRealRsyncOutput(string $file, ?int $files, ?int $size): void
    {
        $stats = Snapshot::parseStats(self::fixture($file));
        $this->assertSame(['regFiles' => $files, 'totalSize' => $size], array_intersect_key($stats, ['regFiles' => 1, 'totalSize' => 1]));
        $this->assertSame($files === null, $stats['regFilesText'] === null);
        $this->assertSame($size === null, $stats['totalSizeText'] === null);
    }

    public function testParseStatsTakesTheLastBlockAndHandlesBigUnits(): void
    {
        $out = "Number of files: 9 (reg: 9)\nTotal file size: 9 bytes\n"
            . "Number of files: 2.50M (reg: 2.40M, dir: 100.00K)\r\nTotal file size: 1.50T bytes\r\n";
        $this->assertSame(
            ['regFiles' => 2400000, 'totalSize' => 1500000000000, 'regFilesText' => '2.40M', 'totalSizeText' => '1.50T'],
            Snapshot::parseStats($out)
        );
    }

    public function testParseStatsWithoutStatsOrWithGarbageGivesNulls(): void
    {
        $none = ['regFiles' => null, 'totalSize' => null, 'regFilesText' => null, 'totalSizeText' => null];
        $this->assertSame($none, Snapshot::parseStats(''));
        $this->assertSame($none, Snapshot::parseStats("sent 10 bytes\n"));
        $this->assertSame($none, Snapshot::parseStats("Number of files: 1,2,3 (reg: x)\nTotal file size: 12,34 bytes\n"));
        $this->assertSame($none, Snapshot::parseStats("Number of files: lots\n"));
    }

    public function testParseStatsZeroFilesHasNoParenthesis(): void
    {
        $this->assertSame(
            ['regFiles' => 0, 'totalSize' => 0, 'regFilesText' => '0', 'totalSizeText' => '0'],
            Snapshot::parseStats("Number of files: 0\nTotal file size: 0 bytes\n")
        );
    }

    public function testParseStatsKeepsRsyncsOwnText(): void
    {
        $this->assertSame(
            ['regFiles' => 45896, 'totalSize' => 408630000000, 'regFilesText' => '45,896', 'totalSizeText' => '408.63G'],
            Snapshot::parseStats("Number of files: 45,897 (reg: 45,896, dir: 1)\nTotal file size: 408.63G bytes\n")
        );
    }

    /** @return array<string,array{0:string,1:?int}> */
    public static function numbers(): array
    {
        return [
            'plain'           => ['1234', 1234],
            'comma groups'    => ['1,234,567', 1234567],
            'dot groups'      => ['1.234.567', 1234567],
            'mixed groups'    => ['1,234.567', null],
            'bad group'       => ['12,34', null],
            'K'               => ['23.50K', 23500],
            'M comma decimal' => ['1,24M', 1240000],
            'G'               => ['3.00G', 3000000000],
            'unit no decimal' => ['7K', 7000],
            'lowercase unit'  => ['1.00k', null],
            'negative'        => ['-1', null],
            'empty'           => ['', null],
        ];
    }

    #[DataProvider('numbers')]
    public function testParseNumber(string $s, ?int $want): void
    {
        $this->assertSame($want, Snapshot::parseNumber($s));
    }

    public function testLinkDestMissingListsEachPathOnce(): void
    {
        $this->assertSame(['/w/nope/sub'], Snapshot::linkDestMissing(self::fixture('rsync-3.5.0-linkdest-missing.txt')));
        $out = "--link-dest arg does not exist: /mnt/s/2026-10-20T030000Z/app data\r\n"
            . "--link-dest arg does not exist: /mnt/s/x/y\n"
            . "--link-dest arg does not exist: /mnt/s/x/y\n"
            . "  --link-dest arg does not exist: /not/at/line/start\n";
        $this->assertSame(['/mnt/s/2026-10-20T030000Z/app data', '/mnt/s/x/y'], Snapshot::linkDestMissing($out));
        $this->assertSame([], Snapshot::linkDestMissing(self::fixture('rsync-3.5.0-plain.txt')));
    }

    // --- helper protocol --------------------------------------------------------

    /** @param array<int,string> $records */
    private static function reply(array $records): string
    {
        return "ur-snapshot 1\n" . implode('', array_map(static fn(string $r): string => "$r\n", $records)) . "ok\n";
    }

    public function testParseHelperProbe(): void
    {
        $r = Snapshot::parseHelper(self::reply(['uid 0', 'now 1792540800', 'ntp yes', 'ionice no']), 'probe');
        $this->assertSame(['ok' => true, 'uid' => 0, 'now' => 1792540800, 'ntp' => 'yes', 'ionice' => false], $r);
    }

    public function testParseHelperCheckAndCommitReturnDf(): void
    {
        foreach (['check', 'commit'] as $op) {
            $r = Snapshot::parseHelper(self::reply(['df 1000 200 800']), $op);
            $this->assertSame(['ok' => true, 'df' => ['size' => 1000, 'used' => 200, 'avail' => 800]], $r);
        }
    }

    public function testParseHelperOperationsWithoutRecords(): void
    {
        foreach (['mkstamp', 'delete', 'hold', 'pin'] as $op) {
            $this->assertSame(['ok' => true], Snapshot::parseHelper("ur-snapshot 1\nok\n", $op));
            $this->assertFalse(Snapshot::parseHelper(self::reply(['df 1 1 1']), $op)['ok']);
        }
    }

    public function testParseHelperList(): void
    {
        $meta = base64_encode('{"subs":{"appdata":{"files":12,"bytes":345}}}');
        $r = Snapshot::parseHelper(self::reply([
            'entry 2026-10-20T030000Z d 1 1',
            'entry 2026-10-21T030000Z d 0 1',
            'entry 2026-10-19T030000Z.inprogress d 0 0',
            'entry 2026-10-18T030000Z f 0 0',
            'entry 2026-02-30T030000Z d 0 0',
            'stampish 2027-01-01T000000Z',
            'latest 2026-10-21T030000Z',
            'hold 0',
            'foreign 3',
            'sub appdata',
            'sub Latest',
            'sub boot',
            "meta 2026-10-21T030000Z $meta",
            'df 1000 200 800',
        ]), 'list');
        $this->assertTrue($r['ok']);
        $this->assertSame([
            ['name' => '2026-10-20T030000Z', 'type' => 'd', 'keep' => true, 'meta' => true],
            ['name' => '2026-10-21T030000Z', 'type' => 'd', 'keep' => false, 'meta' => true],
            ['name' => '2026-10-19T030000Z.inprogress', 'type' => 'd', 'keep' => false, 'meta' => false],
            ['name' => '2026-10-18T030000Z', 'type' => 'f', 'keep' => false, 'meta' => false],
            ['name' => '2026-02-30T030000Z', 'type' => 'd', 'keep' => false, 'meta' => false],
        ], $r['entries']);
        $this->assertSame(['2027-01-01T000000Z'], $r['stampish']);
        $this->assertSame('2026-10-21T030000Z', $r['latest']);
        $this->assertFalse($r['hold']);
        $this->assertSame(3, $r['foreign']);
        $this->assertSame(['appdata', 'boot'], $r['subs'], 'the reserved sub is dropped');
        $this->assertSame(['stamp' => '2026-10-21T030000Z', 'data' => ['subs' => ['appdata' => ['files' => 12, 'bytes' => 345]]]], $r['meta']);
        $this->assertSame(['size' => 1000, 'used' => 200, 'avail' => 800], $r['df']);
    }

    public function testParseHelperListMinimal(): void
    {
        $r = Snapshot::parseHelper(self::reply(['latest none', 'hold 1', 'foreign 0', 'df 1 0 1']), 'list');
        $this->assertSame('', $r['latest']);
        $this->assertTrue($r['hold']);
        $this->assertSame([], $r['entries']);
        $this->assertNull($r['meta']);
        $this->assertSame('', Snapshot::parseHelper(self::reply(['latest invalid', 'hold 0', 'foreign 0', 'df 1 0 1']), 'list')['latest']);
    }

    public function testParseHelperMetaThatIsNotJsonHasNoData(): void
    {
        $r = Snapshot::parseHelper(self::reply([
            'latest none', 'hold 0', 'foreign 0', 'meta 2026-10-21T030000Z ' . base64_encode('{not json'), 'df 1 0 1',
        ]), 'list');
        $this->assertSame(['stamp' => '2026-10-21T030000Z', 'data' => null], $r['meta']);
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function badReplies(): array
    {
        $list = static fn(string $extra): string => "ur-snapshot 1\n$extra\nlatest none\nhold 0\nforeign 0\ndf 1 0 1\nok\n";
        $big  = base64_encode(str_repeat('x', Snapshot::META_MAX_BYTES + 1));
        return [
            'empty'                 => ['', 'check', 'missing header'],
            'missing header'        => ["df 1 0 1\nok\n", 'check', 'missing header'],
            'wrong API'             => ["ur-snapshot 2\ndf 1 0 1\nok\n", 'check', 'unknown API'],
            'header only'           => ["ur-snapshot 1\n", 'mkstamp', 'missing trailer'],
            'missing trailer'       => ["ur-snapshot 1\ndf 1 0 1\n", 'check', 'missing trailer'],
            'truncated'             => ["ur-snapshot 1\ndf 1 0", 'check', 'missing trailer'],
            'trailer not last'      => ["ur-snapshot 1\nok\ndf 1 0 1\n", 'check', 'missing trailer'],
            'unknown op'            => ["ur-snapshot 1\nok\n", 'format', 'unknown operation'],
            'unexpected record'     => ["ur-snapshot 1\nuid 0\ndf 1 0 1\nok\n", 'check', 'unexpected record'],
            'missing record'        => ["ur-snapshot 1\nok\n", 'check', 'missing record df'],
            'list without hold'     => ["ur-snapshot 1\nlatest none\nforeign 0\ndf 1 0 1\nok\n", 'list', 'missing record hold'],
            'duplicate record'      => ["ur-snapshot 1\ndf 1 0 1\ndf 1 0 1\nok\n", 'check', 'duplicate record'],
            'bad df'                => ["ur-snapshot 1\ndf 1 0 -1\nok\n", 'check', 'bad df'],
            'bad uid'               => ["ur-snapshot 1\nuid root\nnow 1\nntp yes\nionice no\nok\n", 'probe', 'bad uid'],
            'bad ntp'               => ["ur-snapshot 1\nuid 0\nnow 1\nntp maybe\nionice no\nok\n", 'probe', 'bad ntp'],
            'bad ionice'            => ["ur-snapshot 1\nuid 0\nnow 1\nntp yes\nionice 1\nok\n", 'probe', 'bad ionice'],
            'traversal entry'       => [$list('entry ../../etc d 0 0'), 'list', 'bad entry'],
            'slash in entry'        => [$list('entry 2026-10-21T030000Z/../x d 0 0'), 'list', 'bad entry'],
            'CR in entry'           => [$list("entry 2026-10-21T030000Z\r d 0 0"), 'list', 'bad entry'],
            'space in entry'        => [$list('entry 2026-10-21T030000Z x d 0 0'), 'list', 'bad entry'],
            'foreign name entry'    => [$list('entry appdata d 0 0'), 'list', 'bad entry'],
            'unknown suffix entry'  => [$list('entry 2026-10-21T030000Z.partial d 0 0'), 'list', 'bad entry'],
            'bad entry type'        => [$list('entry 2026-10-21T030000Z x 0 0'), 'list', 'bad entry'],
            'bad stampish'          => [$list('stampish 2026-10-21T030000Z.x'), 'list', 'bad stampish'],
            'dotdot sub'            => [$list('sub ..'), 'list', 'bad sub'],
            'dot sub'               => [$list('sub .'), 'list', 'bad sub'],
            'slash sub'             => [$list('sub a/b'), 'list', 'bad sub'],
            'long sub'              => [$list('sub ' . str_repeat('a', 65)), 'list', 'bad sub'],
            'meta bad stamp'        => [$list('meta 2026-02-30T030000Z e30='), 'list', 'bad meta'],
            'meta not base64'       => [$list('meta 2026-10-21T030000Z {}'), 'list', 'bad meta'],
            'meta oversized'        => [$list("meta 2026-10-21T030000Z $big"), 'list', 'larger than'],
            'meta oversized base64' => [$list('meta 2026-10-21T030000Z ' . str_repeat('A', 87388)), 'list', 'larger than'],
            'meta empty'            => [$list('meta 2026-10-21T030000Z ='), 'list', 'bad meta'],
            'meta twice'            => [$list("meta 2026-10-21T030000Z e30=\nmeta 2026-10-21T030000Z e30="), 'list', 'duplicate'],
        ];
    }

    #[DataProvider('badReplies')]
    public function testParseHelperRejects(string $stdout, string $op, string $needle): void
    {
        $r = Snapshot::parseHelper($stdout, $op);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString($needle, (string) $r['error']);
    }

    public function testParseHelperRejectsABadLatestAndHoldValue(): void
    {
        $this->assertStringContainsString('bad latest', (string) Snapshot::parseHelper(self::reply(['latest ../etc', 'hold 0', 'foreign 0', 'df 1 0 1']), 'list')['error']);
        $this->assertStringContainsString('bad hold', (string) Snapshot::parseHelper(self::reply(['latest none', 'hold yes', 'foreign 0', 'df 1 0 1']), 'list')['error']);
    }

    public function testParseHelperNeverEchoesControlCharacters(): void
    {
        $r = Snapshot::parseHelper("ur-snapshot 1\n\x1b[2Jevil 1\nok\n", 'check');
        $this->assertStringNotContainsString("\x1b", (string) $r['error']);
    }

    // --- run options ------------------------------------------------------------

    public function testRunOptionsForcesTheUnsafeOptionsOffOnACopy(): void
    {
        $opts = Config::defaultRsyncOptions();
        foreach (Snapshot::FORCED_OFF as $key) {
            $opts[$key] = true;
        }
        $opts['maxDelete'] = '100';
        $before = $opts;

        $run = Snapshot::runOptions($opts);
        $this->assertSame($before, $opts, 'the input is not modified');
        foreach (Snapshot::FORCED_OFF as $key) {
            $this->assertFalse($run[$key], $key);
        }
        $this->assertSame('', $run['maxDelete']);
        $this->assertSame($opts['archive'], $run['archive']);
        $this->assertSame($opts['partial'], $run['partial']);

        $tokens = Rsync::optionTokens($run, 'SSH');
        foreach (['--mkpath', '--delete', '--delete-excluded', '-H', '--inplace'] as $flag) {
            $this->assertNotContains($flag, $tokens);
        }
        $this->assertEmpty(preg_grep('/^--max-delete/', $tokens));
    }

    public function testRunOptionsSetsAbsentKeysOff(): void
    {
        $run = Snapshot::runOptions([]);
        foreach (Snapshot::FORCED_OFF as $key) {
            $this->assertFalse($run[$key]);
        }
        $this->assertSame('', $run['maxDelete']);
    }

    // --- helper file ------------------------------------------------------------

    public function testHelperSha256IsInStepWithTheShippedFile(): void
    {
        $path = dirname(__DIR__) . '/source/scripts/ur-snapshot.sh';
        $this->assertSame(hash_file('sha256', $path), Snapshot::HELPER_SHA256, 'update Snapshot::HELPER_SHA256 after editing ur-snapshot.sh');
        $this->assertSame($path, Snapshot::helperPath());
        $this->assertSame(file_get_contents($path), Snapshot::helperScript());
        $this->assertLessThanOrEqual(Snapshot::HELPER_MAX_BYTES, filesize($path));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($path)), -4), 'the helper ships 0644; it is never executed locally');
    }

    private function helperCopy(string $body): string
    {
        $path = $this->tmp . '/ur-snapshot.sh';
        file_put_contents($path, $body);
        Snapshot::$helperPathOverride = $path;
        return $path;
    }

    private function assertHelperRefused(string $needle): void
    {
        try {
            Snapshot::helperScript();
            $this->fail('helperScript() accepted a bad helper');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    public function testHelperScriptAcceptsAnExactCopy(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__) . '/source/scripts/ur-snapshot.sh');
        $this->helperCopy($body);
        $this->assertSame($body, Snapshot::helperScript());
    }

    public function testHelperScriptRefusesAModifiedFile(): void
    {
        $this->helperCopy(file_get_contents(dirname(__DIR__) . '/source/scripts/ur-snapshot.sh') . "rm -rf /\n");
        $this->assertHelperRefused('sha256 mismatch');
    }

    public function testHelperScriptRefusesASymlink(): void
    {
        $real = $this->tmp . '/real.sh';
        copy(dirname(__DIR__) . '/source/scripts/ur-snapshot.sh', $real);
        symlink($real, $this->tmp . '/link.sh');
        Snapshot::$helperPathOverride = $this->tmp . '/link.sh';
        $this->assertHelperRefused('not a regular file');
    }

    public function testHelperScriptRefusesAMissingFileOrADirectory(): void
    {
        Snapshot::$helperPathOverride = $this->tmp . '/missing.sh';
        $this->assertHelperRefused('missing');
        Snapshot::$helperPathOverride = $this->tmp;
        $this->assertHelperRefused('not a regular file');
    }

    public function testHelperScriptRefusesAnOversizedFile(): void
    {
        $this->helperCopy("#!/bin/sh\n" . str_repeat("#\n", Snapshot::HELPER_MAX_BYTES));
        $this->assertHelperRefused('larger than');
    }

    public function testHelperScriptRefusesAFileWithoutTheShebang(): void
    {
        $this->helperCopy("#!/bin/bash\n:\n");
        $this->assertHelperRefused('#!/bin/sh');
    }

    public function testHelperScriptRefusesAnUnreadableFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read a mode-000 file');
        }
        $path = $this->helperCopy((string) file_get_contents(dirname(__DIR__) . '/source/scripts/ur-snapshot.sh'));
        chmod($path, 0000);
        $this->assertHelperRefused('cannot be read');
    }

    // --- remote command -----------------------------------------------------------

    /** @return array<int,string> */
    private static function commonArgs(): array
    {
        return ['/mnt/disk1/snapshots/app', '/mnt/disk1/.unraid-rsync-target', 'a1b2c3', 'appdata-backup'];
    }

    public function testRemoteCommandQuotesEveryElement(): void
    {
        $cmd = Snapshot::remoteCommand("echo 'hi'", 'check', self::commonArgs(), true);
        $this->assertSame(
            "'sudo' '-n' 'sh' '-c' 'echo '\\''hi'\\''' 'ur-snapshot' 'check' '/mnt/disk1/snapshots/app' "
            . "'/mnt/disk1/.unraid-rsync-target' 'a1b2c3' 'appdata-backup'",
            $cmd
        );
        $this->assertStringStartsWith("'sh' '-c' ", Snapshot::remoteCommand(':', 'probe', [], false));
    }

    /**
     * Run a remote command string the way the receiver's login shell does
     * (`sh -c <string>`) and return what the script received, NUL-split.
     *
     * @return array<int,string>
     */
    private function runThroughSh(string $cmd, ?string $path = null): array
    {
        $env = $path === null ? null : ['PATH' => $path . ':' . (string) getenv('PATH')];
        $p = proc_open(['sh', '-c', $cmd], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($p), $err);
        return explode("\0", rtrim($out, "\0"));
    }

    /** @return array<int,string> */
    private static function hostileArgs(): array
    {
        return [
            "/mnt/disk 1/it's \$HOME; `id` \$(id) \"q\" \\ * ? [a] ~ & | < > ü 日本",
            "/mnt/disk 1/.unraid-rsync-target ' ;",
            "uuid 'x' \$y `z` ; rm -rf /",
            "job \"1\" ' \$IFS",
            '2026-10-21T030000Z',
        ];
    }

    public function testRemoteCommandSurvivesRealShWithHostileArguments(): void
    {
        $args = self::hostileArgs();
        $cmd  = Snapshot::remoteCommand('printf "%s\0" "$0" "$@"', 'mkstamp', $args, false);
        $this->assertSame(array_merge(['ur-snapshot', 'mkstamp'], $args), $this->runThroughSh($cmd));
    }

    public function testRemoteCommandWithSudoRunsShThroughSudoN(): void
    {
        $bin = $this->tmp . '/bin';
        mkdir($bin);
        file_put_contents($bin . '/sudo', "#!/bin/sh\n[ \"\$1\" = -n ] || exit 99\nshift\nprintf 'sudo -n\\0'\nexec \"\$@\"\n");
        chmod($bin . '/sudo', 0755);

        $args = self::hostileArgs();
        $cmd  = Snapshot::remoteCommand('printf "%s\0" "$0" "$@"', 'pin', $args, true);
        $this->assertSame(array_merge(['sudo -n', 'ur-snapshot', 'pin'], $args), $this->runThroughSh($cmd, $bin));
    }

    public function testRemoteCommandRefusesBytesTheLocaleWouldDrop(): void
    {
        $prev = (string) setlocale(LC_CTYPE, '0');
        if (setlocale(LC_CTYPE, 'C.UTF-8', 'en_US.UTF-8', 'C.utf8') === false) {
            $this->markTestSkipped('no UTF-8 locale available');
        }
        try {
            $args    = self::commonArgs();
            $args[0] = "/mnt/disk1/snap\xff";
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('not valid text');
            Snapshot::remoteCommand(':', 'check', $args, false);
        } finally {
            setlocale(LC_CTYPE, $prev);
        }
    }

    /** @return array<string,array{0:string,1:array<int,mixed>,2:string}> */
    public static function badHelperArgs(): array
    {
        $c = self::commonArgs();
        $with = static function (int $i, string $v) use ($c): array {
            $c[$i] = $v;
            return $c;
        };
        return [
            'unknown op'          => ['format', $c, 'Unknown helper operation'],
            'probe with args'     => ['probe', $c, 'takes 0 arguments'],
            'too few'             => ['check', array_slice($c, 0, 3), 'takes 4 arguments'],
            'too many'            => ['check', array_merge($c, ['x']), 'takes 4 arguments'],
            'missing op arg'      => ['mkstamp', $c, 'takes 5 arguments'],
            'not a list'          => ['check', ['a' => '/x/y', 'b' => '/x/z', 'c' => 'u', 'd' => 'j'], 'takes 4 arguments'],
            'non-string'          => ['check', [$c[0], $c[1], 7, $c[3]], 'non-text'],
            'empty content'       => ['check', $with(2, ''), 'non-text'],
            'newline in job id'   => ['check', $with(3, "job\nx"), 'non-text'],
            'NUL in root'         => ['check', $with(0, "/mnt/a\0b"), 'non-text'],
            'DEL in marker'       => ['check', $with(1, "/mnt/a\x7f"), 'non-text'],
            'relative root'       => ['check', $with(0, 'mnt/disk1/s'), 'snapshot root'],
            'root slash'          => ['check', $with(0, '/'), 'snapshot root'],
            'trailing slash'      => ['check', $with(0, '/mnt/disk1/s/'), 'snapshot root'],
            'double slash'        => ['check', $with(0, '/mnt//disk1/s'), 'snapshot root'],
            'dot segment'         => ['check', $with(0, '/mnt/./disk1'), 'snapshot root'],
            'dotdot segment'      => ['check', $with(0, '/mnt/disk1/../etc'), 'snapshot root'],
            'trailing dotdot'     => ['check', $with(0, '/mnt/disk1/..'), 'snapshot root'],
            'relative marker'     => ['check', $with(1, '.unraid-rsync-target'), 'target marker'],
            'bad stamp'           => ['mkstamp', array_merge($c, ['2026-02-30T030000Z']), 'not a valid snapshot name'],
            'suffixed stamp'      => ['pin', array_merge($c, ['2026-10-21T030000Z.inprogress']), 'not a valid snapshot name'],
            'hold bad stamp'      => ['hold', array_merge($c, ['now']), 'not a valid snapshot name'],
            'delete foreign'      => ['delete', array_merge($c, ['appdata']), 'not a valid snapshot name'],
            'delete traversal'    => ['delete', array_merge($c, ['2026-10-21T030000Z/..']), 'not a valid snapshot name'],
            'commit bad meta'     => ['commit', array_merge($c, ['2026-10-21T030000Z', 'e30=;id']), 'base64'],
            'commit huge meta'    => ['commit', array_merge($c, ['2026-10-21T030000Z', str_repeat('A', 87384 + 4)]), 'base64'],
        ];
    }

    /** @param array<int,mixed> $args */
    #[DataProvider('badHelperArgs')]
    public function testRemoteCommandRefusesBadArguments(string $op, array $args, string $needle): void
    {
        $this->assertStringContainsString($needle, (string) Snapshot::helperArgsError($op, $args));
        $this->expectException(InvalidArgumentException::class);
        Snapshot::remoteCommand(':', $op, $args, false);
    }

    public function testHelperArgsAcceptEveryOperation(): void
    {
        $c = self::commonArgs();
        $this->assertNull(Snapshot::helperArgsError('probe', []));
        $this->assertNull(Snapshot::helperArgsError('check', $c));
        $this->assertNull(Snapshot::helperArgsError('list', $c));
        $this->assertNull(Snapshot::helperArgsError('mkstamp', array_merge($c, ['2026-10-21T030000Z'])));
        $this->assertNull(Snapshot::helperArgsError('commit', array_merge($c, ['2026-10-21T030000Z', base64_encode(str_repeat('x', Snapshot::META_MAX_BYTES))])));
        $this->assertNull(Snapshot::helperArgsError('hold', array_merge($c, ['2026-10-21T030000Z'])));
        $this->assertNull(Snapshot::helperArgsError('pin', array_merge($c, ['2026-10-21T030000Z'])));
        foreach (['2026-10-21T030000Z', '2026-10-21T030000Z.inprogress', '2026-10-21T030000Z.deleting', '2026-10-21T030000Z.rolledback'] as $name) {
            $this->assertNull(Snapshot::helperArgsError('delete', array_merge($c, [$name])), $name);
        }
    }

    // --- runRemote ----------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function pieces(array $sshArgv = ['ssh', '-i', '/tmp/unraid.rsync/keys/t', '-p', '22'], array $sshEnv = []): array
    {
        return ['ok' => true, 'sshArgv' => $sshArgv, 'sshEnv' => $sshEnv];
    }

    public function testRunRemoteBuildsTheSshArgvAndUsesTheSeam(): void
    {
        $calls = [];
        Snapshot::$remoteRunner = static function (array $argv, ?array $env, int $timeout, ?callable $stop) use (&$calls): array {
            $calls[] = [$argv, $env, $timeout, $stop];
            return ['exit' => 0, 'stdout' => "ur-snapshot 1\nok\n", 'stderr' => ''];
        };
        $stop = static fn(): bool => false;
        $args = array_merge(self::commonArgs(), ['2026-10-21T030000Z']);
        $r = Snapshot::runRemote(self::pieces(sshEnv: ['UR_ASKPASS_FILE' => '/tmp/p']), 'backup@nas.example', 'pin', $args, true, 120, $stop);

        $this->assertSame(['exit' => 0, 'stdout' => "ur-snapshot 1\nok\n", 'stderr' => ''], $r);
        $this->assertCount(1, $calls);
        [$argv, $env, $timeout, $gotStop] = $calls[0];
        $this->assertSame(
            ['ssh', '-i', '/tmp/unraid.rsync/keys/t', '-p', '22', '-o', 'LogLevel=ERROR', '--', 'backup@nas.example',
                Snapshot::remoteCommand(Snapshot::helperScript(), 'pin', $args, true)],
            $argv
        );
        $this->assertSame('/tmp/p', $env['UR_ASKPASS_FILE'] ?? null);
        $this->assertSame(120, $timeout);
        $this->assertSame($stop, $gotStop);
    }

    public function testRunRemoteHandsSshAnUnbracketedIpv6Host(): void
    {
        $seen = [];
        Snapshot::$remoteRunner = static function (array $argv) use (&$seen): array {
            $seen = $argv;
            return ['exit' => 0];
        };
        Snapshot::runRemote(self::pieces(), 'root@[2001:db8::1]', 'probe', [], false, 120);
        $this->assertContains('root@2001:db8::1', $seen);
        Snapshot::runRemote(self::pieces(), 'root@[fe80::1%eth0]', 'probe', [], false, 120);
        $this->assertContains('root@fe80::1%eth0', $seen);
    }

    public function testRunRemoteCapsWhatTheSeamReturns(): void
    {
        Snapshot::$remoteRunner = static fn(): array => ['exit' => '16', 'stdout' => str_repeat('a', Snapshot::STDOUT_MAX_BYTES + 5)];
        $r = Snapshot::runRemote(self::pieces(), 'root@nas', 'probe', [], false, 120);
        $this->assertSame(16, $r['exit']);
        $this->assertSame(Snapshot::STDOUT_MAX_BYTES, strlen($r['stdout']));
        $this->assertSame('', $r['stderr']);
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function badRemoteTargets(): array
    {
        return [
            'no argv'          => [['ok' => true], 'root@nas'],
            'empty argv'       => [['sshArgv' => []], 'root@nas'],
            'non-string argv'  => [['sshArgv' => ['ssh', 3]], 'root@nas'],
            'no user'          => [self::pieces(), 'nas'],
            'option host'      => [self::pieces(), 'root@-oProxyCommand=x'],
            'option user'      => [self::pieces(), '-oProxyCommand=x@nas'],
            'space'            => [self::pieces(), 'root@nas x'],
            'two at'           => [self::pieces(), 'a@b@c'],
        ];
    }

    /** @param array<string,mixed> $pieces */
    #[DataProvider('badRemoteTargets')]
    public function testRunRemoteRefusesABadTarget(array $pieces, string $userHost): void
    {
        Snapshot::$remoteRunner = function (): array {
            $this->fail('the runner must not be called');
        };
        $this->expectException(InvalidArgumentException::class);
        Snapshot::runRemote($pieces, $userHost, 'probe', [], false, 120);
    }

    public function testRunRemoteFailsClosedOnAModifiedHelperBeforeAnyCall(): void
    {
        $this->helperCopy("#!/bin/sh\necho tampered\n");
        Snapshot::$remoteRunner = function (): array {
            $this->fail('the runner must not be called');
        };
        $this->expectException(RuntimeException::class);
        Snapshot::runRemote(self::pieces(), 'root@nas', 'probe', [], false, 120);
    }

    /** sshArgv that is really `sh -c <body> ssh`, so everything runRemote appends becomes "$@". */
    private static function fakeSsh(string $body): array
    {
        return self::pieces(['sh', '-c', $body, 'ssh']);
    }

    public function testDefaultRunnerPassesTheArgvWithoutAShell(): void
    {
        $r = Snapshot::runRemote(self::fakeSsh('printf "%s\0" "$@"; echo err >&2; exit 16'), 'root@nas', 'check', self::commonArgs(), false, 30);
        $this->assertSame(16, $r['exit']);
        $this->assertSame("err\n", $r['stderr']);
        $this->assertSame(
            ['-o', 'LogLevel=ERROR', '--', 'root@nas', Snapshot::remoteCommand(Snapshot::helperScript(), 'check', self::commonArgs(), false)],
            explode("\0", rtrim($r['stdout'], "\0"))
        );
    }

    public function testDefaultRunnerHonoursTheDeadline(): void
    {
        $t = microtime(true);
        $r = Snapshot::runRemote(self::fakeSsh('sleep 30'), 'root@nas', 'probe', [], false, 1);
        $this->assertSame(Snapshot::REMOTE_TIMED_OUT, $r['exit']);
        $this->assertLessThan(10, microtime(true) - $t);
    }

    public function testDefaultRunnerHonoursStopWhen(): void
    {
        $t = microtime(true);
        $stop = static fn(): bool => microtime(true) - $t > 0.5;
        $r = Snapshot::runRemote(self::fakeSsh('sleep 30'), 'root@nas', 'delete', array_merge(self::commonArgs(), ['2026-10-21T030000Z']), false, 0, $stop);
        $this->assertSame(143, $r['exit']);
        $this->assertLessThan(10, microtime(true) - $t);
    }

    public function testDefaultRunnerStopsAChildThatClosedItsPipes(): void
    {
        $t = microtime(true);
        $r = Snapshot::runRemote(self::fakeSsh('exec >/dev/null 2>&1; sleep 30'), 'root@nas', 'probe', [], false, 1);
        $this->assertSame(Snapshot::REMOTE_TIMED_OUT, $r['exit']);
        $this->assertLessThan(10, microtime(true) - $t);
    }

    public function testDefaultRunnerCapsStdoutAndStderr(): void
    {
        $r = Snapshot::runRemote(self::fakeSsh('head -c 300000 /dev/zero; head -c 70000 /dev/zero >&2'), 'root@nas', 'probe', [], false, 30);
        $this->assertSame(0, $r['exit']);
        $this->assertSame(Snapshot::STDOUT_MAX_BYTES, strlen($r['stdout']));
        $this->assertSame(Snapshot::STDERR_MAX_BYTES, strlen($r['stderr']));
    }

    public function testDefaultRunnerReportsASignalledChild(): void
    {
        $r = Snapshot::runRemote(self::fakeSsh('kill -9 $$'), 'root@nas', 'probe', [], false, 30);
        $this->assertSame(137, $r['exit']);
    }

    public function testDefaultRunnerReportsAnUnstartableSsh(): void
    {
        $r = Snapshot::runRemote(self::pieces(['/nonexistent/ssh']), 'root@nas', 'probe', [], false, 30);
        $this->assertNotSame(0, $r['exit']);
    }

    public function testLastSnapshotFollowsTheLastRealRunAndStillReadsOlderSummaries(): void
    {
        $real = ['state' => 'SUCCESS', 'dryRun' => false, 'snapshot' => '2026-10-01T030000Z', 'committed' => true];
        $dry  = ['state' => 'SUCCESS', 'dryRun' => true, 'snapshot' => '', 'committed' => false];
        $this->assertSame('2026-10-01T030000Z', Snapshot::lastSnapshot($dry + ['lastReal' => $real])['stamp']);
        $this->assertSame('2026-10-01T030000Z', Snapshot::lastSnapshot($real)['stamp'], 'before lastReal existed');
        $preFix = ['state' => 'SUCCESS', 'startedAt' => '', 'finishedAt' => '', 'exitCode' => 0, 'reason' => ''];
        $this->assertSame('2026-10-01T030000Z', Snapshot::lastSnapshot($real + ['lastReal' => $preFix])['stamp'], 'a lastReal with no snapshot fields');
        $this->assertNull(Snapshot::lastSnapshot($dry + ['lastReal' => null]));
        $this->assertSame(
            ['snapshot' => '2026-10-01T030000Z', 'committed' => true],
            array_intersect_key(Runner::lastReal($real), ['snapshot' => 1, 'committed' => 1])
        );
        $this->assertArrayNotHasKey('snapshot', Runner::lastReal(['state' => 'SUCCESS']), 'a mirror keeps its shape');
    }
}

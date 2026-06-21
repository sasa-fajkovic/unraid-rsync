<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase 6 tests for Logger.php additions:
 *   - pruneRuns() keeps the newest N run logs and deletes older ones (oldest
 *     first), never touching plugin.log;
 *   - listRuns() returns the run logs NEWEST first, capped at the limit, and
 *     attributes the summary's state to the run that produced it;
 *   - runLogPathById() whitelists the run-file id and rejects traversal /
 *     absolute / non-run names, confining reads to the job's log dir.
 */
final class LoggerRetentionTest extends TestCase
{
    private string $rtBase;

    protected function setUp(): void
    {
        $this->rtBase = sys_get_temp_dir() . '/ur-logret-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Logger::$baseOverride = $this->rtBase;
        Logger::$retention = null;
    }

    protected function tearDown(): void
    {
        Logger::$baseOverride = null;
        Logger::$retention = null;
        if (is_dir($this->rtBase)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->rtBase, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->rtBase);
        }
    }

    /** Create $n run logs for a job spaced 1 minute apart, returning their paths. */
    private function seedRuns(string $jobId, int $n, int $startTs = 1750000000): array
    {
        $paths = [];
        for ($i = 0; $i < $n; $i++) {
            $paths[] = Logger::openRun($jobId, $startTs + $i * 60);
        }
        return $paths;
    }

    public function testPruneKeepsNewestNAndDeletesOldest(): void
    {
        $jobId = 'j-prune';
        $this->seedRuns($jobId, 15);
        $this->assertCount(15, glob($this->rtBase . '/logs/' . $jobId . '/run-*.log'));

        $deleted = Logger::pruneRuns($jobId, 10);
        $this->assertSame(5, $deleted);

        $remaining = array_map('basename', glob($this->rtBase . '/logs/' . $jobId . '/run-*.log'));
        sort($remaining);
        $this->assertCount(10, $remaining);
        // The newest 10 (highest stamps) survive; the oldest 5 are gone.
        $this->assertContains('run-' . gmdate('Ymd\THis\Z', 1750000000 + 14 * 60) . '.log', $remaining);
        $this->assertNotContains('run-' . gmdate('Ymd\THis\Z', 1750000000) . '.log', $remaining);
        $this->assertNotContains('run-' . gmdate('Ymd\THis\Z', 1750000000 + 4 * 60) . '.log', $remaining);
    }

    public function testPruneNoOpWhenUnderLimit(): void
    {
        $jobId = 'j-few';
        $this->seedRuns($jobId, 3);
        $this->assertSame(0, Logger::pruneRuns($jobId, 10));
        $this->assertCount(3, glob($this->rtBase . '/logs/' . $jobId . '/run-*.log'));
    }

    public function testPruneUsesDefaultRetention(): void
    {
        $jobId = 'j-default';
        // Default retention is 100 (Logger::DEFAULT_RETENTION, kept consistent
        // with Config::DEFAULT_RETENTION). Seed 102 -> 2 oldest pruned, 100 kept.
        $this->seedRuns($jobId, 102);
        $deleted = Logger::pruneRuns($jobId);
        $this->assertSame(2, $deleted);
        $this->assertCount(100, glob($this->rtBase . '/logs/' . $jobId . '/run-*.log'));
    }

    public function testRetentionOverrideIsHonoured(): void
    {
        $jobId = 'j-ovr';
        $this->seedRuns($jobId, 8);
        Logger::$retention = 3;
        $this->assertSame(5, Logger::pruneRuns($jobId));
        $this->assertCount(3, glob($this->rtBase . '/logs/' . $jobId . '/run-*.log'));
    }

    public function testRetentionClampedToAtLeastOne(): void
    {
        Logger::$retention = 0;
        $this->assertSame(1, Logger::retention());
        Logger::$retention = -5;
        $this->assertSame(1, Logger::retention());
    }

    public function testPruneNeverTouchesPluginLog(): void
    {
        $jobId = 'j-keepplugin';
        $this->seedRuns($jobId, 12);
        // The plugin log lives one level up, not in the job dir; but assert
        // explicitly that a prune leaves it intact.
        Logger::event($this->seedRuns('j-other', 1)[0], 'j-other', 'hello');
        $this->assertFileExists(Logger::pluginLogPath());
        Logger::pruneRuns($jobId, 5);
        $this->assertFileExists(Logger::pluginLogPath());
    }

    public function testListRunsNewestFirstAndCapped(): void
    {
        $jobId = 'j-list';
        $this->seedRuns($jobId, 12, 1750000000);
        $runs = Logger::listRuns($jobId, 5);
        $this->assertCount(5, $runs);
        // Newest first: ts descending.
        for ($i = 1; $i < count($runs); $i++) {
            $this->assertGreaterThanOrEqual($runs[$i]['ts'], $runs[$i - 1]['ts']);
        }
        // The first entry is the newest run.
        $this->assertSame('run-' . gmdate('Ymd\THis\Z', 1750000000 + 11 * 60) . '.log', $runs[0]['id']);
        $this->assertSame(1750000000 + 11 * 60, $runs[0]['ts']);
    }

    public function testListRunsEmptyForUnknownJob(): void
    {
        $this->assertSame([], Logger::listRuns('j-nope'));
    }

    public function testListRunsIgnoresNonRunFiles(): void
    {
        $jobId = 'j-mixed';
        $this->seedRuns($jobId, 2, 1750000000);
        // Drop some non-run files into the job dir; they must be ignored.
        $dir = $this->rtBase . '/logs/' . $jobId;
        file_put_contents($dir . '/notes.txt', 'x');
        file_put_contents($dir . '/run-bogus.log', 'x'); // wrong stamp shape
        file_put_contents($dir . '/.hidden', 'x');
        $runs = Logger::listRuns($jobId);
        $this->assertCount(2, $runs);
        foreach ($runs as $r) {
            $this->assertMatchesRegularExpression('/^run-\d{8}T\d{6}Z\.log$/', $r['id']);
        }
    }

    public function testListRunsAttributesSummaryStateToMatchingRun(): void
    {
        $jobId = 'j-state';
        // Newest run at startTs+60; summary's startedAt matches it.
        $startTs = 1750000000;
        $this->seedRuns($jobId, 2, $startTs);
        $newestTs = $startTs + 60;
        $summaryReader = static function (string $jid) use ($newestTs) {
            return [
                'state'     => 'SUCCESS',
                'startedAt' => gmdate('Y-m-d\TH:i:s\Z', $newestTs),
            ];
        };
        $runs = Logger::listRuns($jobId, 10, $summaryReader);
        // Newest run carries the summary state; the older run has none.
        $this->assertSame('SUCCESS', $runs[0]['state']);
        $this->assertSame('', $runs[1]['state']);
    }

    public function testRunLogPathByIdAcceptsValidBasenameAndStamp(): void
    {
        $jobId = 'j-resolve';
        $paths = $this->seedRuns($jobId, 1, 1750000000);
        $basename = basename($paths[0]); // run-<stamp>.log

        $resolved = Logger::runLogPathById($jobId, $basename);
        $this->assertNotNull($resolved);
        $this->assertSame(realpath($paths[0]), realpath($resolved));

        // The bare stamp (no .log) is also accepted and re-appended.
        $stamp = preg_replace('/\.log$/', '', $basename);
        $resolved2 = Logger::runLogPathById($jobId, $stamp);
        $this->assertNotNull($resolved2);
        $this->assertSame(realpath($paths[0]), realpath($resolved2));
    }

    #[DataProvider('traversalIds')]
    public function testRunLogPathByIdRejectsTraversalAndBadNames(string $bad): void
    {
        $this->assertNull(Logger::runLogPathById('j-x', $bad), "must reject: $bad");
    }

    public static function traversalIds(): array
    {
        return [
            'parent traversal'     => ['../../etc/passwd'],
            'dotdot stamp'         => ['run-../../secret.log'],
            'absolute path'        => ['/etc/passwd'],
            'absolute log'         => ['/boot/config/x.log'],
            'plugin log'           => ['plugin.log'],
            'backslash'            => ['run-20250101T000000Z\\..\\x.log'],
            'nul byte'             => ["run-20250101T000000Z.log\0.txt"],
            'wrong extension'      => ['run-20250101T000000Z.txt'],
            'no run prefix'        => ['20250101T000000Z.log'],
            'malformed stamp'      => ['run-2025-01-01.log'],
            'empty'                => [''],
            'subdir'               => ['sub/run-20250101T000000Z.log'],
        ];
    }

    public function testLatestRunLogPath(): void
    {
        $jobId = 'j-latest';
        $this->seedRuns($jobId, 3, 1750000000);
        $latest = Logger::latestRunLogPath($jobId);
        $this->assertStringEndsWith('run-' . gmdate('Ymd\THis\Z', 1750000000 + 2 * 60) . '.log', $latest);
        // No runs -> ''.
        $this->assertSame('', Logger::latestRunLogPath('j-none'));
    }

    /**
     * F5 (confinement symmetry): latestRunLogPath must route the newest run id
     * through the SAME runLogPathById confinement helper, so the "latest" and
     * "by id" paths resolve identically (one hardened resolver, not two code
     * paths). Assert latest === runLogPathById(newest-id).
     */
    public function testLatestRunLogPathSharesConfinementResolver(): void
    {
        $jobId = 'j-sym';
        $paths = $this->seedRuns($jobId, 3, 1750000000);
        $newestId = basename($paths[2]); // run-<startTs+120>.log

        $latest = Logger::latestRunLogPath($jobId);
        $viaHelper = Logger::runLogPathById($jobId, $newestId);

        $this->assertNotNull($viaHelper);
        $this->assertNotSame('', $latest);
        // Both resolve to the same (realpath-confined) location.
        $this->assertSame($viaHelper, $latest, 'latest must go through runLogPathById');
        $this->assertSame(realpath($paths[2]), realpath($latest));
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for History.php: the persistent per-execution run history (JSONL on the
 * flash). Covers append + newest-first paging, lazy prune-to-cap, delete, the
 * secret-free record shape (logRef = basename only), and the pure-dots safeId
 * guard. Uses the temp UR_CONFIG_BASE from the bootstrap; each test uses unique
 * job ids and cleans up its own history files.
 */
final class HistoryTest extends TestCase
{
    /** @var array<int,string> job ids whose history files to clean up. */
    private array $ids = [];

    protected function tearDown(): void
    {
        foreach ($this->ids as $id) {
            @unlink(History::path($id));
            @unlink(dirname(History::path($id)) . '/' . $id . '.history.lock');
        }
        $this->ids = [];
    }

    private function id(string $suffix): string
    {
        $id = 'j-hist-' . $suffix . '-' . bin2hex(random_bytes(3));
        $this->ids[] = $id;
        return $id;
    }

    /** A minimal valid record; overrides merge on top. */
    private function rec(array $over = []): array
    {
        return array_merge([
            'startedAt'   => '2026-06-14T12:00:00Z',
            'finishedAt'  => '2026-06-14T12:00:05Z',
            'jobName'     => 'My Job',
            'dryRun'      => false,
            'trigger'     => 'manual',
            'state'       => Rsync::STATE_SUCCESS,
            'exitCode'    => 0,
            'durationSec' => 5,
            'logRef'      => 'run-20260614T120000Z.log',
        ], $over);
    }

    public function testAppendThenListNewestFirst(): void
    {
        $id = $this->id('order');
        History::append($id, $this->rec(['startedAt' => '2026-06-14T10:00:00Z', 'exitCode' => 1]));
        History::append($id, $this->rec(['startedAt' => '2026-06-14T11:00:00Z', 'exitCode' => 2]));
        History::append($id, $this->rec(['startedAt' => '2026-06-14T12:00:00Z', 'exitCode' => 3]));

        $page = History::list($id, 0, 25);
        $this->assertSame(3, $page['total']);
        $this->assertCount(3, $page['runs']);
        // newest-first: last appended is first.
        $this->assertSame(3, $page['runs'][0]['exitCode']);
        $this->assertSame(2, $page['runs'][1]['exitCode']);
        $this->assertSame(1, $page['runs'][2]['exitCode']);
    }

    public function testPaging(): void
    {
        $id = $this->id('page');
        for ($i = 1; $i <= 5; $i++) {
            History::append($id, $this->rec(['exitCode' => $i]));
        }
        $p0 = History::list($id, 0, 2);
        $this->assertSame(5, $p0['total']);
        $this->assertSame([5, 4], array_column($p0['runs'], 'exitCode'));

        $p1 = History::list($id, 2, 2);
        $this->assertSame([3, 2], array_column($p1['runs'], 'exitCode'));

        $p2 = History::list($id, 4, 2);
        $this->assertSame([1], array_column($p2['runs'], 'exitCode'));
    }

    public function testLimitClampedTo100(): void
    {
        $id = $this->id('clamp');
        History::append($id, $this->rec());
        $this->assertSame(100, History::list($id, 0, 9999)['limit']);
        $this->assertSame(1, History::list($id, 0, 0)['limit']); // <1 -> 1
    }

    public function testPruneKeepsNewestAndIsLazy(): void
    {
        $id = $this->id('prune');
        for ($i = 1; $i <= 6; $i++) {
            History::append($id, $this->rec(['exitCode' => $i]));
        }
        History::prune($id, 3);
        $page = History::list($id, 0, 25);
        $this->assertSame(3, $page['total']);
        // newest 3 kept (4,5,6) -> newest-first 6,5,4.
        $this->assertSame([6, 5, 4], array_column($page['runs'], 'exitCode'));

        // Under-cap prune is a no-op (no data loss).
        History::prune($id, 10);
        $this->assertSame(3, History::list($id, 0, 25)['total']);
    }

    public function testMissingJobListsEmpty(): void
    {
        $page = History::list($this->id('empty'), 0, 25);
        $this->assertSame(0, $page['total']);
        $this->assertSame([], $page['runs']);
    }

    public function testRecordShapeIsSecretFreeAndLogRefIsBasename(): void
    {
        $id = $this->id('shape');
        // A full tmpfs path passed as logRef must be stored as a BASENAME only,
        // and unknown keys (e.g. a leaked secret path) must be dropped.
        History::append($id, $this->rec([
            'logRef'  => '/tmp/unraid.rsync/logs/j-x/run-20260614T120000Z.log',
            'secret'  => '/tmp/unraid.rsync/keys/abc123',
        ]));
        $r = History::list($id, 0, 25)['runs'][0];
        $this->assertSame('run-20260614T120000Z.log', $r['logRef']);
        $this->assertArrayNotHasKey('secret', $r);
        // Exactly the canonical keys, nothing else.
        $this->assertSame(
            ['startedAt', 'finishedAt', 'jobName', 'dryRun', 'trigger', 'state', 'exitCode', 'durationSec', 'logRef', 'reason'],
            array_keys($r)
        );
    }

    public function testSnapshotFieldsAreKeptOnlyForSnapshotRecords(): void
    {
        $id = $this->id('snap');
        History::append($id, $this->rec(['snapshot' => '2026-10-21T030000Z', 'committed' => 1, 'suspect' => 0, 'pruned' => '2', 'growthKB' => '512']));
        History::append($id, $this->rec(['snapshot' => '', 'committed' => false]));
        History::append($id, $this->rec());
        [$mirror, $failed, $committed] = History::list($id, 0, 25)['runs'];

        $this->assertSame(
            ['snapshot' => '2026-10-21T030000Z', 'committed' => true, 'suspect' => false, 'pruned' => 2, 'growthKB' => 512],
            array_slice($committed, 10)
        );
        $this->assertSame(['snapshot' => '', 'committed' => false, 'suspect' => false, 'pruned' => 0], array_slice($failed, 10), 'growthKB stays absent');
        $this->assertCount(10, $mirror, 'a mirror record keeps its shape');
    }

    public function testAppendAfterAPartialLastLineStillParses(): void
    {
        $id = $this->id('partial');
        History::append($id, $this->rec(['exitCode' => 1]));
        // Power loss mid-append: a record cut short, no trailing newline.
        file_put_contents(History::path($id), '{"startedAt":"2026-06-14T12:', FILE_APPEND);
        $this->assertTrue(History::append($id, $this->rec(['exitCode' => 2])));

        $this->assertSame([2, 1], array_column(History::list($id, 0, 25)['runs'], 'exitCode'));
        $this->assertStringEndsWith("12:\n" . json_encode(History::list($id, 0, 1)['runs'][0], JSON_UNESCAPED_SLASHES) . "\n", (string) file_get_contents(History::path($id)));
    }

    public function testAppendReportsFailure(): void
    {
        $id = $this->id('fail');
        mkdir(History::path($id)); // a directory where the file should be
        try {
            $this->assertFalse(History::append($id, $this->rec()));
        } finally {
            rmdir(History::path($id));
        }
        $this->assertTrue(History::append($id, $this->rec()));
    }

    /**
     * Start a child PHP process that runs $call against History and wait until
     * it is about to call it.
     *
     * @param array<int,resource> $pipes
     * @return resource
     */
    private function startChild(string $call, array &$pipes)
    {
        $child = sprintf(
            'define("UR_CONFIG_BASE", %s); require %s; echo "ready\n"; %s; exit(0);',
            var_export(UR_CONFIG_BASE, true),
            var_export(realpath(__DIR__ . '/../source/include/History.php'), true),
            $call
        );
        $proc = proc_open([PHP_BINARY, '-r', $child], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $this->assertSame("ready\n", fgets($pipes[1]));
        usleep(300000); // time for the child to reach the lock (or, unlocked, to finish)
        return $proc;
    }

    /** @param resource $proc @param array<int,resource> $pipes */
    private function finishChild($proc, array $pipes): void
    {
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), (string) $err);
    }

    private function lockPath(string $id): string
    {
        return dirname(History::path($id)) . '/' . $id . '.history.lock';
    }

    public function testAppendWaitsForAPruneHoldingTheLockAndBothLand(): void
    {
        $id = $this->id('race-a');
        for ($i = 1; $i <= 5; $i++) {
            History::append($id, $this->rec(['exitCode' => $i]));
        }
        $proc  = null;
        $pipes = [];
        Util::withLock($this->lockPath($id), function () use ($id, &$proc, &$pipes): void {
            $proc = $this->startChild(sprintf('History::append(%s, ["state" => "SUCCESS", "exitCode" => 99])', var_export($id, true)), $pipes);
            $this->assertSame(5, History::list($id, 0, 25)['total'], 'the append waits for the lock holder');
            History::prune($id, 3);
        });
        $this->finishChild($proc, $pipes);
        $this->assertSame([99, 5, 4, 3], array_column(History::list($id, 0, 25)['runs'], 'exitCode'));
    }

    public function testPruneWaitsForTheLockAndKeepsAnAppendMadeMeanwhile(): void
    {
        $id = $this->id('race-p');
        for ($i = 1; $i <= 5; $i++) {
            History::append($id, $this->rec(['exitCode' => $i]));
        }
        $proc  = null;
        $pipes = [];
        Util::withLock($this->lockPath($id), function () use ($id, &$proc, &$pipes): void {
            $proc = $this->startChild(sprintf('History::prune(%s, 3)', var_export($id, true)), $pipes);
            $this->assertSame(5, History::list($id, 0, 25)['total'], 'the prune waits for the lock holder');
            History::append($id, $this->rec(['exitCode' => 6]));
        });
        $this->finishChild($proc, $pipes);
        $this->assertSame([6, 5, 4], array_column(History::list($id, 0, 25)['runs'], 'exitCode'));
    }

    public function testPureDotsJobIdCollapsesToUnknown(): void
    {
        // SEC-01 parity: a pure-dots id can never address a file outside runs/.
        $this->assertStringEndsWith('/unknown.history.jsonl', History::path('..'));
        $this->assertStringNotContainsString('/..', History::path('..'));
    }

    public function testLastErrorIsKeptOnlyWhenPresentAndAfterEverythingElse(): void
    {
        $id = $this->id('lasterr');
        History::append($id, $this->rec(['state' => 'FAILED', 'exitCode' => 11, 'lastError' => 'rsync error: error in file IO (code 11)']));
        History::append($id, $this->rec(['lastError' => '']));
        [$clean, $failed] = History::list($id, 0, 25)['runs'];
        $this->assertArrayNotHasKey('lastError', $clean);
        $this->assertSame('lastError', array_key_last($failed));
        $this->assertSame('rsync error: error in file IO (code 11)', $failed['lastError']);
    }

    public function testRecordsAndJobIdsIncludeAJobWithOnlyHistory(): void
    {
        $id = $this->id('ids');
        History::append($id, $this->rec(['exitCode' => 1]));
        History::append($id, $this->rec(['exitCode' => 2]));
        $this->assertSame([2, 1], array_column(History::records($id), 'exitCode'));
        $this->assertContains($id, History::jobIds());
        $this->assertSame([], History::records('j-hist-none-' . bin2hex(random_bytes(3))));
    }
}

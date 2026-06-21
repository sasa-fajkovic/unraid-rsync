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
            History::delete($id);
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

    public function testDeleteRemovesHistory(): void
    {
        $id = $this->id('del');
        History::append($id, $this->rec());
        $this->assertSame(1, History::list($id, 0, 25)['total']);
        History::delete($id);
        $this->assertSame(0, History::list($id, 0, 25)['total']);
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
            ['startedAt', 'finishedAt', 'jobName', 'dryRun', 'trigger', 'state', 'exitCode', 'durationSec', 'logRef'],
            array_keys($r)
        );
    }

    public function testPureDotsJobIdCollapsesToUnknown(): void
    {
        // SEC-01 parity: a pure-dots id can never address a file outside runs/.
        $this->assertStringEndsWith('/unknown.history.jsonl', History::path('..'));
        $this->assertStringNotContainsString('/..', History::path('..'));
    }
}

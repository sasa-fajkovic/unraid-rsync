<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Drives source/scripts/ur-snapshot.sh through a real `sh -c` with the exact
 * command string Snapshot::remoteCommand() builds (what ssh hands the
 * receiver's login shell), against a temp tree. No ssh, no root.
 *
 * The helper refuses a root on the filesystem of `/`, so the tree lives under
 * /dev/shm when that is a different filesystem; otherwise these tests skip.
 * The helper needs GNU coreutils (stat -c, mv -T, readlink -f, sync -f), so
 * they also skip off Linux. Only `probe` checks for uid 0: as non-root it
 * must exit 17, and every other operation is tested directly.
 */
final class SnapshotHelperTest extends TestCase
{
    private const JOB     = 'job.app-1';
    private const CONTENT = '6f1c2a8e-3b4d-4c5e-9f00-112233445566';

    private string $base   = '';
    private string $drive  = '';
    private string $root   = '';
    private string $marker = '';

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('the helper needs a Linux receiver with GNU coreutils');
        }
        $shm = @stat('/dev/shm');
        $sys = @stat('/');
        if ($shm === false || $sys === false || $shm['dev'] === $sys['dev'] || !is_writable('/dev/shm')) {
            $this->markTestSkipped('no writable /dev/shm on a filesystem other than / (the helper refuses a root on /)');
        }
        $this->base   = '/dev/shm/ur-helper-' . getmypid() . '-' . bin2hex(random_bytes(4));
        // Every path below carries spaces, quotes, $, ;, backticks and non-ASCII.
        $this->drive  = $this->base . "/drive 1 it's \$x; `id` ü";
        $this->root   = $this->drive . '/snapshots/app data';
        $this->marker = $this->drive . '/.unraid-rsync-target';
        mkdir($this->root, 0700, true);
        file_put_contents($this->marker, self::CONTENT . "\n");
        file_put_contents($this->root . '/.ur-snapshot-root', self::JOB . "\n");
    }

    protected function tearDown(): void
    {
        if ($this->base !== '') {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /** @return array<int,string> */
    private function common(?string $root = null, ?string $marker = null, string $content = self::CONTENT, string $job = self::JOB): array
    {
        return [$root ?? $this->root, $marker ?? $this->marker, $content, $job];
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function sh(string $cmd): array
    {
        $p = proc_open(['sh', '-c', $cmd], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($p), 'stdout' => $out, 'stderr' => $err];
    }

    /**
     * Run an operation with the exact string Snapshot would send.
     *
     * @param array<int,string> $args
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function op(string $op, array $args): array
    {
        return self::sh(Snapshot::remoteCommand(Snapshot::helperScript(), $op, $args, false));
    }

    /**
     * Run the helper with arguments PHP would refuse, to test its own checks.
     *
     * @param array<int,string> $argv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private static function raw(array $argv): array
    {
        $parts = array_map('escapeshellarg', array_merge(['sh', '-c', Snapshot::helperScript(), 'ur-snapshot'], $argv));
        return self::sh(implode(' ', $parts));
    }

    /**
     * @param array<int,string> $args
     * @return array<string,mixed>
     */
    private function ok(string $op, array $args): array
    {
        $r = $this->op($op, $args);
        $this->assertSame(0, $r['exit'], "$op: " . $r['stderr']);
        $parsed = Snapshot::parseHelper($r['stdout'], $op);
        $this->assertTrue($parsed['ok'], (string) ($parsed['error'] ?? ''));
        return $parsed;
    }

    /** @param array<int,string> $args */
    private function refused(int $code, string $op, array $args, string $needle): void
    {
        $r = $this->op($op, $args);
        $this->assertSame($code, $r['exit'], "$op: " . $r['stderr'] . $r['stdout']);
        $this->assertStringContainsString($needle, $r['stderr']);
        $this->assertStringNotContainsString("\nok\n", $r['stdout']);
    }

    /** Commit a stamp the normal way, with one sub and some metadata. */
    private function snapshot(string $stamp, string $sub = 'appdata', array $meta = ['subs' => []]): void
    {
        $this->ok('mkstamp', array_merge($this->common(), [$stamp]));
        mkdir("{$this->root}/$stamp.inprogress/$sub");
        file_put_contents("{$this->root}/$stamp.inprogress/$sub/file", $stamp);
        $this->ok('commit', array_merge($this->common(), [$stamp, base64_encode((string) json_encode($meta))]));
    }

    /** @return array<string,mixed> */
    private function listing(): array
    {
        return $this->ok('list', $this->common());
    }

    // --- probe, check, list -------------------------------------------------------

    public function testProbe(): void
    {
        $r = $this->op('probe', []);
        if (posix_geteuid() !== 0) {
            $this->assertSame(17, $r['exit']);
            $this->assertSame('ur-snapshot 1' . "\n" . 'uid ' . posix_geteuid() . "\n", $r['stdout']);
            $this->assertStringContainsString('not running as root', $r['stderr']);
            return;
        }
        $this->assertSame(0, $r['exit'], $r['stderr']);
        $p = Snapshot::parseHelper($r['stdout'], 'probe');
        $this->assertTrue($p['ok']);
        $this->assertSame(0, $p['uid']);
        $this->assertEqualsWithDelta(time(), $p['now'], 5);
        $this->assertNull(Snapshot::checkSkew($p['now'], time()));
        $this->assertContains($p['ntp'], ['yes', 'no', 'unknown']);
        $this->assertIsBool($p['ionice']);
    }

    public function testCheckPrintsDf(): void
    {
        $df = $this->ok('check', $this->common())['df'];
        $this->assertGreaterThan(0, $df['size']);
        $this->assertGreaterThan(0, $df['avail']);
    }

    public function testListOfAnEmptyRoot(): void
    {
        $l = $this->listing();
        $this->assertSame([], $l['entries']);
        $this->assertSame('', $l['latest']);
        $this->assertFalse($l['hold']);
        $this->assertSame(0, $l['foreign']);
        $this->assertNull($l['meta']);
    }

    public function testListReportsDecoysWithoutNamingThem(): void
    {
        $this->snapshot('2026-10-20T030000Z');
        mkdir("{$this->root}/appdata");
        mkdir("{$this->root}/lost+found");
        mkdir("{$this->root}/snapshots");
        mkdir("{$this->root}/evil\nentry 2026-10-01T000000Z d 0 0");
        mkdir("{$this->root}/2026-10-21T030000Z.partial");
        mkdir("{$this->root}/2026-10-19T030000Z.inprogress");
        mkdir("{$this->root}/2026-10-18T030000Z.rolledback");
        file_put_contents("{$this->root}/2026-10-17T030000Z", '');
        symlink('2026-10-20T030000Z', "{$this->root}/2026-10-16T030000Z");

        $l = $this->listing();
        $this->assertSame(5, $l['foreign']);
        $this->assertSame(['2026-10-21T030000Z'], $l['stampish']);
        $types = array_column($l['entries'], 'type', 'name');
        ksort($types);
        $this->assertSame([
            '2026-10-16T030000Z'            => 'l',
            '2026-10-17T030000Z'            => 'f',
            '2026-10-18T030000Z.rolledback' => 'd',
            '2026-10-19T030000Z.inprogress' => 'd',
            '2026-10-20T030000Z'            => 'd',
        ], $types);
        $this->assertNotNull(Snapshot::checkMonotonic('2026-10-21T020000Z', array_merge(array_keys($types), $l['stampish'])));
    }

    // --- mkstamp and commit -----------------------------------------------------

    public function testMkstampCommitAndList(): void
    {
        $s = '2026-10-21T030000Z';
        $this->ok('mkstamp', array_merge($this->common(), [$s]));
        $this->assertDirectoryExists("{$this->root}/$s.inprogress");
        $this->assertSame('0700', substr(sprintf('%o', fileperms("{$this->root}/$s.inprogress")), -4));
        mkdir("{$this->root}/$s.inprogress/app-data");
        mkdir("{$this->root}/$s.inprogress/latest");

        $meta = ['subs' => ['app-data' => ['files' => 3, 'bytes' => 42]]];
        $c = $this->ok('commit', array_merge($this->common(), [$s, base64_encode((string) json_encode($meta))]));
        $this->assertArrayHasKey('df', $c);

        $this->assertDirectoryDoesNotExist("{$this->root}/$s.inprogress");
        $this->assertDirectoryExists("{$this->root}/$s/app-data");
        $this->assertSame('0600', substr(sprintf('%o', fileperms("{$this->root}/$s/.ur-snapshot.json")), -4));
        $this->assertSame($meta, json_decode((string) file_get_contents("{$this->root}/$s/.ur-snapshot.json"), true));
        $this->assertTrue(is_link("{$this->root}/latest"));
        $this->assertSame($s, readlink("{$this->root}/latest"));
        $this->assertSame([], glob("{$this->root}/.latest.*") ?: []);

        $l = $this->listing();
        $this->assertSame([['name' => $s, 'type' => 'd', 'keep' => false, 'meta' => true]], $l['entries']);
        $this->assertSame($s, $l['latest']);
        $this->assertSame(['app-data'], $l['subs']);
        $this->assertSame(['stamp' => $s, 'data' => $meta], $l['meta']);
    }

    public function testCommitSwapsLatestByRename(): void
    {
        $this->snapshot('2026-10-20T030000Z');
        $before = lstat("{$this->root}/latest");
        $this->snapshot('2026-10-21T030000Z');
        $after = lstat("{$this->root}/latest");
        $this->assertSame('2026-10-21T030000Z', readlink("{$this->root}/latest"));
        $this->assertNotSame($before['ino'], $after['ino'], 'a new link renamed over the old one');
        $this->assertSame([], glob("{$this->root}/.latest.*") ?: []);
    }

    public function testMkstampRefusesANameThatIsNotLater(): void
    {
        $this->snapshot('2026-10-20T030000Z');
        $this->refused(15, 'mkstamp', array_merge($this->common(), ['2026-10-20T030000Z']), 'does not sort after');
        $this->refused(15, 'mkstamp', array_merge($this->common(), ['2026-10-19T030000Z']), 'does not sort after');
        foreach (['2026-10-22T000000Z.rolledback', '2026-10-23T000000Z.partial', '2026-10-24T000000Z'] as $later) {
            if (str_ends_with($later, 'Z')) {
                file_put_contents("{$this->root}/$later", '');
            } else {
                mkdir("{$this->root}/$later");
            }
            $this->refused(15, 'mkstamp', array_merge($this->common(), ['2026-10-21T030000Z']), 'does not sort after');
        }
        $this->assertDirectoryDoesNotExist("{$this->root}/2026-10-21T030000Z.inprogress");
        $this->ok('mkstamp', array_merge($this->common(), ['2026-10-25T000000Z']));
    }

    public function testCommitRefusals(): void
    {
        $s = '2026-10-21T030000Z';
        $meta = base64_encode('{}');
        $this->refused(10, 'commit', array_merge($this->common(), [$s, $meta]), 'is not a directory');

        $this->ok('mkstamp', array_merge($this->common(), [$s]));
        mkdir("{$this->root}/$s");
        $this->refused(15, 'commit', array_merge($this->common(), [$s, $meta]), 'already exists');
        rmdir("{$this->root}/$s");

        mkdir("{$this->root}/latest");
        $this->refused(15, 'commit', array_merge($this->common(), [$s, $meta]), 'not a symlink');
        rmdir("{$this->root}/latest");

        mkdir("{$this->root}/$s.inprogress/.ur-snapshot.json/x", 0700, true);
        $this->refused(18, 'commit', array_merge($this->common(), [$s, $meta]), 'writing the metadata failed');
        $this->assertDirectoryExists("{$this->root}/$s.inprogress", 'a failed commit leaves the .inprogress');
        $this->assertFileDoesNotExist("{$this->root}/$s");
    }

    // --- delete -----------------------------------------------------------------

    public function testDeleteRemovesAnOldSnapshotAndKeepsSharedData(): void
    {
        $this->snapshot('2026-10-19T030000Z');
        $this->snapshot('2026-10-20T030000Z');
        $this->snapshot('2026-10-21T030000Z');
        $shared = "{$this->root}/2026-10-20T030000Z/appdata/shared";
        file_put_contents("{$this->root}/2026-10-19T030000Z/appdata/shared", 'kept data');
        link("{$this->root}/2026-10-19T030000Z/appdata/shared", $shared);

        $this->ok('delete', array_merge($this->common(), ['2026-10-19T030000Z']));
        $this->assertFileDoesNotExist("{$this->root}/2026-10-19T030000Z");
        $this->assertFileDoesNotExist("{$this->root}/2026-10-19T030000Z.deleting");
        $this->assertSame('kept data', file_get_contents($shared));
        $this->assertSame(1, stat($shared)['nlink']);
    }

    public function testDeleteFinishesALeftoverDeletingAndAStaleInprogress(): void
    {
        mkdir("{$this->root}/2026-10-01T030000Z.deleting/a/b", 0700, true);
        mkdir("{$this->root}/2026-10-02T030000Z.inprogress/a", 0700, true);
        $this->ok('delete', array_merge($this->common(), ['2026-10-01T030000Z.deleting']));
        $this->ok('delete', array_merge($this->common(), ['2026-10-02T030000Z.inprogress']));
        $this->assertSame([], glob("{$this->root}/2026-*") ?: []);
    }

    public function testDeleteRefusals(): void
    {
        $this->snapshot('2026-10-18T030000Z');
        $this->snapshot('2026-10-19T030000Z');
        $this->snapshot('2026-10-20T030000Z');
        $this->snapshot('2026-10-21T030000Z');
        $c = $this->common();

        $this->refused(16, 'delete', array_merge($c, ['2026-10-21T030000Z']), 'target of latest');

        unlink("{$this->root}/latest");
        symlink('2026-10-20T030000Z', "{$this->root}/latest");
        $this->refused(16, 'delete', array_merge($c, ['2026-10-20T030000Z']), 'target of latest');
        $this->refused(16, 'delete', array_merge($c, ['2026-10-21T030000Z']), 'newest complete');

        touch("{$this->root}/2026-10-19T030000Z/.ur-keep");
        $this->refused(16, 'delete', array_merge($c, ['2026-10-19T030000Z']), 'pinned');

        mkdir("{$this->root}/2026-10-17T030000Z.rolledback");
        $this->refused(16, 'delete', array_merge($c, ['2026-10-17T030000Z.rolledback']), 'rollback');

        file_put_contents("{$this->root}/2026-10-16T030000Z", '');
        $this->refused(16, 'delete', array_merge($c, ['2026-10-16T030000Z']), 'not a snapshot directory');
        symlink('2026-10-18T030000Z', "{$this->root}/2026-10-15T030000Z");
        $this->refused(16, 'delete', array_merge($c, ['2026-10-15T030000Z']), 'not a snapshot directory');
        $this->refused(16, 'delete', array_merge($c, ['2026-10-14T030000Z']), 'not a snapshot directory');

        mkdir("{$this->root}/2026-10-18T030000Z.deleting");
        $this->refused(15, 'delete', array_merge($c, ['2026-10-18T030000Z']), 'already exists');
        rmdir("{$this->root}/2026-10-18T030000Z.deleting");

        $this->ok('hold', array_merge($c, ['2026-10-21T030000Z']));
        $this->refused(16, 'delete', array_merge($c, ['2026-10-18T030000Z']), 'on hold');

        foreach (['2026-10-18T030000Z', '2026-10-19T030000Z', '2026-10-20T030000Z', '2026-10-21T030000Z', '2026-10-17T030000Z.rolledback'] as $name) {
            $this->assertDirectoryExists("{$this->root}/$name");
        }
        $this->assertFileExists("{$this->root}/2026-10-19T030000Z/.ur-keep");
    }

    // --- hold and pin -----------------------------------------------------------

    public function testHoldIsIdempotentAndNeverCleared(): void
    {
        $this->ok('hold', array_merge($this->common(), ['2026-10-20T030000Z']));
        $first = (string) file_get_contents("{$this->root}/.ur-hold");
        $this->assertStringStartsWith('2026-10-20T030000Z ', $first);
        $this->ok('hold', array_merge($this->common(), ['2026-10-21T030000Z']));
        $this->assertSame($first, file_get_contents("{$this->root}/.ur-hold"));
        $this->assertTrue($this->listing()['hold']);
    }

    public function testPinIsIdempotentAndOnlyForCompleteSnapshots(): void
    {
        $this->snapshot('2026-10-20T030000Z');
        $this->ok('pin', array_merge($this->common(), ['2026-10-20T030000Z']));
        $this->ok('pin', array_merge($this->common(), ['2026-10-20T030000Z']));
        $this->assertFileExists("{$this->root}/2026-10-20T030000Z/.ur-keep");
        $this->assertTrue($this->listing()['entries'][0]['keep']);

        mkdir("{$this->root}/2026-10-21T030000Z.inprogress");
        $this->refused(10, 'pin', array_merge($this->common(), ['2026-10-21T030000Z']), 'not a complete snapshot');
        symlink('2026-10-20T030000Z', "{$this->root}/2026-10-19T030000Z");
        $this->refused(10, 'pin', array_merge($this->common(), ['2026-10-19T030000Z']), 'not a complete snapshot');
    }

    // --- guard ------------------------------------------------------------------

    public function testRootChecks(): void
    {
        $this->refused(11, 'check', $this->common($this->drive . '/snapshots/missing'), 'missing or not a directory');
        $this->assertFileDoesNotExist($this->drive . '/snapshots/missing', 'the helper never creates the root');

        symlink($this->root, $this->drive . '/link');
        $this->refused(11, 'check', $this->common($this->drive . '/link'), 'missing or not a directory');

        symlink($this->drive . '/snapshots', $this->drive . '/alias');
        $this->refused(11, 'list', $this->common($this->drive . '/alias/app data'), 'not canonical');
    }

    public function testMarkerChecks(): void
    {
        $this->refused(12, 'check', $this->common(null, $this->drive . '/missing'), 'missing or not a regular file');
        $this->refused(12, 'check', $this->common(null, null, 'another-uuid'), 'expected content');

        symlink($this->marker, $this->drive . '/marker-link');
        $this->refused(12, 'check', $this->common(null, $this->drive . '/marker-link'), 'missing or not a regular file');

        file_put_contents($this->marker, self::CONTENT . "\r\nsecond line\n");
        $this->ok('check', $this->common());
    }

    public function testSentinelChecks(): void
    {
        $this->refused(14, 'check', $this->common(null, null, self::CONTENT, 'another-job'), 'names another job');
        unlink($this->root . '/.ur-snapshot-root');
        $this->refused(14, 'list', $this->common(), 'missing or not a regular file');
        $this->assertFileDoesNotExist($this->root . '/.ur-snapshot-root', 'the helper never creates the sentinel');
        symlink($this->marker, $this->root . '/.ur-snapshot-root');
        $this->refused(14, 'list', $this->common(), 'missing or not a regular file');
    }

    public function testAMarkerOnAnotherFilesystemIsRefused(): void
    {
        $tmp = sys_get_temp_dir();
        if (stat($tmp)['dev'] === stat('/dev/shm')['dev']) {
            $this->markTestSkipped("$tmp is on the same filesystem as /dev/shm");
        }
        $other = $tmp . '/ur-marker-' . bin2hex(random_bytes(4));
        file_put_contents($other, self::CONTENT . "\n");
        try {
            $this->refused(13, 'check', $this->common(null, $other), 'different filesystems');
        } finally {
            unlink($other);
        }
    }

    public function testARootOnTheFilesystemOfSlashIsRefused(): void
    {
        $tmp = sys_get_temp_dir();
        if (stat($tmp)['dev'] !== stat('/')['dev']) {
            $this->markTestSkipped("$tmp is not on the filesystem of /, so no tree there can be built");
        }
        $tree = $tmp . '/ur-sysfs-' . bin2hex(random_bytes(4));
        mkdir($tree . '/snapshots/app', 0700, true);
        file_put_contents($tree . '/.unraid-rsync-target', self::CONTENT . "\n");
        file_put_contents($tree . '/snapshots/app/.ur-snapshot-root', self::JOB . "\n");
        try {
            $real = (string) realpath($tree);
            $this->refused(13, 'check', $this->common($real . '/snapshots/app', $real . '/.unraid-rsync-target'), 'filesystem of /');
            $this->refused(13, 'mkstamp', array_merge($this->common($real . '/snapshots/app', $real . '/.unraid-rsync-target'), ['2026-10-21T030000Z']), 'filesystem of /');
            $this->assertFileDoesNotExist($real . '/snapshots/app/2026-10-21T030000Z.inprogress');
        } finally {
            exec('rm -rf ' . escapeshellarg($tree));
        }
    }

    // --- argument checks (bypassing PHP's own) -------------------------------------

    public function testTheHelperRevalidatesEveryArgument(): void
    {
        $c = $this->common();
        $cases = [
            'no operation'       => [[], 'usage'],
            'unknown operation'  => [['format'], 'unknown operation'],
            'probe with args'    => [['probe', 'x'], 'takes 0 arguments'],
            'too few'            => [array_merge(['check'], array_slice($c, 0, 3)), 'takes 4 arguments'],
            'too many'           => [array_merge(['check'], $c, ['x']), 'takes 4 arguments'],
            'relative root'      => [['check', 'snapshots/app', $c[1], $c[2], $c[3]], 'not an absolute path'],
            'root slash'         => [['check', '/', $c[1], $c[2], $c[3]], 'not a normalised path'],
            'trailing slash'     => [['check', $c[0] . '/', $c[1], $c[2], $c[3]], 'not a normalised path'],
            'double slash'       => [['check', '/dev//shm', $c[1], $c[2], $c[3]], 'not a normalised path'],
            'dot segment'        => [['check', '/dev/./shm', $c[1], $c[2], $c[3]], 'not a normalised path'],
            'dotdot segment'     => [['check', $c[0] . '/../x', $c[1], $c[2], $c[3]], 'not a normalised path'],
            'trailing dotdot'    => [['check', $c[0] . '/..', $c[1], $c[2], $c[3]], 'not a normalised path'],
            'relative marker'    => [['check', $c[0], 'marker', $c[2], $c[3]], 'not an absolute path'],
            'empty content'      => [['check', $c[0], $c[1], '', $c[3]], 'is empty'],
            'newline in content' => [['check', $c[0], $c[1], "a\nb", $c[3]], 'control character'],
            'tab in job id'      => [['check', $c[0], $c[1], $c[2], "j\tb"], 'control character'],
            'CR in root'         => [['check', $c[0] . "\r", $c[1], $c[2], $c[3]], 'control character'],
            'bad stamp'          => [array_merge(['mkstamp'], $c, ['2026-10-21T0300Z']), 'not a stamp'],
            'suffixed stamp'     => [array_merge(['pin'], $c, ['2026-10-21T030000Z.inprogress']), 'not a stamp'],
            'foreign delete'     => [array_merge(['delete'], $c, ['appdata']), 'not a snapshot name'],
            'traversal delete'   => [array_merge(['delete'], $c, ['../2026-10-21T030000Z']), 'not a snapshot name'],
            'control in op arg'  => [array_merge(['hold'], $c, ["2026-10-21T030000Z\n"]), 'control character'],
            'bad meta'           => [array_merge(['commit'], $c, ['2026-10-21T030000Z', 'e30=;id']), 'not base64'],
            'empty meta'         => [array_merge(['commit'], $c, ['2026-10-21T030000Z', '']), 'is empty'],
        ];
        foreach ($cases as $label => [$argv, $needle]) {
            $r = self::raw($argv);
            $this->assertSame(10, $r['exit'], "$label: {$r['stderr']}");
            $this->assertStringContainsString($needle, $r['stderr'], $label);
            $this->assertSame('', $r['stdout'], $label);
        }
    }
}

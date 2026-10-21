<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Runner::run in snapshot mode with real rsync and the real helper on a temp
 * tree. Only ssh is taken out: Snapshot::$remoteRunner runs the exact command
 * string ssh would hand the receiver through `sh -c`, and Rsync::$runner drops
 * `-e`, `--rsync-path` and the user@host: prefix before running the real
 * rsync. Local sources are mapped from /mnt/user/e2e-* (the guardrails demand
 * /mnt) to the temp tree.
 *
 * The helper refuses a root on the filesystem of /, so the tree lives under
 * /dev/shm; it needs GNU coreutils, so this runs on Linux only. probe insists
 * on uid 0: as non-root its reply is synthesised (SnapshotHelperTest covers
 * the real one); as root (CI containers) the real probe runs. Either way its
 * `now` is the test clock, which moves a day per run. /dev/shm is far smaller
 * than the free-space floor, so `df` replies report a large filesystem.
 */
final class SnapshotEndToEndTest extends TestCase
{
    private const JOB = 'j-e2e';
    private const DAY = 86400;

    private string $base = '';
    private string $root = '';
    private string $src  = '';
    private string $rt   = '';
    /** 2026-10-01T03:00:00Z */
    private int $clock = 1790823600;
    /** @var array<int,string> helper ops, in order */
    private array $ops = [];

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
        if (!is_executable('/usr/bin/rsync')) {
            $this->markTestSkipped('needs rsync at /usr/bin/rsync');
        }

        $this->base = '/dev/shm/ur-e2e-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $drive      = $this->base . '/drive';
        $this->root = $drive . '/snapshots/e2e';
        $this->src  = $this->base . '/src';
        mkdir($this->root, 0700, true);
        file_put_contents("$drive/.unraid-rsync-target", "uuid-e2e\n");
        file_put_contents($this->root . '/.ur-snapshot-root', self::JOB . "\n");
        foreach (['a', 'b'] as $sub) {
            mkdir("{$this->src}/$sub/dir with space", 0755, true);
            for ($i = 0; $i < 5; $i++) {
                file_put_contents("{$this->src}/$sub/f$i.txt", str_repeat("$sub$i", 100));
            }
            file_put_contents("{$this->src}/$sub/dir with space/it's ü.txt", 'quoted');
        }

        $this->rt = $this->base . '/rt';
        Ssh::$runtimeBase = $this->rt;
        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'k1', 'privateKey' => "KEY\n", 'publicKey' => 'ssh-ed25519 AAAA fake', 'fingerprint' => 'SHA256:fake'];
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-e2e', 'name' => 'e2e', 'host' => 'h.example', 'username' => 'root',
            'authMethod' => 'KEY', 'keyId' => 'k-1', 'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
        ]);
        Credentials::save($creds);

        $config = Config::defaults();
        $config['jobs'] = [array_merge(Config::defaultJob(), [
            'id' => self::JOB, 'name' => 'e2e', 'connectionId' => 'c-e2e', 'notifyMode' => 'off',
            'targetMarker' => "$drive/.unraid-rsync-target", 'targetMarkerContent' => 'uuid-e2e',
            'pairs' => [
                ['local' => '/mnt/user/e2e-a/', 'remote' => '', 'sub' => 'a', 'seed' => true],
                ['local' => '/mnt/user/e2e-b/', 'remote' => '', 'sub' => 'b', 'seed' => true],
            ],
            'rsyncOptions' => Config::mergeRsyncOptions(['archive' => true, 'times' => true]),
            'snapshot' => array_merge(Config::defaultSnapshot(), [
                'enabled' => true, 'root' => $this->root, 'keepDaily' => 2, 'keepWeekly' => 0, 'pruneDryRun' => false,
            ]),
        ])];
        Config::save($config);
        RunState::clear(self::JOB);
        RunState::clearAbort(self::JOB);
        @unlink(History::path(self::JOB));

        Snapshot::$nowProvider = fn(): int => $this->clock;
        Snapshot::$remoteRunner = fn(array $argv, ?array $env, int $t, ?callable $stop): array => $this->receiver($argv);
        Rsync::$rsyncPathOverride = '/usr/bin/rsync';
        Rsync::$runner = fn(array $argv, callable $out, ?array $env = null): int => $this->rsync($argv, $out, $env);
        Runner::$scanDir = fn(string $p): array|false => @scandir($this->map($p));
    }

    protected function tearDown(): void
    {
        Snapshot::$nowProvider    = null;
        Snapshot::$remoteRunner   = null;
        Rsync::$runner            = null;
        Rsync::$rsyncPathOverride = null;
        Runner::$scanDir          = null;
        Ssh::$runtimeBase         = '/tmp/unraid.rsync';
        if ($this->base !== '') {
            Credentials::save(Credentials::defaults());
            @unlink(History::path(self::JOB));
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    private function map(string $p): string
    {
        return (string) preg_replace('#^/mnt/user/e2e-#', $this->src . '/', $p);
    }

    /** @param array<int,string> $argv */
    private function rsync(array $argv, callable $out, ?array $env): int
    {
        $real = [];
        for ($i = 0; $i < count($argv); $i++) {
            if ($argv[$i] === '-e') {
                $i++;
                continue;
            }
            if (str_starts_with($argv[$i], '--rsync-path=')) {
                continue;
            }
            $real[] = $this->map((string) preg_replace('#^root@h\.example:#', '', $argv[$i]));
        }
        $self = Rsync::$runner;
        Rsync::$runner = null;
        try {
            return Rsync::run($real, $out, $env);
        } finally {
            Rsync::$runner = $self;
        }
    }

    /**
     * @param array<int,string> $argv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function receiver(array $argv): array
    {
        $cmd = (string) end($argv);
        preg_match("/'ur-snapshot' '(\\w+)'/", $cmd, $m);
        $this->ops[] = $m[1];
        if ($m[1] === 'probe' && posix_geteuid() !== 0) {
            return ['exit' => 0, 'stdout' => "ur-snapshot 1\nuid 0\nnow {$this->clock}\nntp unknown\nionice no\nok\n", 'stderr' => ''];
        }
        $p = proc_open(['sh', '-c', $cmd], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($p);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($p);
        if ($m[1] === 'probe') {
            $stdout = (string) preg_replace('/^now \d+$/m', "now {$this->clock}", $stdout);
        }
        // /dev/shm is far below the 10 GiB free-space floor; keep `used` real.
        $stdout = (string) preg_replace('/^df \d+ (\d+) \d+$/m', 'df 1000000000 $1 900000000', $stdout);
        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array{state:string,exitCode:int,runLog:string,reason?:string} */
    private function runDay(bool $dryRun = false): array
    {
        $this->clock += self::DAY;
        $this->ops = [];
        $res = Runner::run(self::JOB, $dryRun);
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], (string) file_get_contents($res['runLog']));
        return $res;
    }

    private function stampNow(): string
    {
        return Snapshot::stamp(new DateTimeImmutable('@' . $this->clock));
    }

    /** @return array<int,string> */
    private function names(): array
    {
        $n = array_values(array_diff(scandir($this->root) ?: [], ['.', '..', '.ur-snapshot-root']));
        sort($n);
        return $n;
    }

    /** @return array{0:int,1:int} inode and link count */
    private function ino(string $path): array
    {
        clearstatcache();
        $s = stat($path);
        $this->assertIsArray($s, $path);
        return [$s['ino'], $s['nlink']];
    }

    public function testFourRunsLinkUnchangedFilesPruneAndCleanUp(): void
    {
        // Run 1: the seed, a full copy.
        $this->runDay();
        $s1 = $this->stampNow();
        $this->assertSame([$s1, 'latest'], $this->names());
        $this->assertSame($s1, readlink($this->root . '/latest'));
        $this->assertSame('quoted', file_get_contents("{$this->root}/$s1/b/dir with space/it's ü.txt"));
        $meta = json_decode((string) file_get_contents("{$this->root}/$s1/.ur-snapshot.json"), true);
        $this->assertSame(6, $meta['subs']['a']['files']);
        $this->assertSame(['exit', 'files', 'bytes'], array_keys($meta['subs']['b']));
        $this->assertSame([1, 1], [$this->ino("{$this->root}/$s1/a/f0.txt")[1], $this->ino("{$this->root}/$s1/a/f1.txt")[1]]);

        // Run 2: one changed file, one new file; the rest is hard-linked to run 1.
        file_put_contents("{$this->src}/a/f1.txt", 'changed');
        touch("{$this->src}/a/f1.txt", $this->clock + 5);
        file_put_contents("{$this->src}/a/new.txt", 'new');
        $res = $this->runDay();
        $s2 = $this->stampNow();
        $this->assertStringContainsString("Pair #1 (a): $s1/a exists, so its seed flag has no effect and can be cleared.", (string) file_get_contents($res['runLog']));
        [$ino1, $links] = $this->ino("{$this->root}/$s1/a/f0.txt");
        $this->assertSame([$ino1, 2], $this->ino("{$this->root}/$s2/a/f0.txt"), 'an unchanged file shares its inode');
        $this->assertSame(2, $links);
        $this->assertNotSame($this->ino("{$this->root}/$s1/a/f1.txt")[0], $this->ino("{$this->root}/$s2/a/f1.txt")[0]);
        $this->assertSame('changed', file_get_contents("{$this->root}/$s2/a/f1.txt"));
        $this->assertSame($s2, readlink($this->root . '/latest'));
        $this->assertSame(7, json_decode((string) file_get_contents("{$this->root}/$s2/.ur-snapshot.json"), true)['subs']['a']['files']);

        // A dry run in between writes nothing, even though its destination
        // (<stamp>.inprogress) does not exist.
        $this->runDay(true);
        $this->assertSame([$s1, $s2, 'latest'], $this->names());
        $this->assertSame(['probe', 'check', 'list'], $this->ops);

        // Leftovers from crashed runs, dated before every snapshot.
        foreach (['2026-09-01T000000Z.deleting', '2026-09-02T000000Z.inprogress', '2026-09-03T000000Z.inprogress'] as $d) {
            mkdir("{$this->root}/$d/a", 0700, true);
            file_put_contents("{$this->root}/$d/a/junk", 'x');
        }

        // Run 3: cleanup removes the .deleting and the older stale run, keeps
        // the newest stale one; prune (keepDaily 2) removes run 1.
        $this->runDay();
        $s3 = $this->stampNow();
        $this->assertSame(['2026-09-03T000000Z.inprogress', $s2, $s3, 'latest'], $this->names());
        $this->assertSame(['probe', 'check', 'list', 'delete', 'delete', 'mkstamp', 'check', 'commit', 'list', 'delete'], $this->ops);
        $this->assertSame([$ino1, 2], $this->ino("{$this->root}/$s2/a/f0.txt"), 'run 1 is gone, its data lives on in run 2 and 3');
        $h = History::list(self::JOB, 0, 1)['runs'][0];
        $this->assertTrue($h['committed']);
        $this->assertSame(1, $h['pruned']);

        // Run 4: prune removes run 2; the newest stale run is still kept.
        $this->runDay();
        $s4 = $this->stampNow();
        $this->assertSame(['2026-09-03T000000Z.inprogress', $s3, $s4, 'latest'], $this->names());
        $this->assertSame($s4, readlink($this->root . '/latest'));
        $this->assertSame([$ino1, 2], $this->ino("{$this->root}/$s4/a/f0.txt"));
        $this->assertSame('changed', file_get_contents("{$this->root}/$s4/a/f1.txt"));
    }

    public function testStatsAreCapturedAtEveryLogLevelWithRealRsync(): void
    {
        $this->runDay();
        foreach (Job::LOG_LEVELS as $level) {
            $config = Config::load();
            $config['jobs'][0]['logLevel'] = $level;
            Config::save($config);
            $this->runDay();
            $meta = json_decode((string) file_get_contents($this->root . '/' . $this->stampNow() . '/.ur-snapshot.json'), true);
            $this->assertSame(6, $meta['subs']['a']['files'], "files at $level");
            $this->assertSame(6, $meta['subs']['b']['files'], "files at $level");
            $this->assertIsInt($meta['subs']['a']['bytes'], "bytes at $level");
            $this->assertGreaterThan(0, $meta['subs']['a']['bytes'], "bytes at $level");
        }
    }
}

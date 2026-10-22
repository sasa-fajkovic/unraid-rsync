<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runner::run in snapshot mode (docs/design/snapshot-mode.md §4, §6), against
 * a fake receiver: Snapshot::$remoteRunner answers every helper call from an
 * in-memory model of the snapshot root, Rsync::$runner fakes the marker fetch,
 * the readability check and the transfers, and Runner::$scanDir fakes the
 * local sources. No ssh, no rsync, no real notify. SnapshotEndToEndTest runs
 * the same sequence with real rsync and the real helper.
 */
final class SnapshotRunnerTest extends TestCase
{
    private const JOB    = 'j-snap';
    private const ROOT   = '/srv/backup/snapshots/snap';
    private const MARKER = '/srv/backup/.unraid-rsync-target';
    private const DAY    = 86400;

    /** 2026-10-21T03:00:00Z */
    private int $clock = 1792551600;

    /** @var array<int,string> */
    private array $trace = [];
    /** @var array<int,array<int,string>> transfer argvs */
    private array $transfers = [];
    /** @var array<string,array<string,string>> hook text => env */
    private array $hookEnv = [];
    /** @var array<int,string> */
    private array $notified = [];
    /** @var array<string,mixed>|null in-flight record seen by the pre-hook */
    private ?array $inflight = null;

    /**
     * The receiver: dirs (name => keep, meta json|null, subs), hold, latest,
     * df [size, used, avail] in KB, now, plus scripted failures.
     *
     * @var array<string,mixed>
     */
    private array $rx = [];

    /** @var array<string,int> op => exit code to fail with */
    private array $failOp = [];
    /** @var array<string,int> sub => rsync exit */
    private array $pairExit = [];
    /** @var array<string,array{0:int,1:int|string}> sub => [regular files, bytes as rsync prints them] */
    private array $srcStats = [];
    /** @var array<string,array<int,string>|false> local path => scandir() */
    private array $sources = [];
    /** df used grows by this much per transferred pair (KB). */
    private int $transferKB = 500;
    private int $readExit = 0;
    private string $markerContent = "uuid-1\n";
    /** @var (callable(string):void)|null called during each transfer with its sub */
    private $duringTransfer = null;

    private string $rt = '';
    private string $fakeBin = '';

    protected function setUp(): void
    {
        $this->rt = sys_get_temp_dir() . '/ur-snaprun-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Ssh::$runtimeBase = $this->rt;
        $this->fakeBin = $this->rt . '-notify';
        file_put_contents($this->fakeBin, "#!/bin/sh\nexit 0\n");
        chmod($this->fakeBin, 0755);
        Notify::$notifyPath = $this->fakeBin;
        Notify::$runner = function (string $command): int {
            $this->notified[] = $command;
            return 0;
        };

        $this->rx = [
            'dirs'   => [],
            'hold'   => false,
            'latest' => '',
            'df'     => [100000000, 20000000, 80000000],
            'now'    => null,
            'guard'  => 0,
        ];
        Snapshot::$nowProvider  = fn(): int => $this->clock;
        Snapshot::$remoteRunner = fn(array $argv, ?array $env, int $timeout, ?callable $stop): array => $this->receiver($argv, $timeout, $stop);
        Rsync::$rsyncPathOverride = PHP_BINARY;
        Rsync::$runner = fn(array $argv, callable $out): int => $this->rsync($argv, $out);
        Runner::$pidProvider = fn(): int => 12345;
        Runner::$hookRunner = function (string $hook, array $env, $onOutput): int {
            $this->trace[] = "hook:$hook";
            $this->hookEnv[$hook] = $env;
            if ($hook === 'PRE') {
                $this->inflight = RunState::readInflight(self::JOB);
            }
            return 0;
        };
        Runner::$scanDir = fn(string $p): array|false => $this->sources[$p] ?? ['.', '..', 'file'];

        Config::save(Config::defaults());
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id' => 'k-1', 'name' => 'k1', 'privateKey' => "KEY\n",
            'publicKey' => 'ssh-ed25519 AAAA fake', 'fingerprint' => 'SHA256:fake',
        ];
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-key', 'name' => 'ckey', 'host' => 'h.example', 'username' => 'backup', 'useSudo' => true,
            'authMethod' => 'KEY', 'keyId' => 'k-1', 'remoteHostKey' => 'h.example ssh-ed25519 AAAAhostkey',
        ]);
        Credentials::save($creds);
        RunState::clear(self::JOB);
        RunState::clearAbort(self::JOB);
        @unlink(History::path(self::JOB));
    }

    protected function tearDown(): void
    {
        TestSeams::reset();
        Credentials::save(Credentials::defaults());
        RunState::clearAbort(self::JOB);
        @unlink(History::path(self::JOB));
        @unlink($this->fakeBin);
        exec('rm -rf ' . escapeshellarg($this->rt));
    }

    // --- rig ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $overrides job fields
     * @param array<string,mixed> $snap      snapshot block fields
     */
    private function saveJob(array $overrides = [], array $snap = []): void
    {
        $config = Config::load();
        $config['jobs'] = [array_merge(Config::defaultJob(), [
            'id' => self::JOB, 'name' => 'snap', 'connectionId' => 'c-key',
            'preHook' => 'PRE', 'postHook' => 'POST', 'notifyMode' => 'always',
            'targetMarker' => self::MARKER, 'targetMarkerContent' => 'uuid-1',
            'pairs' => [
                ['local' => '/mnt/user/a/', 'remote' => '', 'sub' => 'a'],
                ['local' => '/mnt/user/b/', 'remote' => '', 'sub' => 'b'],
            ],
            'rsyncOptions' => Config::mergeRsyncOptions([
                'archive' => true, 'times' => true, 'delete' => true, 'maxDelete' => '50', 'remoteRsyncPath' => '/usr/bin/rsync',
            ]),
            'snapshot' => array_merge(Config::defaultSnapshot(), ['enabled' => true, 'root' => self::ROOT], $snap),
        ], $overrides)];
        Config::save($config);
    }

    private function stamp(int $daysAgo = 0, int $epoch = 0): string
    {
        return Snapshot::stamp(new DateTimeImmutable('@' . (($epoch ?: $this->clock) - $daysAgo * self::DAY)));
    }

    /** A committed snapshot on the receiver. */
    private function seedSnapshot(string $name, array $subs = ['a', 'b'], ?array $meta = null, bool $keep = false): void
    {
        $this->rx['dirs'][$name] = ['keep' => $keep, 'meta' => $meta === null ? null : (string) json_encode($meta), 'subs' => $subs];
        $complete = array_filter(array_keys($this->rx['dirs']), fn($n) => Snapshot::classify($n) === 'complete');
        sort($complete);
        $this->rx['latest'] = (string) end($complete);
    }

    /** @return array{state:string,exitCode:int,runLog:string,reason?:string} */
    private function runJob(bool $dryRun = false, string $trigger = 'manual'): array
    {
        $this->trace = [];
        $this->transfers = [];
        $this->hookEnv = [];
        $this->notified = [];
        return Runner::run(self::JOB, $dryRun, $trigger);
    }

    private function log(array $res): string
    {
        return (string) file_get_contents($res['runLog']);
    }

    /** The helper calls in the trace, without the rest. */
    private function helperCalls(): array
    {
        return array_values(array_filter($this->trace, fn($t) => str_starts_with($t, 'helper:')));
    }

    private function importance(): string
    {
        $this->assertCount(1, $this->notified);
        preg_match("/'-i' '(\\w+)'/", $this->notified[0], $m);
        return $m[1] ?? '';
    }

    /** @return array<string,mixed> */
    private function lastHistory(): array
    {
        return History::list(self::JOB, 0, 1)['runs'][0];
    }

    /** @return array<int,string> names currently in the fake root */
    private function names(): array
    {
        $n = array_keys($this->rx['dirs']);
        sort($n);
        return $n;
    }

    /**
     * @param array<int,string> $argv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function receiver(array $argv, int $timeout, ?callable $stop): array
    {
        $cmd = (string) end($argv);
        $this->assertSame('backup@h.example', $argv[count($argv) - 2]);
        $this->assertSame(['-o', 'LogLevel=ERROR', '--'], array_slice($argv, -5, 3));
        $this->assertStringStartsWith("'sudo' '-n' 'sh' '-c' '#!/bin/sh", $cmd, 'useSudo from the connection');
        $pos = strpos($cmd, "'ur-snapshot' ");
        preg_match_all("/'((?:[^']|'\\\\'')*)'/", substr($cmd, (int) $pos), $m);
        $words = array_map(fn($w) => str_replace("'\\''", "'", $w), $m[1]);
        $op    = $words[1];
        $args  = array_slice($words, 2);
        if ($op !== 'probe') {
            $this->assertSame([self::ROOT, self::MARKER, 'uuid-1', self::JOB], array_slice($args, 0, 4));
            $args = array_slice($args, 4);
        }
        $this->trace[] = 'helper:' . $op . ($op === 'delete' || $op === 'pin' ? ':' . $args[0] : '');
        $expected = ['commit' => Snapshot::COMMIT_TIMEOUT_SEC, 'delete' => 0][$op] ?? Snapshot::HELPER_TIMEOUT_SEC;
        $this->assertSame($expected, $timeout, "timeout of $op");
        $this->assertSame($op === 'delete', $stop !== null, "only delete is stoppable ($op)");

        $fail = static fn(int $code, string $why): array => ['exit' => $code, 'stdout' => "ur-snapshot 1\n", 'stderr' => "ur-snapshot: $why\n"];
        if (isset($this->failOp[$op])) {
            return $fail($this->failOp[$op], "$op failed on purpose");
        }
        if ($op !== 'probe' && $this->rx['guard'] !== 0) {
            return $fail($this->rx['guard'], 'guard refused');
        }
        $df  = 'df ' . implode(' ', $this->rx['df']);
        $out = [];
        switch ($op) {
            case 'probe':
                $out = ['uid 0', 'now ' . ($this->rx['now'] ?? $this->clock), 'ntp yes', 'ionice yes'];
                break;
            case 'check':
                $out = [$df];
                break;
            case 'list':
                $names = $this->names();
                foreach ($names as $n) {
                    $d = $this->rx['dirs'][$n];
                    $out[] = sprintf('entry %s d %d %d', $n, $d['keep'] ? 1 : 0, $d['meta'] !== null ? 1 : 0);
                }
                $out[] = 'latest ' . ($this->rx['latest'] !== '' ? $this->rx['latest'] : 'none');
                $out[] = 'hold ' . ($this->rx['hold'] ? 1 : 0);
                $out[] = 'foreign 0';
                $complete = array_values(array_filter($names, fn($n) => Snapshot::classify($n) === 'complete'));
                $newest = (string) end($complete);
                if ($newest !== '') {
                    foreach ($this->rx['dirs'][$newest]['subs'] as $s) {
                        $out[] = "sub $s";
                    }
                    if ($this->rx['dirs'][$newest]['meta'] !== null) {
                        $out[] = "meta $newest " . base64_encode($this->rx['dirs'][$newest]['meta']);
                    }
                }
                $out[] = $df;
                break;
            case 'mkstamp':
                if (isset($this->rx['dirs'][$args[0] . '.inprogress'])) {
                    return $fail(15, 'exists');
                }
                $this->rx['dirs'][$args[0] . '.inprogress'] = ['keep' => false, 'meta' => null, 'subs' => []];
                break;
            case 'commit':
                $ip = $args[0] . '.inprogress';
                $this->assertArrayHasKey($ip, $this->rx['dirs']);
                $d = $this->rx['dirs'][$ip];
                unset($this->rx['dirs'][$ip]);
                $d['meta'] = (string) base64_decode($args[1], true);
                $this->rx['dirs'][$args[0]] = $d;
                $this->rx['latest'] = $args[0];
                $out = [$df];
                break;
            case 'delete':
                $name = $args[0];
                if ($this->rx['hold'] || !isset($this->rx['dirs'][$name]) || $this->rx['dirs'][$name]['keep'] || $name === $this->rx['latest']) {
                    return $fail(16, "$name is protected");
                }
                unset($this->rx['dirs'][$name]);
                break;
            case 'hold':
                $this->rx['hold'] = true;
                break;
            case 'pin':
                $this->rx['dirs'][$args[0]]['keep'] = true;
                break;
        }
        return ['exit' => 0, 'stdout' => "ur-snapshot 1\n" . implode('', array_map(fn($l) => "$l\n", $out)) . "ok\n", 'stderr' => ''];
    }

    /** @param array<int,string> $argv */
    private function rsync(array $argv, callable $out): int
    {
        if (in_array('--max-size=4K', $argv, true)) {
            $this->trace[] = 'marker';
            file_put_contents((string) end($argv), $this->markerContent);
            return 0;
        }
        if (in_array('--list-only', $argv, true)) {
            $this->trace[] = 'readable';
            $this->assertSame(['--', 'backup@h.example:' . self::ROOT . '/'], array_slice($argv, -2));
            $this->assertContains('--rsync-path=/usr/bin/rsync', $argv);
            return $this->readExit;
        }
        $this->transfers[] = $argv;
        $dest = (string) end($argv);
        $this->assertSame(1, preg_match('#^backup@h\.example:' . preg_quote(self::ROOT, '#') . '/([^/]+)\.inprogress/([^/]+)/$#', $dest, $m), $dest);
        [, $stamp, $sub] = $m;
        $this->trace[] = "rsync:$sub";
        if ($this->duringTransfer !== null) {
            ($this->duringTransfer)($sub);
        }
        if (!in_array('--dry-run', $argv, true)) {
            $this->assertArrayHasKey("$stamp.inprogress", $this->rx['dirs'], 'transfers go into the stamp mkstamp created');
            $this->rx['dirs']["$stamp.inprogress"]['subs'][] = $sub;
            $this->rx['df'][1] += $this->transferKB;
        }
        [$files, $bytes] = $this->srcStats[$sub] ?? [10, 1000];
        $out("sending incremental file list\n\nNumber of files: " . ($files + 1) . " (reg: $files, dir: 1)\n"
            . "Total file size: $bytes bytes\nTotal transferred file size: 0 bytes\n\nsent 1 bytes  received 2 bytes\n");
        return $this->pairExit[$sub] ?? 0;
    }

    // --- the happy path ----------------------------------------------------------

    public function testHappyPathRunsEveryStepInOrder(): void
    {
        $this->saveJob(snap: ['keepDaily' => 2, 'keepWeekly' => 0, 'pruneDryRun' => false]);
        $meta = ['subs' => ['a' => ['files' => 10, 'bytes' => 1000], 'b' => ['files' => 10, 'bytes' => 1000]]];
        foreach ([3, 2, 1] as $ago) {
            $this->seedSnapshot($this->stamp($ago), meta: $meta);
        }
        $old = $this->stamp(5);
        $this->rx['dirs']["$old.deleting"]   = ['keep' => false, 'meta' => null, 'subs' => []];
        $this->rx['dirs']["$old.inprogress"] = ['keep' => false, 'meta' => null, 'subs' => ['a']];
        $stale = Snapshot::stamp(new DateTimeImmutable('@' . ($this->clock - 3600)));
        $this->rx['dirs']["$stale.inprogress"] = ['keep' => false, 'meta' => null, 'subs' => ['a']];
        $stamp = $this->stamp();

        $res = $this->runJob();

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));
        $this->assertArrayNotHasKey('reason', $res);
        $this->assertSame([
            'marker', 'helper:probe', 'helper:check', 'helper:list', 'readable',
            "helper:delete:$old.deleting", "helper:delete:$old.inprogress", 'helper:check',
            'hook:PRE', 'helper:mkstamp', 'rsync:a', 'rsync:b', 'helper:check', 'helper:commit', 'hook:POST',
            'helper:list', 'helper:delete:' . $this->stamp(3), 'helper:delete:' . $this->stamp(2),
        ], $this->trace);
        $this->assertSame([$this->stamp(1), "$stale.inprogress", $stamp], $this->names());
        $this->assertSame($stamp, $this->rx['latest']);

        // The transfer: forced-off options, stats2 and both link-dests.
        $argv = $this->transfers[0];
        foreach (['--delete', '--mkpath', '--max-delete=50', '-H'] as $flag) {
            $this->assertNotContains($flag, $argv);
        }
        $this->assertContains('--rsync-path=/usr/bin/rsync', $argv);
        $prev = $this->stamp(1);
        $this->assertSame(
            ['--info=stats2', '--link-dest=' . self::ROOT . "/$prev/a", '--link-dest=' . self::ROOT . "/$stale.inprogress/a"],
            array_values(array_filter($argv, fn($t) => $t === '--info=stats2' || str_starts_with($t, '--link-dest=')))
        );
        $this->assertSame(['--', '/mnt/user/a/', 'backup@h.example:' . self::ROOT . "/$stamp.inprogress/a/"], array_slice($argv, -3));

        // Hook env, the in-flight record, metadata, History, summary, notification.
        $this->assertSame($stamp, $this->hookEnv['PRE']['UR_SNAPSHOT']);
        $this->assertSame('', $this->hookEnv['PRE']['UR_SNAPSHOT_STATUS']);
        $this->assertSame($stamp, $this->hookEnv['POST']['UR_SNAPSHOT']);
        $this->assertSame('committed', $this->hookEnv['POST']['UR_SNAPSHOT_STATUS']);
        $this->assertSame($stamp, $this->inflight['snapshot'] ?? null);

        $meta = json_decode((string) $this->rx['dirs'][$stamp]['meta'], true);
        $this->assertSame($stamp, $meta['stamp']);
        $this->assertSame(self::JOB, $meta['jobId']);
        $this->assertFalse($meta['suspect']);
        $this->assertSame(['exit' => 0, 'files' => 10, 'bytes' => 1000], $meta['subs']['a']);
        $this->assertSame(['size' => 100000000, 'used' => 20000000, 'avail' => 80000000], $meta['df']['before']);

        $h = $this->lastHistory();
        $this->assertSame($stamp, $h['snapshot']);
        $this->assertTrue($h['committed']);
        $this->assertFalse($h['suspect']);
        $this->assertSame(2, $h['pruned']);
        $this->assertSame(1000, $h['growthKB'], 'measured from the free space read again after cleanup');
        $summary = Runner::readSummary(self::JOB);
        $this->assertSame($stamp, $summary['snapshot']);
        $this->assertTrue($summary['committed']);
        $this->assertSame('normal', $this->importance());

        $log = $this->log($res);
        $this->assertStringContainsString("Snapshot mode: this run's snapshot is $stamp (UTC).", $log);
        $this->assertStringContainsString('--delete is forced off in snapshot mode.', $log);
        $this->assertStringContainsString("Prune plan: KEEP $stamp (daily, newest, latest, current).", $log);
        $this->assertStringContainsString('Prune plan: DELETE ' . $this->stamp(3) . '.', $log);
    }

    public function testGrowthIsRecordedWhenNothingWasCleanedUp(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $this->runJob();
        $this->assertSame(1000, $this->lastHistory()['growthKB']);
    }

    // --- preflight failures -----------------------------------------------------------

    /** @return array<string,array{0:int,1:string}> */
    public static function guardProvider(): array
    {
        return [
            'root missing'     => [11, Snapshot::HELPER_EXITS[11]],
            'marker wrong'     => [12, Snapshot::HELPER_EXITS[12]],
            'sentinel wrong'   => [14, Snapshot::HELPER_EXITS[14]],
            'other filesystem' => [13, Snapshot::HELPER_EXITS[13]],
        ];
    }

    #[DataProvider('guardProvider')]
    public function testAFailingReceiverCheckFailsBeforeThePreHook(int $exit, string $meaning): void
    {
        $this->saveJob();
        $this->rx['guard'] = $exit;
        $res = $this->runJob();

        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('snapshot-preflight', $res['reason']);
        $this->assertSame(['marker', 'helper:probe', 'helper:check', 'hook:POST'], $this->trace);
        $this->assertSame('', $this->hookEnv['POST']['UR_SNAPSHOT']);
        $this->assertSame('none', $this->hookEnv['POST']['UR_SNAPSHOT_STATUS']);
        $this->assertStringContainsString("Snapshot helper check failed (exit $exit: $meaning). ur-snapshot: guard refused", $this->log($res));
        $this->assertSame('alert', $this->importance());
        $h = $this->lastHistory();
        $this->assertSame('', $h['snapshot']);
        $this->assertFalse($h['committed']);
    }

    public function testADryRunWithTheRootMissingFailsBeforeThePreHook(): void
    {
        $this->saveJob();
        $this->rx['guard'] = 11;
        $res = $this->runJob(true);
        $this->assertSame('snapshot-preflight', $res['reason']);
        $this->assertNotContains('hook:PRE', $this->trace);
        $this->assertSame('dry-run', $this->hookEnv['POST']['UR_SNAPSHOT_STATUS']);
    }

    /** @return array<string,array{0:callable,1:string,2:string}> */
    public static function preflightProvider(): array
    {
        return [
            'probe as non-root' => [fn(self $t) => $t->failOp['probe'] = 17, 'snapshot-preflight', 'Snapshot helper probe failed (exit 17: not root, or a tool is missing).'],
            'sudo wants a password' => [fn(self $t) => $t->failOp['probe'] = 1, 'snapshot-preflight', 'Snapshot helper probe failed (exit 1: transport failure).'],
            'list fails' => [fn(self $t) => $t->failOp['list'] = 18, 'snapshot-preflight', 'Snapshot helper list failed (exit 18: I/O failure).'],
            'clock skew' => [fn(self $t) => $t->rx['now'] = $t->clock + 121, 'snapshot-clock', "The receiver's clock is 121 s ahead of this server"],
            'skew within the limit' => [fn(self $t) => $t->rx['now'] = $t->clock - 120, '', ''],
            'future-named dir' => [fn(self $t) => $t->seedSnapshot('2027-01-01T000000Z'), 'snapshot-clock', "'2027-01-01T000000Z' in the snapshot root is dated more than a day in the future."],
            'not monotonic' => [fn(self $t) => $t->seedSnapshot(Snapshot::stamp(new DateTimeImmutable('@' . ($t->clock + 60)))), 'snapshot-clock', 'does not sort after the existing'],
            'too little space' => [fn(self $t) => $t->rx['df'] = [100000000, 96000000, 4000000], 'snapshot-preflight', 'The receiver has 4000000 KB free'],
            'root unreadable by the login' => [fn(self $t) => $t->readExit = 23, 'snapshot-preflight', "The transfer's SSH login cannot read the snapshot root"],
            'missing source' => [fn(self $t) => $t->sources['/mnt/user/b/'] = false, 'snapshot-preflight', 'Pair #2: the source /mnt/user/b/ does not exist or is not a directory.'],
            'empty source' => [fn(self $t) => $t->sources['/mnt/user/a/'] = ['.', '..'], 'snapshot-preflight', 'Pair #1: the source /mnt/user/a/ is empty'],
            'new pair without seed' => [fn(self $t) => $t->seedSnapshot($t->stamp(1), ['a']), 'snapshot-preflight', 'Pair #2 (b): there is no previous copy to link against'],
        ];
    }

    #[DataProvider('preflightProvider')]
    public function testSnapshotPreflightFailuresStopBeforeThePreHook(callable $arrange, string $reason, string $logged): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $arrange($this);
        $res = $this->runJob();

        if ($reason === '') {
            $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));
            return;
        }
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame($reason, $res['reason']);
        $this->assertNotContains('hook:PRE', $this->trace);
        $this->assertNotContains('helper:mkstamp', $this->trace);
        $this->assertContains('hook:POST', $this->trace, 'the post-hook still runs');
        $this->assertSame([], $this->transfers);
        $this->assertStringContainsString($logged, $this->log($res));
        $this->assertSame('alert', $this->importance());
    }

    public function testAModifiedHelperFailsClosedBeforeAnyRemoteCall(): void
    {
        $this->saveJob();
        $copy = $this->rt . '-helper.sh';
        file_put_contents($copy, (string) file_get_contents(Snapshot::helperPath()) . "# changed\n");
        Snapshot::$helperPathOverride = $copy;
        try {
            $res = $this->runJob();
        } finally {
            @unlink($copy);
        }
        $this->assertSame('snapshot-preflight', $res['reason']);
        $this->assertSame([], $this->helperCalls());
        $this->assertStringContainsString('has been modified (sha256 mismatch)', $this->log($res));
    }

    public function testARejectedOptionIsSnapshotPreflightAndOtherRulesAreGuardrails(): void
    {
        $this->saveJob(['rsyncOptions' => Config::mergeRsyncOptions(['times' => true, 'checksum' => true])]);
        $res = $this->runJob();
        $this->assertSame('snapshot-preflight', $res['reason']);
        $this->assertSame(['hook:POST'], $this->trace, 'refused before the marker fetch');
        $this->assertStringContainsString('Snapshot mode cannot be used with this option: --checksum', $this->log($res));

        $this->saveJob(['pairs' => [['local' => '/mnt/user/a/', 'remote' => '/srv/x', 'sub' => 'a', 'seed' => true]]]);
        $res = $this->runJob();
        $this->assertSame('guardrail', $res['reason']);
        $this->assertStringContainsString('Rejected by path guardrails: Pair #1: in snapshot mode the destination is <root>/<stamp>/<sub>', $this->log($res));

        $this->saveJob(['targetMarker' => '', 'targetMarkerContent' => '']);
        $this->assertSame('guardrail', $this->runJob()['reason']);
    }

    public function testGuardrailErrorsLeaveASnapshotPairsRemoteToSnapshotErrors(): void
    {
        $snap = ['snapshot' => ['enabled' => true, 'root' => self::ROOT]];
        $pair = ['local' => '/mnt/user/a/', 'remote' => '', 'sub' => 'a'];
        $this->assertSame([], Runner::guardrailErrors($snap, $pair, 'SSH', ['delete' => true]));
        $this->assertSame(['remote path is required.'], Runner::guardrailErrors([], $pair, 'SSH', []));
        $this->assertSame(
            ["local path '/boot/config' must be a sub-directory of /mnt (for example /mnt/user/share/...)."],
            Runner::guardrailErrors($snap, ['local' => '/boot/config'] + $pair, 'SSH', [])
        );
    }

    public function testBootAsTheSourcePassesThePreflightAndIsCopiedIntoItsSub(): void
    {
        $this->saveJob(['pairs' => [['local' => '/boot/', 'remote' => '', 'sub' => 'boot', 'seed' => true]]]);
        $scanned = [];
        Runner::$scanDir = function (string $p) use (&$scanned): array|false {
            $scanned[] = $p;
            return $this->sources[$p] ?? false;
        };
        $this->sources['/boot/'] = ['.', '..', 'bzimage', 'config', 'previous'];
        $stamp = $this->stamp();

        $res = $this->runJob();

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));
        $this->assertSame(['/boot/'], $scanned);
        $this->assertCount(1, $this->transfers);
        $this->assertSame(['--', '/boot/', 'backup@h.example:' . self::ROOT . "/$stamp.inprogress/boot/"], array_slice($this->transfers[0], -3));
        $this->assertSame([], preg_grep('/^--link-dest=/', $this->transfers[0]), 'the first snapshot is a seeded full copy');

        $this->clock += self::DAY;
        $this->sources['/boot/'] = ['.', '..'];
        $res = $this->runJob();
        $this->assertSame('snapshot-preflight', $res['reason']);
        $this->assertStringContainsString('Pair #1: the source /boot/ is empty; refusing to take an empty snapshot of it.', $this->log($res));
    }

    public function testTheMarkerIsMandatoryAndCheckedFromTheHost(): void
    {
        $this->saveJob();
        $this->markerContent = "other\n";
        $res = $this->runJob();
        $this->assertSame('dest-not-mounted', $res['reason']);
        $this->assertSame(['marker', 'hook:POST'], $this->trace);
    }

    // --- pairs, seeds, basis ------------------------------------------------------------

    public function testANewPairWithSeedIsAFullCopyAndTheOthersLink(): void
    {
        $this->saveJob(['pairs' => [
            ['local' => '/mnt/user/a/', 'remote' => '', 'sub' => 'a', 'seed' => true],
            ['local' => '/mnt/user/b/', 'remote' => '', 'sub' => 'b', 'seed' => true],
        ]]);
        $prev = $this->stamp(1);
        $this->seedSnapshot($prev, ['a']);
        $res = $this->runJob();

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));
        $this->assertContains('--link-dest=' . self::ROOT . "/$prev/a", $this->transfers[0]);
        $this->assertSame([], preg_grep('/^--link-dest=/', $this->transfers[1]));
        $log = $this->log($res);
        $this->assertStringContainsString("Pair #1 (a): $prev/a exists, so its seed flag has no effect and can be cleared.", $log);
        $this->assertStringContainsString('Pair #2 (b): new pair: full copy expected.', $log);
    }

    public function testTheFirstSnapshotNeedsSeedOnEveryPair(): void
    {
        $this->saveJob();
        $res = $this->runJob();
        $this->assertSame('snapshot-preflight', $res['reason']);
        $this->assertStringContainsString('Pair #1 (a): there is no previous copy to link against (no complete snapshot yet)', $this->log($res));

        $this->saveJob(['pairs' => [['local' => '/mnt/user/a/', 'remote' => '', 'sub' => 'a', 'seed' => true]]]);
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));
        $this->assertStringContainsString('no baseline (the previous snapshot has no metadata)', $this->log($res));
    }

    public function testASeedWithoutMetadataLogsNoBaselineAndCommits(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $this->srcStats = ['a' => [1, 1], 'b' => [1, 1]];
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertStringContainsString('Anomaly check: no baseline (the previous snapshot has no metadata), so the file, size and growth checks were skipped.', $this->log($res));
        $this->assertTrue($this->lastHistory()['committed']);
    }

    // --- the completion gate ------------------------------------------------------------

    /** @return array<string,array{0:int,1:string}> */
    public static function notCommittedProvider(): array
    {
        return [
            'partial' => [23, Rsync::STATE_PARTIAL],
            'timeout' => [30, Rsync::STATE_TIMEOUT],
            'failed'  => [12, Rsync::STATE_FAILED],
        ];
    }

    #[DataProvider('notCommittedProvider')]
    public function testAFailedPairIsNotCommittedOrPrunedAndAlerts(int $exit, string $state): void
    {
        $this->saveJob(snap: ['pruneDryRun' => false]);
        $this->seedSnapshot($this->stamp(1));
        $this->pairExit = ['a' => $exit];
        $stamp = $this->stamp();
        $res = $this->runJob();

        $this->assertSame($state, $res['state']);
        $this->assertSame('snapshot-not-committed', $res['reason']);
        $this->assertSame(['helper:probe', 'helper:check', 'helper:list', 'helper:mkstamp'], $this->helperCalls(), 'no commit, no prune');
        $this->assertArrayHasKey("$stamp.inprogress", $this->rx['dirs']);
        $this->assertSame(['rsync:a', 'rsync:b'], array_values(preg_grep('/^rsync:/', $this->trace)), 'every pair still runs');
        $this->assertSame($stamp, $this->hookEnv['POST']['UR_SNAPSHOT']);
        $this->assertSame('not-committed', $this->hookEnv['POST']['UR_SNAPSHOT_STATUS']);
        $this->assertSame('alert', $this->importance());
        $h = $this->lastHistory();
        $this->assertSame($stamp, $h['snapshot']);
        $this->assertFalse($h['committed']);
        $this->assertFalse(Runner::readSummary(self::JOB)['committed']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function abortProvider(): array
    {
        return ['manual' => ['manual', 'normal'], 'schedule' => ['schedule', 'warning']];
    }

    #[DataProvider('abortProvider')]
    public function testAnAbortIsNotCommittedAndKeepsTheAbortImportance(string $trigger, string $importance): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $this->duringTransfer = fn(string $sub) => RunState::requestAbort(self::JOB);
        $this->pairExit = ['a' => 20];
        $res = $this->runJob(false, $trigger);

        $this->assertSame(Rsync::STATE_ABORTED, $res['state']);
        $this->assertArrayNotHasKey('reason', $res);
        $this->assertNotContains('helper:commit', $this->trace);
        $this->assertNotContains('rsync:b', $this->trace);
        $this->assertSame($importance, $this->importance());
        $this->assertSame('not-committed', $this->hookEnv['POST']['UR_SNAPSHOT_STATUS']);
    }

    public function testAnAbortThatLandsAsTheLastPairSucceedsIsStillNotCommitted(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $this->duringTransfer = function (string $sub): void {
            if ($sub === 'b') {
                RunState::requestAbort(self::JOB);
            }
        };
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_ABORTED, $res['state']);
        $this->assertSame(['rsync:a', 'rsync:b'], array_values(preg_grep('/^rsync:/', $this->trace)));
        $this->assertNotContains('helper:commit', $this->trace);
        $this->assertArrayNotHasKey('reason', $res);
    }

    public function testExit24CommitsWithANormalNotification(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $this->pairExit = ['b' => 24];
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_WARNING, $res['state']);
        $this->assertArrayNotHasKey('reason', $res);
        $this->assertContains('helper:commit', $this->trace);
        $this->assertSame('normal', $this->importance());
    }

    public function testMkstampAndCommitFailuresFailTheRun(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $this->failOp['mkstamp'] = 15;
        $res = $this->runJob();
        $this->assertSame('snapshot-mkstamp-failed', $res['reason']);
        $this->assertSame([], $this->transfers);
        $this->assertSame('none', $this->hookEnv['POST']['UR_SNAPSHOT_STATUS']);
        $this->assertSame('alert', $this->importance());

        $this->failOp = ['commit' => 18];
        $this->clock += 60;
        $stamp = $this->stamp();
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('snapshot-commit-failed', $res['reason']);
        $this->assertArrayHasKey("$stamp.inprogress", $this->rx['dirs'], 'the .inprogress stays');
        $this->assertSame('not-committed', $this->hookEnv['POST']['UR_SNAPSHOT_STATUS']);
        $this->assertNotContains('helper:list', array_slice($this->trace, (int) array_search('hook:POST', $this->trace, true)), 'no prune');
        $this->assertSame('alert', $this->importance());
    }

    public function testTheAlertCountsRunsThatDidNotCommit(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $streak = 'Job "snap" has not committed a snapshot for';
        $this->pairExit = ['a' => 23];

        $this->runJob();
        $this->assertStringNotContainsString('has not committed', $this->notified[0], 'one run is not a streak');
        $this->assertStringContainsString('Job "snap" finished.', $this->notified[0]);

        $this->clock += 3600;
        $this->runJob();
        $this->assertSame('alert', $this->importance(), 'the reason still decides the importance');
        $this->assertStringContainsString("$streak 2 runs.", $this->notified[0]);

        // A dry run and an abort neither count nor break the streak.
        $this->clock += 3600;
        $this->runJob(true);
        $this->assertStringNotContainsString('has not committed', $this->notified[0]);
        $this->clock += 3600;
        $this->duringTransfer = fn(string $sub) => RunState::requestAbort(self::JOB);
        $this->runJob();
        $this->assertStringNotContainsString('has not committed', $this->notified[0]);
        $this->duringTransfer = null;

        $this->clock += 3600;
        $this->runJob();
        $this->assertStringContainsString("$streak 3 runs.", $this->notified[0]);

        // A commit resets it.
        $this->pairExit = [];
        $this->clock += 3600;
        $this->runJob();
        $this->assertStringNotContainsString('has not committed', $this->notified[0]);
        $this->pairExit = ['a' => 23];
        $this->clock += 3600;
        $this->runJob();
        $this->assertStringNotContainsString('has not committed', $this->notified[0]);
    }

    public function testThreeRunsThatDoNotCommitKeepOnlyTheNewestStaleInprogress(): void
    {
        $this->saveJob();
        $prev = $this->stamp(1);
        $this->seedSnapshot($prev);
        $this->pairExit = ['a' => 23];
        $stamps = [];
        for ($i = 0; $i < 3; $i++) {
            $stamps[] = $this->stamp();
            $this->runJob();
            $this->clock += 3600;
        }
        // After run 3's cleanup exactly one stale .inprogress was left (run 2's);
        // run 3 then created its own, which the next run will treat the same way.
        $this->assertSame([$prev, "{$stamps[1]}.inprogress", "{$stamps[2]}.inprogress"], $this->names());
        $this->assertContains("helper:delete:{$stamps[0]}.inprogress", $this->trace);
        $this->assertContains('--link-dest=' . self::ROOT . "/{$stamps[1]}.inprogress/a", $this->transfers[0], 'the kept one is a link-dest');
    }

    /** @return array<string,array{0:bool,1:array<int,int>,2:array<int,int>,3:?int}> */
    public static function unfinishedProvider(): array
    {
        // Ages in hours; the newest complete snapshot, when there is one, is 24 h old.
        return [
            'older than the last commit'      => [true, [48], [48], null],
            'newer than the last commit'      => [true, [1], [], 1],
            'both older than the last commit' => [true, [72, 48], [72, 48], null],
            'one older, one newer'            => [true, [48, 1], [48], 1],
            'no complete snapshot yet'        => [false, [48, 1], [48], 1],
        ];
    }

    /**
     * @param array<int,int> $ages
     * @param array<int,int> $deleted
     */
    #[DataProvider('unfinishedProvider')]
    public function testAnUnfinishedSnapshotIsKeptOnlyIfNewerThanTheLastCommit(bool $committed, array $ages, array $deleted, ?int $kept): void
    {
        $name = fn(int $h): string => Snapshot::stamp(new DateTimeImmutable('@' . ($this->clock - $h * 3600))) . '.inprogress';
        if ($committed) {
            $this->saveJob();
            $this->seedSnapshot($this->stamp(1));
        } else {
            $this->saveJob(['pairs' => [['local' => '/mnt/user/a/', 'remote' => '', 'sub' => 'a', 'seed' => true]]]);
        }
        foreach ($ages as $h) {
            $this->rx['dirs'][$name($h)] = ['keep' => false, 'meta' => null, 'subs' => ['a', 'b']];
        }
        $res = $this->runJob();

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));
        $this->assertSame(
            array_map(fn(int $h): string => 'helper:delete:' . $name($h), $deleted),
            array_values(array_filter($this->helperCalls(), fn($t) => str_starts_with($t, 'helper:delete:') && str_ends_with($t, '.inprogress')))
        );
        foreach ($deleted as $h) {
            $this->assertArrayNotHasKey($name($h), $this->rx['dirs']);
        }
        $this->assertSame(
            $kept === null ? [] : ['--link-dest=' . self::ROOT . '/' . $name($kept) . '/a'],
            array_values(array_filter($this->transfers[0], fn($t) => str_starts_with($t, '--link-dest=') && str_contains($t, '.inprogress/')))
        );
        $log = $this->log($res);
        if ($kept === null) {
            $this->assertStringNotContainsString('reused', $log);
        } else {
            $this->assertArrayHasKey($name($kept), $this->rx['dirs']);
            $this->assertStringContainsString("unfinished {$name($kept)} reused as a link-dest.", $log);
        }
    }

    public function testAHoldKeepsAnUnfinishedSnapshotOlderThanTheLastCommit(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $old = $this->stamp(2) . '.inprogress';
        $this->rx['dirs'][$old] = ['keep' => false, 'meta' => null, 'subs' => ['a', 'b']];
        $this->rx['hold'] = true;
        $res = $this->runJob();

        $this->assertSame('snapshot-held', $res['reason'] ?? null, $this->log($res));
        $this->assertSame([], array_filter($this->helperCalls(), fn($t) => str_starts_with($t, 'helper:delete:')));
        $this->assertArrayHasKey($old, $this->rx['dirs']);
        $this->assertNotContains('--link-dest=' . self::ROOT . "/$old/a", $this->transfers[0], 'still not a link-dest');
    }

    public function testAMirrorJobNeverTouchesTheSnapshotRoot(): void
    {
        $this->saveJob(['pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']]], ['enabled' => false]);
        $this->seedSnapshot($this->stamp(1));
        $this->rx['dirs'][$this->stamp(2) . '.inprogress'] = ['keep' => false, 'meta' => null, 'subs' => ['a']];
        $before = $this->rx;
        $marker = Rsync::$runner;
        Rsync::$runner = function (array $argv, callable $out) use ($marker): int {
            if (in_array('--max-size=4K', $argv, true)) {
                return $marker($argv, $out);
            }
            $this->transfers[] = $argv;
            return 0;
        };
        $res = $this->runJob();

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));
        $this->assertSame([], $this->helperCalls());
        $this->assertSame($before, $this->rx);
        $this->assertNotSame([], $this->transfers);
        $this->assertSame([], array_filter(array_merge(...$this->transfers), fn($t) => str_starts_with($t, '--link-dest=')));
    }

    // --- anomaly ------------------------------------------------------------------------

    public function testAHalvedSourceIsSuspectHeldPinnedCommittedAndNotPruned(): void
    {
        $this->saveJob(snap: ['pruneDryRun' => false, 'keepDaily' => 1, 'keepWeekly' => 0]);
        $prev = $this->stamp(1);
        $this->seedSnapshot($this->stamp(2));
        $this->seedSnapshot($this->stamp(3));
        $this->seedSnapshot($prev, meta: ['subs' => ['a' => ['files' => 1000, 'bytes' => 5000], 'b' => ['files' => 10, 'bytes' => 10]]]);
        $this->srcStats = ['a' => [400, 5000], 'b' => [10, 10]];
        $stamp = $this->stamp();
        $res = $this->runJob();

        $this->assertSame(Rsync::STATE_WARNING, $res['state']);
        $this->assertSame('snapshot-suspect', $res['reason']);
        $this->assertSame(
            ['helper:probe', 'helper:check', 'helper:list', 'helper:mkstamp', 'helper:check', 'helper:hold', "helper:pin:$prev", 'helper:commit'],
            $this->helperCalls()
        );
        $this->assertTrue($this->rx['hold']);
        $this->assertTrue($this->rx['dirs'][$prev]['keep']);
        $this->assertSame($stamp, $this->rx['latest']);
        $this->assertTrue(json_decode((string) $this->rx['dirs'][$stamp]['meta'], true)['suspect']);
        $this->assertStringContainsString('SUSPECT: a: files dropped from 1000 to 400.', $this->log($res));
        $this->assertSame('alert', $this->importance());
        $this->assertTrue($this->lastHistory()['suspect']);

        // The next run warns that deletions are held and neither cleans up nor prunes.
        $this->clock += self::DAY;
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_WARNING, $res['state']);
        $this->assertSame('snapshot-held', $res['reason']);
        $this->assertSame([], preg_grep('/^helper:delete/', $this->trace));
        $this->assertStringContainsString('Prune skipped: deletions are on hold', $this->log($res));
        $this->assertSame('warning', $this->importance());
    }

    public function testAMissingLinkDestBasisIsSuspect(): void
    {
        $this->saveJob();
        $prev = $this->stamp(1);
        $this->seedSnapshot($prev, meta: ['subs' => []]);
        $out = Rsync::$runner;
        Rsync::$runner = function (array $argv, callable $o) use ($out, $prev): int {
            if (!in_array('--max-size=4K', $argv, true) && !in_array('--list-only', $argv, true)) {
                $o('--link-dest arg does not exist: ' . self::ROOT . "/$prev/a\n");
            }
            return $out($argv, $o);
        };
        $res = $this->runJob();
        $this->assertSame('snapshot-suspect', $res['reason']);
        $this->assertStringContainsString("SUSPECT: a: rsync reported that the previous snapshot's copy does not exist.", $this->log($res));
    }

    public function testAMissingBasisReportedEarlyInLongOutputIsStillSuspect(): void
    {
        $this->saveJob();
        $prev = $this->stamp(1);
        $this->seedSnapshot($prev, meta: ['subs' => []]);
        $out = Rsync::$runner;
        Rsync::$runner = function (array $argv, callable $o) use ($out, $prev): int {
            if (!in_array('--max-size=4K', $argv, true) && !in_array('--list-only', $argv, true)) {
                $o('--link-dest arg does not exist: ' . self::ROOT . "/$prev/a\n");
                for ($i = 0; $i < 300; $i++) {
                    $o(str_repeat('x', 999) . "\n");
                }
            }
            return $out($argv, $o);
        };
        $res = $this->runJob();
        $this->assertSame('snapshot-suspect', $res['reason'], 'the warning is 300 KB before the end of the output');
        $meta = json_decode((string) $this->rx['dirs'][$this->stamp()]['meta'], true);
        $this->assertSame(10, $meta['subs']['a']['files'], 'the stats still come from the tail');
    }

    /** @return array<string,array{0:string}> */
    public static function protectProvider(): array
    {
        return ['hold fails' => ['hold'], 'pin fails' => ['pin']];
    }

    #[DataProvider('protectProvider')]
    public function testASuspectRunThatCannotBeProtectedIsNotCommitted(string $op): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1), meta: ['subs' => ['a' => ['files' => 1000, 'bytes' => 1]]]);
        $this->srcStats = ['a' => [1, 1]];
        $this->failOp[$op] = 18;
        $stamp = $this->stamp();
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('snapshot-commit-failed', $res['reason']);
        $this->assertNotContains('helper:commit', $this->trace);
        $this->assertArrayHasKey("$stamp.inprogress", $this->rx['dirs']);
        $this->assertStringContainsString('was not committed because it could not be protected', $this->log($res));
        $this->assertSame('alert', $this->importance());
    }

    public function testAnAbortAfterTheLastPairIsHonouredBeforeTheCommit(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $orig = Snapshot::$remoteRunner;
        $checks = 0;
        Snapshot::$remoteRunner = function (array $argv, ?array $env, int $t, ?callable $s) use ($orig, &$checks): array {
            if (str_contains((string) end($argv), "'check'") && ++$checks === 2) {
                RunState::requestAbort(self::JOB);
            }
            return $orig($argv, $env, $t, $s);
        };
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_ABORTED, $res['state']);
        $this->assertSame(143, $res['exitCode']);
        $this->assertArrayNotHasKey('reason', $res);
        $this->assertNotContains('helper:commit', $this->trace);
        $this->assertSame('not-committed', $this->hookEnv['POST']['UR_SNAPSHOT_STATUS']);
        $this->assertSame('normal', $this->importance());
    }

    /** @return array<string,array{0:string,1:int,2:array<int,string>}> op, which call of it, helper calls made */
    public static function killedHelperProvider(): array
    {
        return [
            'probe'               => ['probe', 1, ['helper:probe']],
            'mkstamp'             => ['mkstamp', 1, ['helper:probe', 'helper:check', 'helper:list', 'helper:mkstamp']],
            'check after pairs'   => ['check', 2, ['helper:probe', 'helper:check', 'helper:list', 'helper:mkstamp', 'helper:check']],
            'hold'                => ['hold', 1, ['helper:probe', 'helper:check', 'helper:list', 'helper:mkstamp', 'helper:check', 'helper:hold']],
            'commit'              => ['commit', 1, ['helper:probe', 'helper:check', 'helper:list', 'helper:mkstamp', 'helper:check', 'helper:commit']],
        ];
    }

    /**
     * Abort SIGTERMs the runner's process group, so the helper's ssh dies
     * with it: the call fails with 143. That is an abort, not a failure.
     *
     * @param array<int,string> $calls
     */
    #[DataProvider('killedHelperProvider')]
    public function testAHelperCallKilledByAbortIsAnAbortNotAFailure(string $op, int $nth, array $calls): void
    {
        $this->saveJob();
        // Only the hold case is suspect (a halved file count).
        $this->seedSnapshot($this->stamp(1), meta: ['subs' => $op === 'hold' ? ['a' => ['files' => 1000, 'bytes' => 1]] : []]);
        $orig = Snapshot::$remoteRunner;
        $seen = 0;
        Snapshot::$remoteRunner = function (array $argv, ?array $env, int $t, ?callable $s) use ($orig, $op, $nth, &$seen): array {
            if (str_contains((string) end($argv), "'ur-snapshot' '$op'") && ++$seen === $nth) {
                $this->trace[] = "helper:$op";
                RunState::requestAbort(self::JOB);
                return ['exit' => 143, 'stdout' => '', 'stderr' => ''];
            }
            return $orig($argv, $env, $t, $s);
        };
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_ABORTED, $res['state'], $this->log($res));
        $this->assertSame(143, $res['exitCode']);
        $this->assertArrayNotHasKey('reason', $res);
        $this->assertSame($calls, $this->helperCalls());
        $this->assertSame($op === 'probe', !in_array('hook:PRE', $this->trace, true), 'no pre-hook after an abort in preflight');
        $this->assertContains('hook:POST', $this->trace);
        $this->assertSame('normal', $this->importance());
    }

    public function testACleanupDeletionThatFreedSpaceAndThenFailedStillRefreshesTheBaseline(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1), meta: ['subs' => []]);
        $this->rx['dirs'][$this->stamp(3) . '.deleting'] = ['keep' => false, 'meta' => null, 'subs' => []];
        foreach ([100, 200, 300] as $kb) {
            History::append(self::JOB, ['startedAt' => '2026-10-01T00:00:00Z', 'state' => 'SUCCESS', 'snapshot' => 'x', 'committed' => true, 'growthKB' => $kb]);
        }
        $orig = Snapshot::$remoteRunner;
        Snapshot::$remoteRunner = function (array $argv, ?array $env, int $t, ?callable $s) use ($orig): array {
            if (str_contains((string) end($argv), "'delete'")) {
                $this->trace[] = 'helper:delete:failed';
                $this->rx['df'][1] -= 10000000;
                return ['exit' => 18, 'stdout' => "ur-snapshot 1\n", 'stderr' => "ur-snapshot: rm failed half way\n"];
            }
            return $orig($argv, $env, $t, $s);
        };
        $this->transferKB = intdiv(Snapshot::GROWTH_MIN_KB + 2, 2);
        $res = $this->runJob();
        $this->assertSame('snapshot-suspect', $res['reason'], 'suspect (alert) replaces prune-failed (warning)');
        $this->assertSame(Snapshot::GROWTH_MIN_KB + 2, $this->lastHistory()['growthKB']);
    }

    public function testAnAbortDuringCleanupSkipsThePreHook(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $this->rx['dirs'][$this->stamp(3) . '.deleting'] = ['keep' => false, 'meta' => null, 'subs' => []];
        $orig = Snapshot::$remoteRunner;
        Snapshot::$remoteRunner = function (array $argv, ?array $env, int $t, ?callable $s) use ($orig): array {
            if (str_contains((string) end($argv), "'delete'")) {
                RunState::requestAbort(self::JOB);
            }
            return $orig($argv, $env, $t, $s);
        };
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_ABORTED, $res['state']);
        $this->assertArrayNotHasKey('reason', $res);
        $this->assertNotContains('hook:PRE', $this->trace);
        $this->assertNotContains('helper:mkstamp', $this->trace);
        $this->assertContains('hook:POST', $this->trace);
        $this->assertSame('none', $this->hookEnv['POST']['UR_SNAPSHOT_STATUS']);
        $this->assertSame('normal', $this->importance());
    }

    public function testAnAbortThatKillsThePostCommitListingIsNotAPruneSanityAlert(): void
    {
        $this->saveJob(snap: ['pruneDryRun' => false]);
        $this->seedSnapshot($this->stamp(1));
        $orig = Snapshot::$remoteRunner;
        $lists = 0;
        Snapshot::$remoteRunner = function (array $argv, ?array $env, int $t, ?callable $s) use ($orig, &$lists): array {
            if (str_contains((string) end($argv), "'list'") && ++$lists === 2) {
                RunState::requestAbort(self::JOB);
                return ['exit' => 143, 'stdout' => '', 'stderr' => ''];
            }
            return $orig($argv, $env, $t, $s);
        };
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertArrayNotHasKey('reason', $res);
        $this->assertTrue($this->lastHistory()['committed']);
        $this->assertStringContainsString('Abort requested; prune skipped.', $this->log($res));
        $this->assertSame('normal', $this->importance());
    }

    public function testGrowthIsNotMeasuredWhenTheFreeSpaceCannotBeReadAfterCleanup(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1), meta: ['subs' => []]);
        $this->rx['dirs'][$this->stamp(3) . '.deleting'] = ['keep' => false, 'meta' => null, 'subs' => []];
        $orig = Snapshot::$remoteRunner;
        $checks = 0;
        Snapshot::$remoteRunner = function (array $argv, ?array $env, int $t, ?callable $s) use ($orig, &$checks): array {
            if (str_contains((string) end($argv), "'check'") && ++$checks === 2) {
                return ['exit' => 255, 'stdout' => '', 'stderr' => "ssh: connection reset\n"];
            }
            return $orig($argv, $env, $t, $s);
        };
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertArrayNotHasKey('growthKB', $this->lastHistory());
        $this->assertStringContainsString('Growth will not be measured: the free space could not be read again after cleanup.', $this->log($res));
        $this->assertStringContainsString('Anomaly check: growth was not measured.', $this->log($res));
    }

    /** @return array<string,array{0:int}> */
    public static function rsyncAbortProvider(): array
    {
        return ['rsync saw the signal (20)' => [20], 'rsync was killed (143)' => [143]];
    }

    #[DataProvider('rsyncAbortProvider')]
    public function testAnAbortExitWithoutTheFlagIsATerminatedFailure(int $exit): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $this->pairExit = ['b' => $exit];
        $res = $this->runJob();
        // Nobody clicked Abort: a shutdown or a stray kill, which must alert.
        $this->assertSame(Rsync::STATE_FAILED, $res['state']);
        $this->assertSame('terminated', $res['reason'] ?? null);
        $this->assertNotContains('helper:commit', $this->trace);
        $this->assertSame('alert', $this->importance());
    }

    public function testRunawayGrowthIsSuspectOnceThreeValuesExist(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1), meta: ['subs' => []]);
        foreach ([100, 200, 300] as $kb) {
            History::append(self::JOB, ['startedAt' => '2026-10-01T00:00:00Z', 'state' => 'SUCCESS', 'snapshot' => 'x', 'committed' => true, 'growthKB' => $kb]);
        }
        $this->transferKB = intdiv(Snapshot::GROWTH_MIN_KB + 2, 2);
        $res = $this->runJob();
        $this->assertSame('snapshot-suspect', $res['reason']);
        $this->assertStringContainsString('the filesystem grew by ' . (Snapshot::GROWTH_MIN_KB + 2) . ' KB', $this->log($res));
    }

    public function testRunawayGrowthIsStillCaughtAfterCleanupFreedSpace(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1), meta: ['subs' => []]);
        $this->rx['dirs'][$this->stamp(3) . '.deleting'] = ['keep' => false, 'meta' => null, 'subs' => []];
        foreach ([100, 200, 300] as $kb) {
            History::append(self::JOB, ['startedAt' => '2026-10-01T00:00:00Z', 'state' => 'SUCCESS', 'snapshot' => 'x', 'committed' => true, 'growthKB' => $kb]);
        }
        $orig = Snapshot::$remoteRunner;
        Snapshot::$remoteRunner = function (array $argv, ?array $env, int $t, ?callable $s) use ($orig): array {
            $r = $orig($argv, $env, $t, $s);
            if (str_contains((string) end($argv), "'delete'")) {
                $this->rx['df'][1] -= 10000000;
            }
            return $r;
        };
        $this->transferKB = intdiv(Snapshot::GROWTH_MIN_KB + 2, 2);
        $res = $this->runJob();
        $this->assertSame('snapshot-suspect', $res['reason'], 'the 10 GB cleanup freed does not hide the growth');
        $this->assertSame(Snapshot::GROWTH_MIN_KB + 2, $this->lastHistory()['growthKB']);
    }

    // --- dry run ------------------------------------------------------------------------

    public function testADryRunCreatesNothingAndLogsThePrunePlan(): void
    {
        $this->saveJob(snap: ['pruneDryRun' => false, 'keepDaily' => 1, 'keepWeekly' => 0, 'maxPrunePerRun' => 1]);
        foreach ([3, 2, 1] as $ago) {
            $this->seedSnapshot($this->stamp($ago));
        }
        $stale = $this->stamp(4);
        $this->rx['dirs']["$stale.inprogress"] = ['keep' => false, 'meta' => null, 'subs' => []];
        $this->rx['dirs'][$this->stamp(5) . '.inprogress'] = ['keep' => false, 'meta' => null, 'subs' => []];
        $before = $this->rx;
        $stamp = $this->stamp();
        $res = $this->runJob(true);

        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));
        $this->assertSame(['helper:probe', 'helper:check', 'helper:list'], $this->helperCalls());
        $this->assertSame($before, $this->rx, 'nothing changed on the receiver');
        $this->assertContains('--dry-run', $this->transfers[0]);
        $this->assertSame('backup@h.example:' . self::ROOT . "/$stamp.inprogress/a/", end($this->transfers[0]));
        $this->assertSame('dry-run', $this->hookEnv['POST']['UR_SNAPSHOT_STATUS']);
        $this->assertSame('', $this->hookEnv['POST']['UR_SNAPSHOT']);
        $this->assertSame($stamp, $this->hookEnv['PRE']['UR_SNAPSHOT']);
        $log = $this->log($res);
        $this->assertStringContainsString('Dry run: the prune plan for the current listing (nothing is deleted):', $log);
        $this->assertStringContainsString('Prune plan: DELETE ' . $this->stamp(3) . '.', $log);
        $h = $this->lastHistory();
        $this->assertSame('', $h['snapshot']);
        $this->assertFalse($h['committed']);
    }

    // --- cleanup and prune ---------------------------------------------------------------

    public function testPruneDryRunDeletesNothingAndLogsThePlan(): void
    {
        $this->saveJob(snap: ['keepDaily' => 1, 'keepWeekly' => 0, 'maxPrunePerRun' => 1]);
        foreach ([3, 2, 1] as $ago) {
            $this->seedSnapshot($this->stamp($ago));
        }
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertSame([], preg_grep('/^helper:delete/', $this->trace));
        $this->assertStringContainsString('Prune plan: DELETE ' . $this->stamp(3) . '.', $this->log($res));
        $this->assertStringContainsString('Prune dry run is on (pruneDryRun), so nothing was deleted.', $this->log($res));
        $this->assertSame(0, $this->lastHistory()['pruned']);
    }

    public function testPruneHonoursTheCapAndWarnsAboutTheBacklog(): void
    {
        $this->saveJob(snap: ['pruneDryRun' => false, 'keepDaily' => 4, 'keepWeekly' => 0, 'maxPrunePerRun' => 2]);
        for ($ago = 8; $ago >= 1; $ago--) {
            $this->seedSnapshot($this->stamp($ago));
        }
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_WARNING, $res['state']);
        $this->assertSame('prune-backlog', $res['reason']);
        $this->assertSame(['helper:delete:' . $this->stamp(8), 'helper:delete:' . $this->stamp(7)], array_values(preg_grep('/^helper:delete/', $this->trace)));
        $this->assertStringContainsString('3 deletion(s) carried over to later runs (maxPrunePerRun is 2).', $this->log($res));
        $this->assertSame('warning', $this->importance());
        $this->assertSame(2, $this->lastHistory()['pruned']);
    }

    public function testPruneProblemsWarnOrAlertButKeepTheCommit(): void
    {
        $this->saveJob(snap: ['pruneDryRun' => false, 'keepDaily' => 1, 'keepWeekly' => 0, 'maxPrunePerRun' => 2]);
        foreach ([3, 2, 1] as $ago) {
            $this->seedSnapshot($this->stamp($ago));
        }
        // The first deletion is refused: the batch stops, prune-failed warns.
        $orig = Snapshot::$remoteRunner;
        $first = true;
        Snapshot::$remoteRunner = function (array $argv, ?array $env, int $t, ?callable $s) use ($orig, &$first): array {
            if ($first && str_contains((string) end($argv), "'delete'")) {
                $first = false;
                $this->trace[] = 'helper:delete:refused';
                return ['exit' => 16, 'stdout' => "ur-snapshot 1\n", 'stderr' => "ur-snapshot: protected\n"];
            }
            return $orig($argv, $env, $t, $s);
        };
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_WARNING, $res['state']);
        $this->assertSame('prune-failed', $res['reason']);
        $this->assertSame(['helper:delete:refused'], array_values(preg_grep('/^helper:delete/', $this->trace)));
        $this->assertTrue($this->lastHistory()['committed']);
        $this->assertSame('warning', $this->importance());

        // A listing that cannot be taken after the commit is a sanity abort.
        Snapshot::$remoteRunner = $orig;
        $this->clock += self::DAY;
        $calls = 0;
        Snapshot::$remoteRunner = function (array $argv, ?array $env, int $t, ?callable $s) use ($orig, &$calls): array {
            if (str_contains((string) end($argv), "'list'") && ++$calls === 2) {
                return ['exit' => 14, 'stdout' => '', 'stderr' => "ur-snapshot: sentinel gone\n"];
            }
            return $orig($argv, $env, $t, $s);
        };
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_WARNING, $res['state']);
        $this->assertSame('prune-sanity', $res['reason']);
        $this->assertSame('alert', $this->importance());
        $this->assertStringContainsString('Prune aborted: the snapshot root could not be listed after the commit.', $this->log($res));
    }

    public function testAnAbortThatStopsAPruneDeletionIsNotAPruneFailure(): void
    {
        $this->saveJob(snap: ['pruneDryRun' => false, 'keepDaily' => 1, 'keepWeekly' => 0, 'maxPrunePerRun' => 2]);
        foreach ([3, 2, 1] as $ago) {
            $this->seedSnapshot($this->stamp($ago));
        }
        $orig = Snapshot::$remoteRunner;
        Snapshot::$remoteRunner = function (array $argv, ?array $env, int $t, ?callable $s) use ($orig): array {
            if (str_contains((string) end($argv), "'delete'")) {
                RunState::requestAbort(self::JOB);
                $this->assertNotNull($s);
                $this->assertTrue($s(), 'the running deletion sees the abort');
                return ['exit' => 143, 'stdout' => '', 'stderr' => ''];
            }
            return $orig($argv, $env, $t, $s);
        };
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state']);
        $this->assertArrayNotHasKey('reason', $res);
        $this->assertSame(0, $this->lastHistory()['pruned']);
        $this->assertTrue($this->lastHistory()['committed']);
        $this->assertStringContainsString('Abort requested; Prune stopped.', $this->log($res));
    }

    public function testACleanupFailureWarnsAndTheRunStillCommits(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        // The fake receiver refuses to delete a pinned name.
        $this->rx['dirs'][$this->stamp(3) . '.deleting'] = ['keep' => true, 'meta' => null, 'subs' => []];
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_WARNING, $res['state']);
        $this->assertSame('prune-failed', $res['reason']);
        $this->assertContains('helper:commit', $this->trace);
        $this->assertSame('WARNING', $this->hookEnv['POST']['UR_JOB_STATUS'], 'the post-hook sees the warning');
        $this->assertSame('warning', $this->importance());
    }

    public function testAnAlertReasonReplacesAWarningReason(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $this->rx['hold'] = true;
        $this->pairExit = ['a' => 23];
        $res = $this->runJob();
        $this->assertSame('snapshot-not-committed', $res['reason']);
        $this->assertStringContainsString('Deletions are on hold', $this->log($res));
        $this->assertSame('alert', $this->importance());
    }

    // --- log levels (D-c) -----------------------------------------------------------------

    /** @return array<string,array{0:string}> */
    public static function levelProvider(): array
    {
        return array_combine(Job::LOG_LEVELS, array_map(fn($l) => [$l], Job::LOG_LEVELS));
    }

    #[DataProvider('levelProvider')]
    public function testStatsAreCapturedAtEveryLogLevel(string $level): void
    {
        $this->saveJob(['logLevel' => $level]);
        $this->seedSnapshot($this->stamp(1));
        $this->srcStats = ['a' => [7, 70], 'b' => [8, 80]];
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));
        $argv = $this->transfers[0];
        $this->assertContains('--info=stats2', $argv);
        $this->assertNotContains('-q', $argv, 'quiet is lifted to summary');
        $meta = json_decode((string) $this->rx['dirs'][$this->stamp()]['meta'], true);
        $this->assertSame(['exit' => 0, 'files' => 7, 'bytes' => 70], $meta['subs']['a']);
        $this->assertSame(['exit' => 0, 'files' => 8, 'bytes' => 80], $meta['subs']['b']);
    }

    public function testPairStatsLogRsyncsOwnFigureForAUnitAndThePlainNumberOtherwise(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $this->srcStats = ['a' => [45896, '408.63G'], 'b' => [8, '1,234']];
        $res = $this->runJob();
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));
        $this->assertStringContainsString('Pair #1 (a): 45896 regular files, 408.63G bytes.', $this->log($res));
        $this->assertStringContainsString('Pair #2 (b): 8 regular files, 1234 bytes.', $this->log($res));
        $meta = json_decode((string) $this->rx['dirs'][$this->stamp()]['meta'], true);
        $this->assertSame(408630000000, $meta['subs']['a']['bytes']);
    }

    /** The snapshot line follows the last REAL run, like the badge: a dry run must not hide it. */
    public function testADryRunKeepsTheLastRealRunsSnapshotLine(): void
    {
        $this->saveJob();
        $this->seedSnapshot($this->stamp(1));
        $stamp = $this->stamp();
        $this->assertSame(Rsync::STATE_SUCCESS, $this->runJob()['state']);
        $this->clock += 3600;
        $res = $this->runJob(true);
        $this->assertSame(Rsync::STATE_SUCCESS, $res['state'], $this->log($res));

        $summary = Runner::readSummary(self::JOB);
        $this->assertTrue($summary['dryRun']);
        $this->assertSame('', $summary['snapshot'], 'the top level is still the dry run');
        $last = Snapshot::lastSnapshot($summary);
        $this->assertNotNull($last);
        $this->assertSame($stamp, $last['stamp']);
        $this->assertTrue($last['committed']);
        $this->assertStringEndsWith('(committed)', $last['label']);
    }

    /** A summary from before lastReal carried snapshot fields, then the first dry run after the upgrade. */
    public function testTheFirstDryRunAfterTheUpgradeKeepsTheSnapshotLine(): void
    {
        $stamp = '2026-10-20T030005Z';
        $old = static fn(array $top): string => (string) json_encode($top + ['lastReal' => [
            'state' => 'SUCCESS', 'startedAt' => '2026-10-20T03:00:00Z', 'finishedAt' => '2026-10-20T03:10:00Z', 'exitCode' => 0, 'reason' => '',
        ]]);
        $dry = ['state' => 'SUCCESS', 'startedAt' => '2026-10-21T03:00:00Z', 'finishedAt' => '2026-10-21T03:01:00Z', 'exitCode' => 0, 'dryRun' => true, 'snapshot' => '', 'committed' => false];
        $path = UR_CONFIG_BASE . '/runs/' . self::JOB . '.summary.json';
        @mkdir(dirname($path), 0755, true);

        // Its top level is that same real run.
        file_put_contents($path, $old(['state' => 'SUCCESS', 'startedAt' => '2026-10-20T03:00:00Z', 'finishedAt' => '2026-10-20T03:10:00Z',
            'exitCode' => 0, 'dryRun' => false, 'snapshot' => $stamp, 'committed' => true]));
        $this->assertTrue(Runner::writeSummary(self::JOB, $dry));
        $this->assertSame($stamp, Snapshot::lastSnapshot(Runner::readSummary(self::JOB))['stamp'] ?? null);

        // Its top level was already a dry run: the real run's History record has it.
        file_put_contents($path, $old(['state' => 'SUCCESS', 'startedAt' => '2026-10-20T04:00:00Z', 'finishedAt' => '2026-10-20T04:01:00Z',
            'exitCode' => 0, 'dryRun' => true, 'snapshot' => '', 'committed' => false]));
        History::append(self::JOB, ['startedAt' => '2026-10-20T03:00:00Z', 'state' => 'SUCCESS', 'snapshot' => $stamp, 'committed' => true]);
        $this->assertTrue(Runner::writeSummary(self::JOB, $dry));
        $last = Snapshot::lastSnapshot(Runner::readSummary(self::JOB));
        $this->assertSame($stamp, $last['stamp'] ?? null);
        $this->assertTrue($last['committed']);
        @unlink($path);
    }

    /** The first run after the upgrade is a dry run that crashes: its crash record carries no snapshot fields. */
    public function testACrashedFirstDryRunAfterTheUpgradeKeepsTheSnapshotLineAtRetentionOne(): void
    {
        $this->saveJob();
        $config = Config::load();
        $config['global']['retention'] = 1;
        Config::save($config);
        $stamp = '2026-10-20T030005Z';
        $path  = UR_CONFIG_BASE . '/runs/' . self::JOB . '.summary.json';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, (string) json_encode([
            'state' => 'SUCCESS', 'startedAt' => '2026-10-20T03:00:00Z', 'finishedAt' => '2026-10-20T03:10:00Z',
            'exitCode' => 0, 'dryRun' => false, 'snapshot' => $stamp, 'committed' => true,
            'lastReal' => ['state' => 'SUCCESS', 'startedAt' => '2026-10-20T03:00:00Z', 'finishedAt' => '2026-10-20T03:10:00Z', 'exitCode' => 0, 'reason' => ''],
        ]));
        History::append(self::JOB, ['startedAt' => '2026-10-20T03:00:00Z', 'state' => 'SUCCESS', 'snapshot' => $stamp, 'committed' => true]);
        RunState::write(self::JOB, ['pid' => 2147483647, 'running' => true, 'dryRun' => true, 'startedAt' => '2026-10-21T03:00:00Z']);

        $this->assertFalse(RunState::isRunning(self::JOB));
        $summary = Runner::readSummary(self::JOB);
        $this->assertSame('CRASHED', $summary['state']);
        $this->assertSame(['FAILED'], array_column(History::list(self::JOB)['runs'], 'state'), 'retention 1 pruned the real run');
        $this->assertSame($stamp, Snapshot::lastSnapshot($summary)['stamp'] ?? null);
        RunState::clear(self::JOB);
        @unlink($path);
    }
}

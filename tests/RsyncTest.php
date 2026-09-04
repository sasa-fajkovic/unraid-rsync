<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Rsync.php: the whitelist option -> argv token mapping (every key),
 * value flags, repeatable exclude/include, all four log levels, LOCAL vs SSH
 * argv composition (SSH pieces injected, not materialised - no live ssh), the
 * exit-code -> state map (incl. SIGTERM 143 -> ABORTED), worst-of reduction,
 * and effective-options resolution under useGlobalDefaults true/false. Every
 * assertion is on the returned ARRAY; nothing spawns rsync.
 */
final class RsyncTest extends TestCase
{
    /**
     * A canonical (full whitelist) options object with EVERY boolean explicitly
     * OFF and every value empty. NB: Config::defaultRsyncOptions() turns some
     * booleans (archive/humanReadable/partial) ON by default, so we cannot use
     * the bare defaults as an "empty" baseline - we force every boolean false.
     */
    private function emptyOpts(): array
    {
        $opts = Config::mergeRsyncOptions([]);
        foreach (Job::BOOL_OPTION_KEYS as $key) {
            $opts[$key] = false;
        }
        return $opts;
    }

    public function testWhitelistKeysMatchJobModel(): void
    {
        // The Rsync flag maps must mirror Job.php's whitelist exactly, or a
        // stored option key could silently never map to a flag (or vice versa).
        $this->assertSame(
            Job::BOOL_OPTION_KEYS,
            array_keys(Rsync::BOOL_FLAGS),
            'Rsync::BOOL_FLAGS keys must equal Job::BOOL_OPTION_KEYS'
        );
        $this->assertSame(
            Job::SCALAR_OPTION_KEYS,
            array_keys(Rsync::SCALAR_FLAGS),
            'Rsync::SCALAR_FLAGS keys must equal Job::SCALAR_OPTION_KEYS'
        );
        $this->assertSame(
            Job::FILTER_TYPES,
            array_keys(Rsync::FILTER_FLAGS),
            'Rsync::FILTER_FLAGS keys must equal Job::FILTER_TYPES'
        );
    }

    public function testEveryBooleanKeyMapsToItsFlag(): void
    {
        foreach (Rsync::BOOL_FLAGS as $key => $flag) {
            $opts = $this->emptyOpts();
            $opts[$key] = true;
            $tokens = Rsync::optionTokens($opts);
            $this->assertContains($flag, $tokens, "key $key should emit $flag");
            // Off => not present.
            $opts[$key] = false;
            $this->assertNotContains($flag, Rsync::optionTokens($opts), "key $key off should NOT emit $flag");
        }
    }

    public function testBooleanTokenOrderFollowsMap(): void
    {
        // Deliberately NOT using archive here: -a additionally emits a --no-*
        // for each implied option left off, which is its own contract (see the
        // testArchive* cases). This case is only about BOOL_FLAGS map order.
        $opts = $this->emptyOpts();
        $opts['compress'] = true; // -z
        $opts['delete']   = true; // --delete
        $opts['mkpath']   = true; // --mkpath
        $tokens = Rsync::optionTokens($opts);
        // compress precedes delete precedes mkpath (map order).
        $this->assertSame(['-z', '--delete', '--mkpath'], $tokens);
    }

    /** -a itself still lands in map order; the negations follow it, not precede. */
    public function testArchiveTokenKeepsItsMapPositionAheadOfLaterFlags(): void
    {
        $opts = $this->emptyOpts();
        $opts['archive']  = true;
        $opts['compress'] = true;
        foreach (array_keys(Rsync::ARCHIVE_IMPLIED) as $key) {
            $opts[$key] = true;   // silence the negations
        }
        $tokens = Rsync::optionTokens($opts);
        $this->assertLessThan(
            array_search('-z', $tokens, true),
            array_search('-a', $tokens, true)
        );
    }

    public function testRecursiveKeyEmitsDashR(): void
    {
        $opts = $this->emptyOpts();
        $opts['recursive'] = true;
        $this->assertContains('-r', Rsync::optionTokens($opts));
    }

    public function testDefaultProfileTokensAreRecursiveNonArchive(): void
    {
        // The shipped defaults must build a recursive, non-archive, non-delete
        // command: -r + -t + -h present; -a and --delete absent.
        $tokens = Rsync::optionTokens(Config::defaultRsyncOptions());
        $this->assertContains('-r', $tokens);
        $this->assertContains('-t', $tokens);
        $this->assertContains('-h', $tokens);
        $this->assertNotContains('-a', $tokens);
        $this->assertNotContains('--delete', $tokens);
        // -O/-J are opt-in only; the shipped default keeps preserving times
        // everywhere (including directories/symlinks) unless the user opts out.
        $this->assertNotContains('-O', $tokens);
        $this->assertNotContains('-J', $tokens);
    }

    public function testOmitDirAndLinkTimesFlags(): void
    {
        // -O/--omit-dir-times and -J/--omit-link-times let a user keep -t
        // (Preserve times) for regular files while opting directories/symlinks
        // out, for remote filesystems that cannot set times on those entries.
        $opts = $this->emptyOpts();
        $opts['omitDirTimes']  = true;
        $opts['omitLinkTimes'] = true;
        $tokens = Rsync::optionTokens($opts);
        $this->assertContains('-O', $tokens, 'omitDirTimes should emit -O');
        $this->assertContains('-J', $tokens, 'omitLinkTimes should emit -J');
    }

    public function testScalarValueFlagsEmitWhenNonEmptyOnly(): void
    {
        $opts = $this->emptyOpts();
        $opts['bwlimit']  = '1000';
        $opts['timeout']  = '300';
        $opts['maxDelete'] = '50';
        $opts['chmod']    = 'D2755,F644';
        $tokens = Rsync::optionTokens($opts);
        $this->assertContains('--bwlimit=1000', $tokens);
        $this->assertContains('--timeout=300', $tokens);
        $this->assertContains('--max-delete=50', $tokens);
        $this->assertContains('--chmod=D2755,F644', $tokens);

        // Empty scalar => omitted: no token begins with the scalar's flag stem.
        $empty = $this->emptyOpts();
        foreach (Rsync::optionTokens($empty) as $tok) {
            $this->assertFalse(
                str_starts_with($tok, '--bwlimit'),
                "no token should start with --bwlimit when bwlimit is empty (got: $tok)"
            );
        }
    }

    public function testBackupDirAlsoAddsBackup(): void
    {
        $opts = $this->emptyOpts();
        $opts['backupDir'] = '/mnt/user/backups/old';
        $tokens = Rsync::optionTokens($opts);
        $this->assertContains('--backup', $tokens);
        $this->assertContains('--backup-dir=/mnt/user/backups/old', $tokens);
        // --backup must come immediately before --backup-dir.
        $iBackup = array_search('--backup', $tokens, true);
        $iDir    = array_search('--backup-dir=/mnt/user/backups/old', $tokens, true);
        $this->assertSame($iDir - 1, $iBackup);
    }

    /**
     * The filter rules must reach the argv in EXACTLY the stored order. rsync
     * acts on the FIRST filter rule that matches, so re-ordering them changes
     * which files transfer - this is the regression guard for issue #128, where
     * every --exclude was emitted before every --include and so `--exclude=*`
     * killed the user's `--include=A*` before it was ever considered.
     */
    public function testFiltersAreEmittedInStoredOrder(): void
    {
        $opts = $this->emptyOpts();
        $opts['filters'] = [
            ['type' => 'include', 'pattern' => '*/'],
            ['type' => 'include', 'pattern' => 'A*'],
            ['type' => 'exclude', 'pattern' => '*'],
        ];
        $tokens = Rsync::optionTokens($opts);

        // The three filter tokens, in order, with nothing reordered between them.
        $filters = array_values(array_filter(
            $tokens,
            static fn(string $t): bool => str_starts_with($t, '--include=') || str_starts_with($t, '--exclude=')
        ));
        $this->assertSame(['--include=*/', '--include=A*', '--exclude=*'], $filters);
    }

    /** The reverse order must survive too - nothing may sort or group by type. */
    public function testFiltersPreserveAnExcludeFirstOrdering(): void
    {
        $opts = $this->emptyOpts();
        $opts['filters'] = [
            ['type' => 'exclude', 'pattern' => '*'],
            ['type' => 'include', 'pattern' => 'A*'],
        ];
        $tokens = Rsync::optionTokens($opts);
        $iExclude = array_search('--exclude=*', $tokens, true);
        $iInclude = array_search('--include=A*', $tokens, true);
        $this->assertIsInt($iExclude);
        $this->assertIsInt($iInclude);
        $this->assertLessThan($iInclude, $iExclude, 'exclude-first ordering must be preserved verbatim');
    }

    public function testFiltersDropEmptyPatternsAndUnknownTypes(): void
    {
        $opts = $this->emptyOpts();
        $opts['filters'] = [
            ['type' => 'exclude', 'pattern' => 'thumbs/'],
            ['type' => 'exclude', 'pattern' => '   '],       // blank -> dropped
            ['type' => 'protect', 'pattern' => 'keep'],      // not whitelisted -> dropped
            ['type' => 'include', 'pattern' => '*.tmp'],
        ];
        $tokens = Rsync::optionTokens(Config::mergeRsyncOptions($opts));
        $this->assertSame(
            ['--exclude=thumbs/', '--include=*.tmp'],
            array_values(array_filter(
                $tokens,
                static fn(string $t): bool => str_starts_with($t, '--include=') || str_starts_with($t, '--exclude=')
            ))
        );
        $this->assertNotContains('--exclude=', $tokens);
        $this->assertNotContains('--protect=keep', $tokens);
    }

    /**
     * -a implies -rlptgoD at PARSE TIME, so simply omitting the positive flag
     * for an unticked box does nothing - the box silently lies. Each unticked
     * implied option must emit its --no-* negation, and that negation only wins
     * if it comes AFTER the -a that set it (rsync man page: "if you specify
     * --no-r -a, the -r option would end up being turned on").
     */
    public function testArchiveNegatesUntickedImpliedOptions(): void
    {
        $opts = $this->emptyOpts();
        $opts['archive'] = true;   // every implied option left OFF
        $tokens = Rsync::optionTokens($opts);

        $iArchive = array_search('-a', $tokens, true);
        $this->assertIsInt($iArchive, '-a must be emitted');

        foreach (Rsync::ARCHIVE_IMPLIED as $key => $noFlag) {
            $i = array_search($noFlag, $tokens, true);
            $this->assertIsInt($i, "unticked '$key' under -a must emit $noFlag");
            $this->assertGreaterThan($iArchive, $i, "$noFlag must come AFTER -a or rsync ignores it");
        }
    }

    public function testArchiveEmitsNoNegationsWhenEveryImpliedOptionIsOn(): void
    {
        $opts = $this->emptyOpts();
        $opts['archive'] = true;
        foreach (array_keys(Rsync::ARCHIVE_IMPLIED) as $key) {
            $opts[$key] = true;
        }
        $tokens = Rsync::optionTokens($opts);
        foreach (Rsync::ARCHIVE_IMPLIED as $noFlag) {
            $this->assertNotContains($noFlag, $tokens);
        }
    }

    /** Without -a nothing is implied, so an unticked option is simply absent. */
    public function testNoNegationsWithoutArchive(): void
    {
        $opts = $this->emptyOpts();   // archive OFF, every implied option OFF
        $tokens = Rsync::optionTokens($opts);
        foreach (Rsync::ARCHIVE_IMPLIED as $noFlag) {
            $this->assertNotContains($noFlag, $tokens, "$noFlag must not appear without -a");
        }
    }

    public function testArchiveImpliedKeysAreAllRealBooleanOptions(): void
    {
        foreach (array_keys(Rsync::ARCHIVE_IMPLIED) as $key) {
            $this->assertArrayHasKey(
                $key,
                Rsync::BOOL_FLAGS,
                "ARCHIVE_IMPLIED key '$key' must be a whitelisted boolean option"
            );
        }
        // Config owns the same list (it cannot depend on Rsync) and the v1 -> v2
        // migration keys off it. If the two drift, an upgraded config would stop
        // matching what -a actually turns on.
        $this->assertSame(
            Config::ARCHIVE_IMPLIED_KEYS,
            array_keys(Rsync::ARCHIVE_IMPLIED),
            'Config::ARCHIVE_IMPLIED_KEYS must equal the keys of Rsync::ARCHIVE_IMPLIED'
        );
    }

    public function testAllScalarKeysHaveDistinctFlags(): void
    {
        $expected = [
            'maxDelete'     => '--max-delete=5',
            'bwlimit'       => '--bwlimit=5',
            'timeout'       => '--timeout=5',
            'contimeout'    => '--contimeout=5',
            'maxSize'       => '--max-size=5',
            'minSize'       => '--min-size=5',
            'chmod'         => '--chmod=5',
            'tempDir'       => '--temp-dir=5',
            'compressLevel' => '--compress-level=5',
            'modifyWindow'  => '--modify-window=5',
            'remoteRsyncPath' => '--rsync-path=5',
        ];
        foreach ($expected as $key => $flag) {
            $opts = $this->emptyOpts();
            $opts[$key] = '5';
            $this->assertContains($flag, Rsync::optionTokens($opts), "scalar $key");
        }
    }

    public function testRemoteRsyncPathEmitsWhenSetAndIsOmittedWhenBlank(): void
    {
        $opts = $this->emptyOpts();
        $opts['remoteRsyncPath'] = '/usr/local/bin/rsync';
        $this->assertContains('--rsync-path=/usr/local/bin/rsync', Rsync::optionTokens($opts));

        foreach (Rsync::optionTokens($this->emptyOpts()) as $tok) {
            $this->assertFalse(
                str_starts_with($tok, '--rsync-path'),
                "no token should start with --rsync-path when remoteRsyncPath is empty (got: $tok)"
            );
        }
    }

    public function testLogLevelFlags(): void
    {
        $this->assertSame(['-q'], Rsync::logLevelFlags('quiet'));
        $this->assertSame(['-v', '--info=stats2,progress2'], Rsync::logLevelFlags('normal'));
        $this->assertSame(['-vv', '--info=progress2,stats2', '--itemize-changes'], Rsync::logLevelFlags('verbose'));
        $this->assertSame(['-vvv', '--debug=all', '--stderr=all'], Rsync::logLevelFlags('debug'));
        // Unknown -> normal default.
        $this->assertSame(Rsync::logLevelFlags('normal'), Rsync::logLevelFlags('bogus'));
    }

    public function testBuildArgvLocalNoSsh(): void
    {
        $opts = $this->emptyOpts();
        $opts['archive'] = true;
        $argv = Rsync::buildArgv($opts, 'normal', '/rt/logs/j/run.log', '/mnt/user/src/', '/mnt/disk1/dst/');
        $this->assertSame(Rsync::rsyncPath(), $argv[0], 'LOCAL: the resolved rsync binary is first');
        $this->assertContains('-a', $argv);
        $this->assertContains('--log-file=/rt/logs/j/run.log', $argv);
        $this->assertNotContains('-e', $argv, 'LOCAL has no -e transport');
        // Operands after the -- terminator, in order.
        $dd = array_search('--', $argv, true);
        $this->assertNotFalse($dd);
        $this->assertSame('/mnt/user/src/', $argv[$dd + 1]);
        $this->assertSame('/mnt/disk1/dst/', $argv[$dd + 2]);
    }

    public function testBuildArgvSshKeyAuthInjectsDashE(): void
    {
        // Simulate the KEY-auth pieces Ssh::materialize hands back: a dashE, no
        // password env.
        $ssh = [
            'dashE'         => "'ssh' '-i' '/tmp/k' '-o' 'BatchMode=yes'",
            'sshEnv' => [],
        ];
        $opts = $this->emptyOpts();
        $argv = Rsync::buildArgv($opts, 'quiet', '/rt/run.log', '/mnt/user/s/', 'user@host:/data/', $ssh);
        $this->assertSame(Rsync::rsyncPath(), $argv[0]);
        $eIdx = array_search('-e', $argv, true);
        $this->assertNotFalse($eIdx, 'SSH transport must inject -e');
        $this->assertSame($ssh['dashE'], $argv[$eIdx + 1]);
        // -e must come before the -- operand terminator.
        $ddIdx = array_search('--', $argv, true);
        $this->assertLessThan($ddIdx, $eIdx);
    }

    public function testBuildArgvSshPasswordAddsNothingToTheArgv(): void
    {
        // PASSWORD auth is carried entirely by the child ENVIRONMENT (the
        // SSH_ASKPASS vars), so there is no wrapper program: rsync is still
        // argv[0], exactly as for KEY auth. Nothing about the password - not
        // the secret, not the passfile path - may appear in the argv.
        $ssh = [
            'dashE'  => "'ssh' '-o' 'PubkeyAuthentication=no'",
            'sshEnv' => [
                'SSH_ASKPASS'         => '/usr/local/emhttp/plugins/unraid.rsync/scripts/askpass.sh',
                'SSH_ASKPASS_REQUIRE' => 'force',
                'UR_ASKPASS_FILE'     => '/tmp/pass/tok',
            ],
        ];
        $argv = Rsync::buildArgv($this->emptyOpts(), 'normal', '/rt/run.log', '/mnt/user/s/', 'user@host:/d/', $ssh);
        $this->assertSame(Rsync::rsyncPath(), $argv[0], 'rsync is argv[0]; nothing wraps it');
        $this->assertContains('-e', $argv);
        foreach (['askpass.sh', '/tmp/pass/tok', 'SSH_ASKPASS'] as $needle) {
            $this->assertEmpty(
                array_filter($argv, fn($t) => strpos((string) $t, $needle) !== false),
                "argv must not carry $needle"
            );
        }
    }

    public function testBuildArgvAppendsDryRun(): void
    {
        $argv = Rsync::buildArgv($this->emptyOpts(), 'normal', '/rt/run.log', '/a/', '/b/', null, true);
        $this->assertContains('--dry-run', $argv);
        // --dry-run before the -- operand terminator.
        $this->assertLessThan(array_search('--', $argv, true), array_search('--dry-run', $argv, true));
    }

    public function testExitToStateMap(): void
    {
        $this->assertSame(Rsync::STATE_SUCCESS, Rsync::exitToState(0));
        $this->assertSame(Rsync::STATE_WARNING, Rsync::exitToState(24));
        $this->assertSame(Rsync::STATE_WARNING, Rsync::exitToState(25));
        $this->assertSame(Rsync::STATE_PARTIAL, Rsync::exitToState(23));
        $this->assertSame(Rsync::STATE_TIMEOUT, Rsync::exitToState(30));
        $this->assertSame(Rsync::STATE_TIMEOUT, Rsync::exitToState(35));
        $this->assertSame(Rsync::STATE_ABORTED, Rsync::exitToState(20));
        $this->assertSame(Rsync::STATE_ABORTED, Rsync::exitToState(143), 'SIGTERM (128+15) -> ABORTED');
        $this->assertSame(Rsync::STATE_FAILED, Rsync::exitToState(1));
        $this->assertSame(Rsync::STATE_FAILED, Rsync::exitToState(12));
        $this->assertSame(Rsync::STATE_FAILED, Rsync::exitToState(255));
    }

    public function testWorstOutcomeReducesToWorstState(): void
    {
        // SUCCESS + WARNING -> WARNING.
        $this->assertSame(Rsync::STATE_WARNING, Rsync::worstOutcome([0, 24])['state']);
        // SUCCESS + FAILED -> FAILED, carrying the failing code.
        $w = Rsync::worstOutcome([0, 12]);
        $this->assertSame(Rsync::STATE_FAILED, $w['state']);
        $this->assertSame(12, $w['exitCode']);
        // ABORTED outranks FAILED.
        $this->assertSame(Rsync::STATE_ABORTED, Rsync::worstOutcome([12, 143])['state']);
        // No pairs -> SUCCESS/0.
        $this->assertSame(['state' => Rsync::STATE_SUCCESS, 'exitCode' => 0], Rsync::worstOutcome([]));
    }

    public function testWorstOutcomeFullSeverityLadder(): void
    {
        // GAP-FILL: the prior test asserts only the WARNING/FAILED/ABORTED edges.
        // Exercise the COMPLETE rank ladder, especially the PARTIAL and TIMEOUT
        // rungs that sit between WARNING and FAILED:
        //   SUCCESS(0) < WARNING(1) < PARTIAL(2) < TIMEOUT(3) < FAILED(4) < ABORTED(5)
        // PARTIAL(23) outranks WARNING(24).
        $this->assertSame(Rsync::STATE_PARTIAL, Rsync::worstOutcome([24, 23])['state']);
        $this->assertSame(23, Rsync::worstOutcome([24, 23])['exitCode']);
        // TIMEOUT(30) outranks PARTIAL(23).
        $w = Rsync::worstOutcome([23, 30]);
        $this->assertSame(Rsync::STATE_TIMEOUT, $w['state']);
        $this->assertSame(30, $w['exitCode']);
        // FAILED(12) outranks TIMEOUT(35).
        $this->assertSame(Rsync::STATE_FAILED, Rsync::worstOutcome([35, 12])['state']);
        // SUCCESS only when every pair is 0.
        $this->assertSame(Rsync::STATE_SUCCESS, Rsync::worstOutcome([0, 0, 0])['state']);
        // A single pair returns that pair's state + exact code.
        $this->assertSame(['state' => Rsync::STATE_TIMEOUT, 'exitCode' => 30], Rsync::worstOutcome([30]));
        // The full ascending mix collapses to ABORTED carrying 20 (its code).
        $full = Rsync::worstOutcome([0, 24, 23, 30, 12, 20]);
        $this->assertSame(Rsync::STATE_ABORTED, $full['state']);
        $this->assertSame(20, $full['exitCode']);
        // An UNKNOWN exit code maps to FAILED (rank 4), so it outranks WARNING.
        $this->assertSame(Rsync::STATE_FAILED, Rsync::worstOutcome([24, 99])['state']);
    }

    public function testEffectiveOptionsUsesGlobalWhenFlagSet(): void
    {
        $global = [
            'defaultRsyncOptions' => Config::mergeRsyncOptions(['compress' => true, 'archive' => false]),
        ];
        $job = Config::defaultJob();
        $job['rsyncOptions'] = Config::mergeRsyncOptions(['compress' => false, 'archive' => true]);

        // useGlobalDefaults = true -> the GLOBAL options win.
        $job['useGlobalDefaults'] = true;
        $eff = Rsync::effectiveOptions($job, $global);
        $this->assertTrue($eff['compress']);
        $this->assertFalse($eff['archive']);

        // useGlobalDefaults = false -> the JOB's own options win.
        $job['useGlobalDefaults'] = false;
        $eff = Rsync::effectiveOptions($job, $global);
        $this->assertFalse($eff['compress']);
        $this->assertTrue($eff['archive']);
    }

    public function testRunDelegatesToInjectedRunner(): void
    {
        $seen = null;
        Rsync::$runner = function (array $argv, $onOutput) use (&$seen): int {
            $seen = $argv;
            $onOutput("line one\n");
            return 23;
        };
        try {
            $out = '';
            $code = Rsync::run(['rsync', '-a', '--', '/a/', '/b/'], function (string $c) use (&$out): void {
                $out .= $c;
            });
            $this->assertSame(23, $code);
            $this->assertSame(['rsync', '-a', '--', '/a/', '/b/'], $seen);
            $this->assertSame("line one\n", $out);
        } finally {
            Rsync::$runner = null;
        }
    }

    // --- rsync presence check (FIX 3: detect, never install) ----------------

    public function testRsyncPathDefaultsToBaseOsLocation(): void
    {
        $this->assertNull(Rsync::$rsyncPathOverride);
        $this->assertSame('/usr/bin/rsync', Rsync::rsyncPath());
        $this->assertSame('/usr/bin/rsync', Rsync::RSYNC_PATH);
    }

    public function testRsyncPathHonoursOverride(): void
    {
        Rsync::$rsyncPathOverride = '/custom/rsync';
        try {
            $this->assertSame('/custom/rsync', Rsync::rsyncPath());
        } finally {
            Rsync::$rsyncPathOverride = null;
        }
    }

    public function testRsyncAvailableTrueForAnExecutable(): void
    {
        // Point the path at a binary that always exists in the test env.
        Rsync::$rsyncPathOverride = PHP_BINARY;
        try {
            $this->assertTrue(Rsync::rsyncAvailable());
        } finally {
            Rsync::$rsyncPathOverride = null;
        }
    }

    public function testRsyncAvailableFalseForMissingBinary(): void
    {
        Rsync::$rsyncPathOverride = '/nonexistent/path/to/rsync';
        try {
            $this->assertFalse(Rsync::rsyncAvailable());
        } finally {
            Rsync::$rsyncPathOverride = null;
        }
        // An empty path is also "not available".
        Rsync::$rsyncPathOverride = '';
        try {
            $this->assertFalse(Rsync::rsyncAvailable());
        } finally {
            Rsync::$rsyncPathOverride = null;
        }
    }

    public function testRsyncMissingMessageIsClearAndInstallFree(): void
    {
        Rsync::$rsyncPathOverride = '/usr/bin/rsync';
        try {
            $msg = Rsync::rsyncMissingMessage();
            $this->assertStringContainsString('/usr/bin/rsync', $msg);
            $this->assertStringContainsString('Unraid', $msg);
            $this->assertStringContainsString('misconfigured', $msg);
            // It must NOT promise to install anything.
            $this->assertStringNotContainsStringIgnoringCase('install', $msg);
        } finally {
            Rsync::$rsyncPathOverride = null;
        }
    }

    public function testRsyncVersionLineEmptyWhenMissing(): void
    {
        Rsync::$rsyncPathOverride = '/nonexistent/path/to/rsync';
        try {
            $this->assertSame('', Rsync::rsyncVersionLine());
        } finally {
            Rsync::$rsyncPathOverride = null;
        }
    }

    public function testRsyncVersionLineReturnsFirstNonEmptyLine(): void
    {
        // Use the version-probe stub so we don't depend on a real rsync binary.
        Rsync::$rsyncPathOverride = PHP_BINARY; // present -> probe is consulted
        try {
            $this->assertSame(
                'rsync  version 3.2.7  protocol version 31',
                RsyncVersionProbeStub::rsyncVersionLine()
            );
        } finally {
            Rsync::$rsyncPathOverride = null;
        }
    }

    // --- rsync DAEMON transport (issue #139) --------------------------------

    /**
     * A deliberately busy, fully explicit option set shared by the golden argv
     * regressions and the daemon argv assertions, so both sides compare like
     * with like: -a with two implied options left ON (they emit no negation)
     * and the rest negated, three scalars, and three filters in an order that
     * only survives if nothing sorts, groups or dedupes them.
     */
    private function busyOpts(): array
    {
        $opts = $this->emptyOpts();
        $opts['archive']         = true;
        $opts['perms']           = true;   // implied by -a and ticked -> no --no-perms
        $opts['times']           = true;   // ditto
        $opts['compress']        = true;
        $opts['delete']          = true;
        $opts['bwlimit']         = '2000';
        $opts['tempDir']         = '/mnt/user/tmp';
        $opts['remoteRsyncPath'] = '/usr/local/bin/rsync';
        $opts['filters']         = [
            ['type' => 'include', 'pattern' => '*/'],
            ['type' => 'include', 'pattern' => 'A*'],
            ['type' => 'exclude', 'pattern' => '*'],
        ];
        return $opts;
    }

    /** The transport-pieces bag Runner hands buildArgv for a DAEMON job. */
    private function daemonPieces(array $over = []): array
    {
        return $over + [
            'daemon'       => true,
            'daemonPort'   => 873,
            'passwordFile' => '/rt/pass/tok',
            // Runner ALWAYS sets this (it is fed to Ssh::childEnv unguarded).
            'sshEnv'       => [],
        ];
    }

    /** A merged DAEMON connection, as Credentials::mergeConnection returns it. */
    private function daemonConn(array $over = []): array
    {
        return $over + [
            'transport' => 'DAEMON',
            'host'      => 'nas.local',
            'username'  => 'moduser',
            'port'      => 873,
        ];
    }

    /**
     * GOLDEN REGRESSION. The SSH argv is frozen byte-for-byte at a3ed950's
     * output: adding DAEMON transport must not move, add or drop a single
     * token on the SSH path. Verified against a checkout of a3ed950, not
     * against the current implementation.
     */
    public function testGoldenSshArgvIsByteIdenticalToTheReleasedBuild(): void
    {
        $argv = Rsync::buildArgv(
            $this->busyOpts(),
            'verbose',
            '/rt/logs/j1/run.log',
            '/mnt/user/src/',
            'bob@nas:/data/',
            ['dashE' => "'ssh' '-i' '/tmp/k'", 'sshEnv' => []],
            false
        );
        $this->assertSame([
            '/usr/bin/rsync',
            '-a',
            '--no-recursive',
            '--no-links',
            '--no-owner',
            '--no-group',
            '--no-D',
            '-z',
            '-t',
            '-p',
            '--delete',
            '--bwlimit=2000',
            '--temp-dir=/mnt/user/tmp',
            '--rsync-path=/usr/local/bin/rsync',
            '--include=*/',
            '--include=A*',
            '--exclude=*',
            '-vv',
            '--info=progress2,stats2',
            '--itemize-changes',
            '--log-file=/rt/logs/j1/run.log',
            '-e',
            "'ssh' '-i' '/tmp/k'",
            '--',
            '/mnt/user/src/',
            'bob@nas:/data/',
        ], $argv);
    }

    /** GOLDEN REGRESSION. Same contract for a LOCAL job: no -e, no daemon flag. */
    public function testGoldenLocalArgvIsByteIdenticalToTheReleasedBuild(): void
    {
        $argv = Rsync::buildArgv(
            $this->busyOpts(),
            'normal',
            '/rt/logs/j2/run.log',
            '/mnt/user/a/',
            '/mnt/disk1/b/',
            null,
            false
        );
        $this->assertSame([
            '/usr/bin/rsync',
            '-a',
            '--no-recursive',
            '--no-links',
            '--no-owner',
            '--no-group',
            '--no-D',
            '-z',
            '-t',
            '-p',
            '--delete',
            '--bwlimit=2000',
            '--temp-dir=/mnt/user/tmp',
            '--rsync-path=/usr/local/bin/rsync',
            '--include=*/',
            '--include=A*',
            '--exclude=*',
            '-v',
            '--info=stats2,progress2',
            '--log-file=/rt/logs/j2/run.log',
            '--',
            '/mnt/user/a/',
            '/mnt/disk1/b/',
        ], $argv);
    }

    /**
     * The whole DAEMON argv, asserted as one array rather than by substring:
     * the filters keep their stored order, the -a negations still sit
     * immediately after the -a they negate, --log-file is still emitted (D18),
     * --contimeout IS emitted here (daemon is the only transport rsync accepts
     * it on), and --port then --password-file occupy exactly the slot -e used
     * to - between --dry-run and the -- terminator.
     */
    public function testBuildArgvDaemonEmitsPortAndPasswordFileInTheDashESlot(): void
    {
        $opts = $this->busyOpts();
        $opts['contimeout'] = '15';
        $argv = Rsync::buildArgv(
            $opts,
            'verbose',
            '/rt/logs/j3/run.log',
            'bob@nas::rsync_bkp/photos/',
            '/mnt/user/dst/',
            $this->daemonPieces(),
            false
        );
        $this->assertSame([
            '/usr/bin/rsync',
            '-a',
            '--no-recursive',
            '--no-links',
            '--no-owner',
            '--no-group',
            '--no-D',
            '-z',
            '-t',
            '-p',
            '--delete',
            '--bwlimit=2000',
            '--contimeout=15',
            '--temp-dir=/mnt/user/tmp',
            '--rsync-path=/usr/local/bin/rsync',
            '--include=*/',
            '--include=A*',
            '--exclude=*',
            '-vv',
            '--info=progress2,stats2',
            '--itemize-changes',
            '--log-file=/rt/logs/j3/run.log',
            '--port=873',
            '--password-file=/rt/pass/tok',
            '--',
            'bob@nas::rsync_bkp/photos/',
            '/mnt/user/dst/',
        ], $argv);
    }

    /**
     * An ANONYMOUS module (no stored secret) emits --port but NO
     * --password-file: an empty password file makes rsync exit 1 with "failed
     * to read a password from %s" (authenticate.c:215), so omitting the flag is
     * the only correct behaviour.
     */
    public function testBuildArgvDaemonAnonymousModuleOmitsThePasswordFile(): void
    {
        $argv = Rsync::buildArgv(
            $this->busyOpts(),
            'normal',
            '/rt/logs/j4/run.log',
            'bob@nas::pub',
            '/mnt/user/dst/',
            $this->daemonPieces(['daemonPort' => 8730, 'passwordFile' => '']),
            true
        );
        $this->assertSame([
            '/usr/bin/rsync',
            '-a',
            '--no-recursive',
            '--no-links',
            '--no-owner',
            '--no-group',
            '--no-D',
            '-z',
            '-t',
            '-p',
            '--delete',
            '--bwlimit=2000',
            '--temp-dir=/mnt/user/tmp',
            '--rsync-path=/usr/local/bin/rsync',
            '--include=*/',
            '--include=A*',
            '--exclude=*',
            '-v',
            '--info=stats2,progress2',
            '--log-file=/rt/logs/j4/run.log',
            '--dry-run',
            '--port=8730',
            '--',
            'bob@nas::pub',
            '/mnt/user/dst/',
        ], $argv);
        foreach ($argv as $tok) {
            $this->assertFalse(
                str_starts_with($tok, '--password-file'),
                "an anonymous module must emit no --password-file (got: $tok)"
            );
        }
    }

    /**
     * STRUCTURAL mutual exclusion. rsync does NOT reject `-e` beside a
     * "host::module" operand - it silently switches to daemon-over-remote-shell
     * (main.c:1435), which would hand the module secret to whatever the default
     * remote shell reaches. So a bag carrying BOTH keys must still emit no -e.
     */
    public function testBuildArgvDaemonNeverEmitsDashEEvenWhenTheBagCarriesOne(): void
    {
        $argv = Rsync::buildArgv(
            $this->emptyOpts(),
            'quiet',
            '/rt/r.log',
            'bob@nas::mod',
            '/mnt/user/dst/',
            $this->daemonPieces(['dashE' => "'ssh' '-i' '/rt/keys/tok'"])
        );
        $this->assertSame([
            '/usr/bin/rsync',
            '-q',
            '--log-file=/rt/r.log',
            '--port=873',
            '--password-file=/rt/pass/tok',
            '--',
            'bob@nas::mod',
            '/mnt/user/dst/',
        ], $argv);
        $this->assertNotContains('-e', $argv, 'daemon transport must never emit -e, at any index');
        $this->assertNotContains("'ssh' '-i' '/rt/keys/tok'", $argv, 'the -e payload must not leak either');
    }

    /**
     * The exclusion is gated on the explicit `daemon` key, NOT on "is there a
     * password file" - an anonymous daemon connection carries neither a passfile
     * nor a port-specific hint, and any emptiness-based gate would let -e back
     * in for exactly that connection. This is the case an emptiness test misses.
     */
    public function testBuildArgvDaemonWithoutAPassFileStillNeverEmitsDashE(): void
    {
        $argv = Rsync::buildArgv(
            $this->emptyOpts(),
            'quiet',
            '/rt/r.log',
            'bob@nas::mod',
            '/mnt/user/dst/',
            $this->daemonPieces(['passwordFile' => '', 'dashE' => "'ssh' '-o' 'BatchMode=yes'"])
        );
        $this->assertSame([
            '/usr/bin/rsync',
            '-q',
            '--log-file=/rt/r.log',
            '--port=873',
            '--',
            'bob@nas::mod',
            '/mnt/user/dst/',
        ], $argv);
        $this->assertNotContains('-e', $argv);
    }

    /**
     * --port is emitted UNCONDITIONALLY for a daemon run, with no "only if it
     * differs from 873" branch, so a wrong port is visible in the run log; a
     * bag that omits it falls back to the rsyncd default rather than to 22.
     */
    public function testBuildArgvDaemonAlwaysEmitsAPortAndDefaultsToRsyncd(): void
    {
        $bag = ['daemon' => true, 'sshEnv' => []];   // no daemonPort, no passwordFile
        $argv = Rsync::buildArgv($this->emptyOpts(), 'quiet', '/rt/r.log', 'bob@nas::m', '/d/', $bag);
        $this->assertSame([
            '/usr/bin/rsync',
            '-q',
            '--log-file=/rt/r.log',
            '--port=' . Credentials::RSYNCD_PORT,
            '--',
            'bob@nas::m',
            '/d/',
        ], $argv);
        $this->assertSame(873, Credentials::RSYNCD_PORT);
    }

    /**
     * D18: --log-file and --rsync-path are NOT suppressed on daemon transport.
     * server_options() never transmits either to the far side, so a daemon's
     * "refuse options" cannot reach them: --log-file stays correct (it is a
     * client-side local log) and --rsync-path is simply inert.
     */
    public function testBuildArgvDaemonKeepsLogFileAndRsyncPath(): void
    {
        $opts = $this->emptyOpts();
        $opts['remoteRsyncPath'] = '/usr/local/bin/rsync';
        $argv = Rsync::buildArgv($opts, 'normal', '/rt/logs/j/run.log', 'b@n::m', '/d/', $this->daemonPieces());
        $this->assertContains('--log-file=/rt/logs/j/run.log', $argv);
        $this->assertContains('--rsync-path=/usr/local/bin/rsync', $argv);
    }

    /**
     * D7. --contimeout is a HARD failure (exit 1, RERR_SYNTAX) on every SSH and
     * LOCAL transfer: main.c:1558 is reached for remote-shell AND local runs,
     * because only the daemon socket path returns earlier at main.c:1550. So it
     * is emitted for DAEMON and dropped everywhere else - and dropping it must
     * change NOTHING else about the argv.
     */
    public function testContimeoutIsEmittedForDaemonTransportOnly(): void
    {
        $plain = $this->busyOpts();
        $withCt = $plain;
        $withCt['contimeout'] = '15';

        $sshBag = ['dashE' => "'ssh'", 'sshEnv' => []];
        // SSH and LOCAL: the argv is identical with and without the stored value.
        $this->assertSame(
            Rsync::buildArgv($plain, 'quiet', '/rt/r.log', '/a/', 'u@h:/b/', $sshBag),
            Rsync::buildArgv($withCt, 'quiet', '/rt/r.log', '/a/', 'u@h:/b/', $sshBag),
            'a stored contimeout must not change the SSH argv at all'
        );
        $this->assertSame(
            Rsync::buildArgv($plain, 'quiet', '/rt/r.log', '/a/', '/b/', null),
            Rsync::buildArgv($withCt, 'quiet', '/rt/r.log', '/a/', '/b/', null),
            'a stored contimeout must not change the LOCAL argv at all'
        );
        foreach (Rsync::buildArgv($withCt, 'quiet', '/rt/r.log', '/a/', 'u@h:/b/', $sshBag) as $tok) {
            $this->assertFalse(str_starts_with($tok, '--contimeout'), "SSH must not emit $tok");
        }
        foreach (Rsync::buildArgv($withCt, 'quiet', '/rt/r.log', '/a/', '/b/', null) as $tok) {
            $this->assertFalse(str_starts_with($tok, '--contimeout'), "LOCAL must not emit $tok");
        }
        // DAEMON keeps it, in its SCALAR_FLAGS map position (after --bwlimit,
        // before --temp-dir).
        $daemon = Rsync::buildArgv($withCt, 'quiet', '/rt/r.log', 'u@h::m', '/b/', $this->daemonPieces());
        $this->assertContains('--contimeout=15', $daemon);
        $this->assertSame(
            array_search('--bwlimit=2000', $daemon, true) + 1,
            array_search('--contimeout=15', $daemon, true)
        );
    }

    /** Dropping contimeout must not mutate the caller's options array. */
    public function testBuildArgvDoesNotMutateTheCallersOptions(): void
    {
        $opts = $this->emptyOpts();
        $opts['contimeout'] = '15';
        Rsync::buildArgv($opts, 'quiet', '/rt/r.log', '/a/', '/b/', null);
        $this->assertSame('15', $opts['contimeout']);
    }

    /**
     * The live "rsync options preview" renders straight from optionTokens(), and
     * its whole reason to exist is that it shows what will really run. So the
     * contimeout gate lives HERE, not in buildArgv: told the transport, the
     * mapper drops exactly what buildArgv would have dropped, and the preview
     * can no longer promise a flag the run throws away.
     */
    public function testOptionTokensDropsContimeoutForEveryNonDaemonTransport(): void
    {
        $opts = $this->emptyOpts();
        $opts['contimeout'] = '30';

        $this->assertSame(['--contimeout=30'], Rsync::optionTokens($opts, 'DAEMON'));
        $this->assertSame([], Rsync::optionTokens($opts, 'SSH'));
        $this->assertSame([], Rsync::optionTokens($opts, 'LOCAL'));
        // A hand-edited/unknown transport behaves like the non-daemon ones -
        // the same way buildArgv treats anything without a `daemon` piece.
        $this->assertSame([], Rsync::optionTokens($opts, 'FTP'));
        // Resolved the way every other transport comparison in the tree is.
        $this->assertSame(['--contimeout=30'], Rsync::optionTokens($opts, ' daemon '));

        // No transport = no context (the Global Settings block, shared by jobs of
        // every transport): today's behaviour, unchanged, which is what keeps the
        // whitelist tests and every one-argument caller green.
        $this->assertSame(['--contimeout=30'], Rsync::optionTokens($opts));
        $this->assertSame(['--contimeout=30'], Rsync::optionTokens($opts, null));
    }

    /** The gate touches --contimeout and nothing else, in either direction. */
    public function testOptionTokensTransportGateTouchesOnlyContimeout(): void
    {
        $opts = $this->busyOpts();
        unset($opts['contimeout']);

        foreach ([null, 'SSH', 'LOCAL', 'DAEMON', 'FTP'] as $transport) {
            $this->assertSame(
                Rsync::optionTokens($opts),
                Rsync::optionTokens($opts, $transport),
                'a job with no contimeout must produce byte-identical tokens on every transport'
            );
        }

        $withCt = $opts;
        $withCt['contimeout'] = '30';
        $this->assertSame(Rsync::optionTokens($opts), Rsync::optionTokens($withCt, 'SSH'));
        $this->assertSame(
            array_merge(Rsync::optionTokens($opts), []),
            Rsync::optionTokens($withCt, 'SSH'),
            'dropping the key must not disturb the order of anything else'
        );
    }

    /** ...and the mapper must not mutate the caller's array when it drops it. */
    public function testOptionTokensDoesNotMutateTheCallersOptions(): void
    {
        $opts = $this->emptyOpts();
        $opts['contimeout'] = '15';
        Rsync::optionTokens($opts, 'SSH');
        $this->assertSame('15', $opts['contimeout']);
    }

    // --- listDaemonModules: the pre-auth module-listing probe ----------------

    /**
     * The probe argv, asserted whole. It ends with `--` then the operand, like
     * every other spawn in this codebase; it carries NO --password-file (a
     * listing is answered BEFORE auth_server(), clientserver.c:1420-1424, so the
     * flag would never be read) and no -e and no whitelist option.
     */
    public function testListDaemonModulesArgvIsMinimalAndCarriesNoPasswordFile(): void
    {
        $seen = null;
        Rsync::$daemonProbeRunner = function (array $argv) use (&$seen): array {
            $seen = $argv;
            return [0, "@RSYNCD: EXIT\n"];
        };
        try {
            Rsync::listDaemonModules($this->daemonConn(['port' => 1873]));
        } finally {
            Rsync::$daemonProbeRunner = null;
        }
        $this->assertSame([
            '/usr/bin/rsync',
            '--contimeout=20',
            '--timeout=20',
            '--port=1873',
            '--',
            'moduser@nas.local::',
        ], $seen);
        $this->assertSame(20, Rsync::DAEMON_PROBE_TIMEOUT);
        $this->assertSame('--', $seen[count($seen) - 2], 'the operand must follow a -- terminator');
        foreach ($seen as $tok) {
            $this->assertFalse(str_starts_with($tok, '--password-file'), 'a listing never reads a password file');
        }
        $this->assertNotContains('-e', $seen);
    }

    /**
     * send_listing() emits one "%-15s\t%s\n" line per listed module, wrapped in
     * the daemon's MOTD and @RSYNCD framing. Only the names are kept.
     */
    public function testListDaemonModulesParsesARealisticListing(): void
    {
        $listing = "Welcome to the NAS\n"
            . "@RSYNCD: 31.0\n"
            . "rsync_bkp      \tBackup share\n"
            . "photos         \tPhotos, read only\n"
            . "media.2        \t\n"
            . "\n"
            . "bad name       \tspaces are not a module name\n"
            . "@RSYNCD: EXIT\n";
        Rsync::$daemonProbeRunner = fn(array $argv): array => [0, $listing];
        try {
            $res = Rsync::listDaemonModules($this->daemonConn());
        } finally {
            Rsync::$daemonProbeRunner = null;
        }
        $this->assertSame(
            [
                'ok'      => true,
                'reason'  => 'ok',
                'message' => 'Connected to the rsync daemon and listed 3 module(s): rsync_bkp, photos, media.2.'
                    . ' NOTE: a module listing is answered BEFORE authentication, so this does NOT verify'
                    . ' the username or the module secret. Run a dry-run to test those.',
                'modules' => ['rsync_bkp', 'photos', 'media.2'],
            ],
            $res
        );
    }

    /** A daemon whose every module is `list = no` still answers, with nothing. */
    public function testListDaemonModulesReportsAnEmptyListing(): void
    {
        Rsync::$daemonProbeRunner = fn(array $argv): array => [0, "@RSYNCD: 31.0\n@RSYNCD: EXIT\n"];
        try {
            $res = Rsync::listDaemonModules($this->daemonConn());
        } finally {
            Rsync::$daemonProbeRunner = null;
        }
        $this->assertSame(
            [
                'ok'      => true,
                'reason'  => 'ok',
                'message' => 'Connected to the rsync daemon, but it listed no public modules'
                    . ' (a module can be hidden with "list = no").'
                    . ' NOTE: a module listing is answered BEFORE authentication, so this does NOT verify'
                    . ' the username or the module secret. Run a dry-run to test those.',
                'modules' => [],
            ],
            $res
        );
    }

    /** A listing is pre-auth, so anyone who can reach the port writes it: cap it. */
    public function testListDaemonModulesCapsTheListingAtTwoHundredNames(): void
    {
        $listing = '';
        for ($i = 0; $i < 500; $i++) {
            $listing .= 'mod' . $i . "\tcomment\n";
        }
        Rsync::$daemonProbeRunner = fn(array $argv): array => [0, $listing];
        try {
            $res = Rsync::listDaemonModules($this->daemonConn());
        } finally {
            Rsync::$daemonProbeRunner = null;
        }
        $this->assertCount(200, $res['modules']);
        $this->assertSame('mod0', $res['modules'][0]);
        $this->assertSame('mod199', $res['modules'][199]);
    }

    #[DataProvider('daemonProbeExitProvider')]
    public function testListDaemonModulesClassifiesEveryExitCode(int $exit, string $reason, string $message): void
    {
        Rsync::$daemonProbeRunner = fn(array $argv): array => [$exit, "some output\n"];
        try {
            $res = Rsync::listDaemonModules($this->daemonConn());
        } finally {
            Rsync::$daemonProbeRunner = null;
        }
        $this->assertSame(
            ['ok' => false, 'reason' => $reason, 'message' => $message, 'modules' => []],
            $res
        );
    }

    public static function daemonProbeExitProvider(): array
    {
        $unreachable = 'Could not reach the rsync daemon. Check the host, the port and the network.';
        $timeout     = 'The rsync daemon did not answer within 20 seconds.';
        $refused     = static fn(int $n): string => 'The rsync daemon answered but refused the request (rsync exit '
            . $n . '). Check that the daemon is really rsyncd and not an SSH server.';
        return [
            'RERR_STARTCLIENT 5'  => [5, 'unreachable', $unreachable],
            'RERR_SOCKETIO 10'    => [10, 'unreachable', $unreachable],
            'RERR_TIMEOUT 30'     => [30, 'timeout', $timeout],
            'RERR_CONTIMEOUT 35'  => [35, 'timeout', $timeout],
            'SIGTERM 143'         => [143, 'timeout', $timeout],
            'RERR_SYNTAX 1'       => [1, 'refused', $refused(1)],
            'RERR_PROTOCOL 2'     => [2, 'refused', $refused(2)],
            'RERR_UNSUPPORTED 4'  => [4, 'refused', $refused(4)],
            'unmapped 12'         => [12, 'error', 'The rsync daemon probe failed (rsync exit 12).'],
            'exec failure 127'    => [127, 'error', 'The rsync daemon probe failed (rsync exit 127).'],
        ];
    }

    /**
     * Every pre-flight refusal happens BEFORE the spawn seam is consulted, so a
     * connection that could produce a dangerous operand never reaches rsync at
     * all: a ':' or '/' in the host or username makes parse_hostspec
     * (options.c:3073-3120) reinterpret "u@nas::mod" as an SSH target or as a
     * local path.
     */
    #[DataProvider('unusableDaemonConnProvider')]
    public function testListDaemonModulesRefusesAnUnusableConnectionWithoutSpawning(array $conn, string $cause): void
    {
        $spawned = false;
        Rsync::$daemonProbeRunner = function (array $argv) use (&$spawned): array {
            $spawned = true;
            return [0, ''];
        };
        try {
            $res = Rsync::listDaemonModules($conn);
        } finally {
            Rsync::$daemonProbeRunner = null;
        }
        $this->assertFalse($spawned, 'a rejected connection must never reach the probe runner');
        $this->assertSame(
            [
                'ok'      => false,
                'reason'  => 'config',
                'message' => 'This Connection is not usable for an rsync daemon probe: ' . $cause,
                'modules' => [],
            ],
            $res
        );
    }

    public static function unusableDaemonConnProvider(): array
    {
        $base = ['transport' => 'DAEMON', 'host' => 'nas.local', 'username' => 'moduser', 'port' => 873];
        return [
            'ssh transport' => [
                ['transport' => 'SSH'] + $base,
                'it does not use rsync daemon (rsyncd) transport.',
            ],
            'legacy record with no transport key' => [
                ['host' => 'nas.local', 'username' => 'moduser', 'port' => 873],
                'it does not use rsync daemon (rsyncd) transport.',
            ],
            'empty host' => [
                ['host' => ''] + $base,
                'it needs both a host and a username.',
            ],
            'empty username' => [
                ['username' => ''] + $base,
                'it needs both a host and a username.',
            ],
            'host smuggling an ssh hostspec' => [
                ['host' => 'a:b@evil.example'] + $base,
                'the host is not valid for an rsync daemon operand.',
            ],
            'host with a slash' => [
                ['host' => 'nas.local/evil'] + $base,
                'the host is not valid for an rsync daemon operand.',
            ],
            'username with a colon' => [
                ['username' => 'a:b'] + $base,
                'the username is not valid for an rsync daemon operand.',
            ],
        ];
    }

    // --- listDaemonModules: the live proc_open path --------------------------
    // These two spawn a FAKE rsync (a tiny PHP stub), never a real one: the byte
    // cap and the wall-clock deadline live in the spawn arm, below the
    // $daemonProbeRunner seam, so the seam cannot exercise them.

    /** @var array<int,string> fake rsync stubs to unlink */
    private array $fakeBins = [];

    protected function tearDown(): void
    {
        Rsync::$daemonProbeRunner = null;
        Rsync::$rsyncPathOverride = null;
        foreach ($this->fakeBins as $path) {
            @unlink($path);
        }
        $this->fakeBins = [];
    }

    /** Write an executable stub that stands in for the rsync binary. */
    private function fakeRsync(string $phpBody): string
    {
        $dir = UR_RUNTIME_BASE . '/rsync-probe-stub';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = $dir . '/fake-rsync-' . bin2hex(random_bytes(4));
        file_put_contents($path, '#!' . PHP_BINARY . "\n<?php\n" . $phpBody . "\n");
        chmod($path, 0755);
        $this->fakeBins[] = $path;
        return $path;
    }

    /**
     * The output cap is applied INSIDE the drain callback, not by trimming the
     * buffer afterwards - a hostile daemon can stream an unbounded MOTD at a
     * php-fpm worker. The stub emits exactly DAEMON_PROBE_MAX_BYTES of listing
     * (one real module + 4095 * 16 bytes of noise) and then one more module
     * line, which must therefore never be captured at all.
     */
    public function testListDaemonModulesCapsCapturedOutputInsideTheChunkCallback(): void
    {
        $this->assertSame(65536, Rsync::DAEMON_PROBE_MAX_BYTES);
        $this->assertSame(16 + (4095 * 16), Rsync::DAEMON_PROBE_MAX_BYTES);

        Rsync::$rsyncPathOverride = $this->fakeRsync(<<<'PHPSTUB'
fwrite(STDOUT, "keepmod\tcomment\n");                    // 16 bytes
fwrite(STDOUT, str_repeat("noise line here\n", 4095));   // 65520 bytes -> 65536
fwrite(STDOUT, "toolate\tz\n");                          // entirely past the cap
exit(0);
PHPSTUB);
        $res = Rsync::listDaemonModules($this->daemonConn());

        $this->assertTrue($res['ok'], 'the stub exits 0, so the probe succeeded');
        $this->assertSame(['keepmod'], $res['modules'], 'nothing past the byte cap may be captured');
    }

    /**
     * A daemon that accepts the TCP connection and then says nothing must not
     * hold the php-fpm worker: the probe's HARD wall-clock deadline
     * (DAEMON_PROBE_TIMEOUT, a fixed constant - never the connection's stored
     * connectTimeout, which clamps to 600) breaks the drain loop and kills the
     * child. Costs ~20s of wall clock; it is the only end-to-end proof that the
     * deadline is plumbed through to ProcIO::drainPipes.
     */
    public function testListDaemonModulesHonoursItsHardWallClockDeadline(): void
    {
        // Sleeps well past the deadline and would exit 0 (reason 'ok') if the
        // deadline were dropped, so this fails on both the clock and the reason.
        Rsync::$rsyncPathOverride = $this->fakeRsync('sleep(45); exit(0);');

        $started = microtime(true);
        $res     = Rsync::listDaemonModules($this->daemonConn());
        $elapsed = microtime(true) - $started;

        $this->assertSame(
            [
                'ok'      => false,
                'reason'  => 'timeout',
                'message' => 'The rsync daemon did not answer within 20 seconds.',
                'modules' => [],
            ],
            $res
        );
        $this->assertGreaterThanOrEqual(15.0, $elapsed, 'it must actually wait for the deadline');
        $this->assertLessThan(30.0, $elapsed, 'it must not wait for the child to exit on its own');
    }

    /**
     * The deadline must bound the CALL, not just the drain loop. drainPipes
     * returns the instant both pipes hit EOF - which is NOT the same as the
     * child having exited. A child that closes stdout and stderr and then
     * lingers ends the drain immediately, leaving proc_close() to block until it
     * finally goes away: in a php-fpm worker that is exactly the wedge
     * DAEMON_PROBE_TIMEOUT exists to prevent, and the previous
     * deadline-gated kill never fired for it because the deadline had not
     * passed. The stub above keeps its pipes open and so passes either way; this
     * one does not.
     */
    public function testListDaemonModulesKillsAChildThatClosesItsPipesAndLingers(): void
    {
        Rsync::$rsyncPathOverride = $this->fakeRsync(<<<'PHPSTUB'
fwrite(STDOUT, "keepmod	comment
");
fclose(STDOUT);
fclose(STDERR);
sleep(45);
exit(0);
PHPSTUB);

        $started = microtime(true);
        $res     = Rsync::listDaemonModules($this->daemonConn());
        $elapsed = microtime(true) - $started;

        // Un-bounded, this returns reason 'ok' after ~45s (the stub exits 0).
        $this->assertSame(
            [
                'ok'      => false,
                'reason'  => 'timeout',
                'message' => 'The rsync daemon did not answer within 20 seconds.',
                'modules' => [],
            ],
            $res
        );
        $this->assertGreaterThanOrEqual(15.0, $elapsed, 'it must wait for its own deadline, not EOF');
        $this->assertLessThan(30.0, $elapsed, 'proc_close() must never wait out a lingering child');
    }

    /**
     * ...and the bounded wait must not slow down the ordinary case: a child that
     * closes its pipes and exits promptly is reaped promptly.
     */
    public function testListDaemonModulesReturnsAsSoonAsAWellBehavedChildExits(): void
    {
        Rsync::$rsyncPathOverride = $this->fakeRsync(<<<'PHPSTUB'
fwrite(STDOUT, "keepmod	comment
");
exit(0);
PHPSTUB);

        $started = microtime(true);
        $res     = Rsync::listDaemonModules($this->daemonConn());
        $elapsed = microtime(true) - $started;

        $this->assertTrue($res['ok'], json_encode($res));
        $this->assertSame(['keepmod'], $res['modules']);
        $this->assertLessThan(5.0, $elapsed, 'the poll must not add measurable latency');
    }
}

/**
 * Test double that overrides the (protected) version-probe seam so
 * rsyncVersionLine() can be asserted without spawning a process.
 */
final class RsyncVersionProbeStub extends Rsync
{
    protected static function runVersionProbe(string $rsyncPath): string
    {
        return "\nrsync  version 3.2.7  protocol version 31\nCopyright (C) ...\n";
    }
}

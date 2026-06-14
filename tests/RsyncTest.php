<?php

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
            Job::LIST_OPTION_KEYS,
            array_keys(Rsync::LIST_FLAGS),
            'Rsync::LIST_FLAGS keys must equal Job::LIST_OPTION_KEYS'
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
        $opts = $this->emptyOpts();
        $opts['archive']  = true; // -a
        $opts['compress'] = true; // -z
        $opts['delete']   = true; // --delete
        $tokens = Rsync::optionTokens($opts);
        // archive precedes compress precedes delete (map order).
        $this->assertSame(['-a', '-z', '--delete'], $tokens);
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

    public function testRepeatableExcludesAndIncludes(): void
    {
        $opts = $this->emptyOpts();
        $opts['excludes'] = ['thumbs/', '*.tmp', ''];   // empty entry dropped
        $opts['includes'] = ['keep/'];
        $tokens = Rsync::optionTokens($opts);
        $this->assertContains('--exclude=thumbs/', $tokens);
        $this->assertContains('--exclude=*.tmp', $tokens);
        $this->assertContains('--include=keep/', $tokens);
        // No empty-valued exclude leaked through.
        $this->assertNotContains('--exclude=', $tokens);
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
        ];
        foreach ($expected as $key => $flag) {
            $opts = $this->emptyOpts();
            $opts[$key] = '5';
            $this->assertContains($flag, Rsync::optionTokens($opts), "scalar $key");
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
        $this->assertSame(Rsync::rsyncPath(), $argv[0], 'LOCAL: no sshpass prefix, the resolved rsync binary is first');
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
        // sshpass prefix.
        $ssh = [
            'dashE'         => "'ssh' '-i' '/tmp/k' '-o' 'BatchMode=yes'",
            'sshpassPrefix' => [],
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

    public function testBuildArgvSshPasswordPrependsSshpassPrefix(): void
    {
        // PASSWORD-auth pieces: a sshpass prefix wraps the WHOLE rsync argv.
        $ssh = [
            'dashE'         => "'ssh' '-o' 'PubkeyAuthentication=no'",
            'sshpassPrefix' => ['/usr/bin/sshpass', '-f', '/tmp/pass/tok'],
        ];
        $opts = $this->emptyOpts();
        $argv = Rsync::buildArgv($opts, 'normal', '/rt/run.log', '/mnt/user/s/', 'user@host:/d/', $ssh);
        $this->assertSame(['/usr/bin/sshpass', '-f', '/tmp/pass/tok'], array_slice($argv, 0, 3));
        $this->assertSame(Rsync::rsyncPath(), $argv[3], 'the rsync binary follows the sshpass prefix');
        $this->assertContains('-e', $argv);
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

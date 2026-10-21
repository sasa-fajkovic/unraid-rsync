<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for Job.php: normalisation (whitelist -> stored shape), validation, and
 * the path guardrails that protect the boot drive and array/pool roots.
 */
final class JobTest extends TestCase
{
    /** A minimal valid LOCAL job (both sides are local sub-paths). */
    private function validLocalJob(array $overrides = []): array
    {
        return Job::normalize(array_merge([
            'name'      => 'backup',
            'enabled'   => true,
            'schedule'  => '0 3 * * *',
            'transport' => 'LOCAL',
            'direction' => 'PUSH',
            'pairs'     => [['local' => '/mnt/user/media/', 'remote' => '/mnt/disk1/backup/media/']],
            'logLevel'  => 'normal',
            'notifyMode' => 'failure-only',
        ], $overrides));
    }

    // --- happy path --------------------------------------------------------

    public function testValidJobPasses(): void
    {
        $res = Job::validate($this->validLocalJob());
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
        $this->assertSame([], $res['errors']);
    }

    public function testValidSshJobWithRemotePathPasses(): void
    {
        $job = Job::normalize([
            'name'         => 'remote',
            'schedule'     => '15 2 * * 1-5',
            'transport'    => 'SSH',
            'direction'    => 'PUSH',
            'connectionId' => 'c-rpi',
            'pairs'        => [['local' => '/mnt/user/docs/', 'remote' => '/srv/backup/docs/']],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    public function testIdSluggedFromName(): void
    {
        $job = Job::normalize(['name' => 'My Music!!']);
        $this->assertSame('j-my-music', $job['id']);
    }

    public function testSuppliedSafeIdKept(): void
    {
        // A pre-existing safe slug id (incl. dots/underscores within the token)
        // is preserved verbatim.
        $job = Job::normalize(['name' => 'x', 'id' => 'j-My_job.2-3']);
        $this->assertSame('j-My_job.2-3', $job['id']);
    }

    /**
     * SEC-01: a crafted id (traversal, shell metachars, NUL, overlong, or a
     * pure-dots segment) must never persist - normalize() regenerates from the
     * name instead, so downstream filesystem helpers never see it.
     */
    #[DataProvider('craftedIdProvider')]
    public function testCraftedIdRegeneratedFromName(string $badId): void
    {
        $job = Job::normalize(['name' => 'safe name', 'id' => $badId]);
        $this->assertSame('j-safe-name', $job['id']);
    }

    /** @return array<string,array{0:string}> */
    public static function craftedIdProvider(): array
    {
        return [
            'dotdot'       => ['..'],
            'dot'          => ['.'],
            'slash'        => ['../../etc'],
            'shell-meta'   => ['j-a; rm -rf /'],
            'nul-byte'     => ["j-a\0b"],
            // Control bytes must be rejected BEFORE trim() laundered them away
            // (trailing newline/NUL), matching ur_safe_job_id's ordering.
            'trailing-nl'  => ["j-ok\n"],
            'trailing-nul' => ["j-ok\0"],
            'overlong'     => [str_repeat('a', 129)],
        ];
    }

    public function testOmittedScheduleKeepsDefault(): void
    {
        // A minimal job that omits schedule should keep the sensible default,
        // not become the always-invalid empty string.
        $job = Job::normalize(['name' => 'minimal']);
        $this->assertSame('0 3 * * *', $job['schedule']);
        // ...and a job with an explicit schedule keeps it.
        $job2 = Job::normalize(['name' => 'x', 'schedule' => '15 4 * * 0']);
        $this->assertSame('15 4 * * 0', $job2['schedule']);
    }

    // --- required-field validation ----------------------------------------

    public function testMissingNameRejected(): void
    {
        $res = Job::validate($this->validLocalJob(['name' => '']));
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'name') !== false));
    }

    public function testNoPairsRejected(): void
    {
        $res = Job::validate($this->validLocalJob(['pairs' => []]));
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'pair') !== false));
    }

    public function testEmptyPairSidesRejected(): void
    {
        // A pair with a local but empty remote is invalid.
        $job = $this->validLocalJob();
        $job['pairs'] = [['local' => '/mnt/user/a/', 'remote' => '']];
        $res = Job::validate($job);
        $this->assertFalse($res['valid']);
    }

    #[DataProvider('invalidCronProvider')]
    public function testInvalidCronRejected(string $cron): void
    {
        $res = Job::validate($this->validLocalJob(['schedule' => $cron]));
        $this->assertFalse($res['valid'], "cron '$cron' should be invalid");
    }

    public function testManualOnlyJobSkipsScheduleValidation(): void
    {
        // A manual-only job is never scheduled, so an empty/garbage schedule must
        // NOT fail validation.
        $job = Job::normalize($this->validLocalJob(['manualOnly' => true, 'schedule' => '']));
        $this->assertTrue($job['manualOnly']);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'manual-only job must validate without a cron schedule: ' . implode('; ', $res['errors']));
    }

    public function testNormalizeDefaultsManualOnlyFalse(): void
    {
        $job = Job::normalize($this->validLocalJob());
        $this->assertFalse($job['manualOnly']);
    }

    public static function invalidCronProvider(): array
    {
        return [
            'empty'        => [''],
            'too few'      => ['0 3 * *'],
            'too many'     => ['0 3 * * * *'],
            'bad minute'   => ['60 3 * * *'],
            'bad hour'     => ['0 24 * * *'],
            'bad dom'      => ['0 3 32 * *'],
            'bad month'    => ['0 3 * 13 *'],
            'garbage'      => ['* * * * x'],
            'bad range'    => ['5-2 * * * *'],
        ];
    }

    #[DataProvider('validCronProvider')]
    public function testValidCronAccepted(string $cron): void
    {
        $this->assertTrue(Job::isValidCron($cron), "cron '$cron' should be valid");
    }

    public static function validCronProvider(): array
    {
        return [
            'every minute'    => ['* * * * *'],
            'daily 3am'       => ['0 3 * * *'],
            'step'            => ['*/15 * * * *'],
            'list'            => ['0 0,6,12,18 * * *'],
            'range'           => ['0 9-17 * * 1-5'],
            'named month'     => ['0 0 1 jan *'],
            'named dow'       => ['0 0 * * sun'],
            'sunday as 7'     => ['0 0 * * 7'],
        ];
    }

    public function testInvalidEnumsCoercedToDefaults(): void
    {
        // normalize() coerces unknown enum values back to safe defaults.
        $job = Job::normalize([
            'name'       => 'x',
            'transport'  => 'FTP',
            'direction'  => 'SIDEWAYS',
            'notifyMode' => 'maybe',
            'logLevel'   => 'loud',
        ]);
        $this->assertSame('SSH', $job['transport']);
        $this->assertSame('PUSH', $job['direction']);
        $this->assertSame('failure-only', $job['notifyMode']);
        // An unrecognised level falls back to 'normal', NOT to the (quieter)
        // new-job default: a job must never get quieter than the user asked for.
        $this->assertSame('normal', $job['logLevel']);
    }

    public function testNormalizeAcceptsEveryDeclaredLogLevel(): void
    {
        foreach (Job::LOG_LEVELS as $lvl) {
            $job = Job::normalize(['name' => 'x', 'logLevel' => $lvl]);
            $this->assertSame($lvl, $job['logLevel'], "$lvl must survive normalize()");
        }
        // 'summary' is the one the new-job default relies on.
        $this->assertContains('summary', Job::LOG_LEVELS);
    }

    // --- PATH GUARDRAILS ---------------------------------------------------

    #[DataProvider('forbiddenLocalPathProvider')]
    public function testForbiddenLocalSourceRejected(string $path): void
    {
        $job = $this->validLocalJob();
        $job['pairs'] = [['local' => $path, 'remote' => '/mnt/disk1/backup/x/']];
        $res = Job::validate($job);
        $this->assertFalse($res['valid'], "local source '$path' must be rejected");
    }

    #[DataProvider('forbiddenLocalPathProvider')]
    public function testForbiddenLocalDestRejected(string $path): void
    {
        // Under LOCAL transport the destination is also guardrail-checked.
        $job = $this->validLocalJob();
        $job['pairs'] = [['local' => '/mnt/user/safe/', 'remote' => $path]];
        $res = Job::validate($job);
        $this->assertFalse($res['valid'], "local dest '$path' must be rejected");
    }

    public static function forbiddenLocalPathProvider(): array
    {
        return [
            'root'             => ['/'],
            'boot'             => ['/boot'],
            'boot subdir'      => ['/boot/config'],
            'etc'              => ['/etc'],
            'usr'              => ['/usr/local'],
            'var'              => ['/var/log'],
            'mnt bare'         => ['/mnt'],
            'mnt user'         => ['/mnt/user'],
            'mnt user0'        => ['/mnt/user0'],
            'pool root cache'  => ['/mnt/cache'],
            'pool root disk1'  => ['/mnt/disk1'],
            'mnt user slash'   => ['/mnt/user/'],
            'traversal escape' => ['/mnt/user/../../etc'],
            'relative'         => ['relative/path'],
        ];
    }

    public function testAllowedLocalSubPathAccepted(): void
    {
        // A two-level sub-path under a share / pool is fine.
        foreach (['/mnt/user/media/', '/mnt/cache/appdata/', '/mnt/disk1/backup/'] as $p) {
            $errs = Job::checkLocalPath($p, 'x');
            $this->assertSame([], $errs, "path '$p' should be allowed");
        }
    }

    public function testDeleteWithoutSpecificDestRejected(): void
    {
        // SSH transport, --delete on, destination is the filesystem root -> reject.
        $job = Job::normalize([
            'name'      => 'del',
            'schedule'  => '0 3 * * *',
            'transport' => 'SSH',
            'pairs'     => [['local' => '/mnt/user/a/', 'remote' => '/']],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '100'],
        ]);
        $res = Job::validate($job);
        $this->assertFalse($res['valid']);
    }

    public function testDeleteWithSpecificDestPasses(): void
    {
        $job = Job::normalize([
            'name'         => 'del',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'connectionId' => 'c-rpi',
            'pairs'        => [['local' => '/mnt/user/a/', 'remote' => '/srv/backup/a/']],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '100'],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    public function testDeleteWithoutMaxDeleteWarns(): void
    {
        $job = Job::normalize([
            'name'         => 'del',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'connectionId' => 'c-rpi',
            'pairs'        => [['local' => '/mnt/user/a/', 'remote' => '/srv/backup/a/']],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => ''],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid']);                       // warning, not error
        $this->assertNotEmpty($res['warnings']);
        $this->assertNotEmpty(array_filter($res['warnings'], fn($w) => stripos($w, 'max delete') !== false));
    }

    public function testRemoteRootPathRejected(): void
    {
        $job = Job::normalize([
            'name'      => 'r',
            'schedule'  => '0 3 * * *',
            'transport' => 'SSH',
            'pairs'     => [['local' => '/mnt/user/a/', 'remote' => '/']],
        ]);
        $res = Job::validate($job);
        $this->assertFalse($res['valid']);
    }

    public function testLocalTransportErrorLabelsAreNotRemote(): void
    {
        // A LOCAL job whose second path is invalid should NOT describe it as
        // "(remote)" - both paths are on this box.
        $job = Job::normalize([
            'name'      => 'local',
            'schedule'  => '0 3 * * *',
            'transport' => 'LOCAL',
            'pairs'     => [['local' => '/mnt/user/a/', 'remote' => '/boot']],
        ]);
        $res = Job::validate($job);
        $this->assertFalse($res['valid']);
        $joined = implode(' | ', $res['errors']);
        $this->assertStringNotContainsString('(remote)', $joined);
        $this->assertStringContainsString('(local)', $joined);
    }

    public function testLocalPathOutsideMntMessageIsClean(): void
    {
        // The "outside /mnt" message must not read like a "/." typo.
        $errs = Job::checkLocalPath('/srv/data/x/', 'Test path');
        $this->assertNotEmpty($errs);
        $this->assertStringNotContainsString('/mnt/.', $errs[0]);
        $this->assertStringContainsString('sub-directory of /mnt', $errs[0]);
    }

    public function testLocalTransportCoercesDirectionToPush(): void
    {
        // Direction only applies to SSH; a LOCAL job must persist PUSH.
        $job = Job::normalize([
            'name'      => 'local-pull',
            'transport' => 'LOCAL',
            'direction' => 'PULL',
            'pairs'     => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/']],
        ]);
        $this->assertSame('PUSH', $job['direction']);
    }

    public function testPushDeleteToRemoteRootRejected(): void
    {
        // SSH + PUSH: destination is the remote side. --delete to a remote root
        // must be rejected (the destination-subpath rule targets the remote).
        $job = Job::normalize([
            'name'      => 'push-del',
            'schedule'  => '0 3 * * *',
            'transport' => 'SSH',
            'direction' => 'PUSH',
            'pairs'     => [['local' => '/mnt/user/a/', 'remote' => '/']],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '10'],
        ]);
        $res = Job::validate($job);
        $this->assertFalse($res['valid']);
    }

    public function testPullDeleteDoesNotFlagRemoteSourceAsDestination(): void
    {
        // SSH + PULL: the remote side is the SOURCE. A valid remote source +
        // valid local destination with --delete must be accepted - the
        // destination-subpath rule must target the local side, not the remote.
        $job = Job::normalize([
            'name'         => 'pull-src',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PULL',
            'connectionId' => 'c-rpi',
            'pairs'        => [['local' => '/mnt/user/restore/', 'remote' => '/srv/data/']],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '10'],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    public function testPullDeleteWithSpecificLocalDestPasses(): void
    {
        // SSH + PULL into a specific local sub-dir with --delete -> valid.
        $job = Job::normalize([
            'name'         => 'pull-ok',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PULL',
            'connectionId' => 'c-rpi',
            'pairs'        => [['local' => '/mnt/user/restore/data/', 'remote' => '/srv/data/']],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '10'],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    // --- WHITELIST -> stored shape -----------------------------------------

    public function testNonWhitelistedOptionsDropped(): void
    {
        $opts = Job::normalizeRsyncOptions([
            'archive'        => true,
            'compress'       => '1',
            'rsh'            => 'ssh -i /evil/key',   // not whitelisted
            'rsyncPath'      => '/evil',              // not whitelisted
            'removeSource'   => true,                 // not whitelisted
            'filesFrom'      => '/x',                 // not whitelisted
            'excludes'       => ['thumbs/', 'cache/'], // v1 key, gone in v2
            'filters'        => [
                ['type' => 'exclude', 'pattern' => 'thumbs/'],
                ['type' => 'exclude', 'pattern' => ''],   // blank -> stripped
                ['type' => 'exclude', 'pattern' => 'cache/'],
            ],
            'maxDelete'      => '50',
        ]);

        // Only whitelist keys remain.
        $expectedKeys = array_keys(Config::defaultRsyncOptions());
        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($opts));

        // Coercions.
        $this->assertTrue($opts['archive']);
        $this->assertTrue($opts['compress']);   // '1' -> true
        $this->assertSame('50', $opts['maxDelete']);
        // Blank filter row stripped, order preserved.
        $this->assertSame([
            ['type' => 'exclude', 'pattern' => 'thumbs/'],
            ['type' => 'exclude', 'pattern' => 'cache/'],
        ], $opts['filters']);
        // The v1 excludes/includes keys are no longer part of the whitelist.
        $this->assertArrayNotHasKey('excludes', $opts);
        // Dangerous keys absent.
        $this->assertArrayNotHasKey('rsh', $opts);
        $this->assertArrayNotHasKey('rsyncPath', $opts);
        $this->assertArrayNotHasKey('removeSource', $opts);
        $this->assertArrayNotHasKey('filesFrom', $opts);
    }

    public function testBooleanCoercionVariants(): void
    {
        $this->assertTrue(Job::toBool('on'));
        $this->assertTrue(Job::toBool('1'));
        $this->assertTrue(Job::toBool('true'));
        $this->assertTrue(Job::toBool(1));
        $this->assertTrue(Job::toBool(true));
        $this->assertFalse(Job::toBool('0'));
        $this->assertFalse(Job::toBool(''));
        $this->assertFalse(Job::toBool('off'));
        $this->assertFalse(Job::toBool(0));
        $this->assertFalse(Job::toBool(null));
    }

    public function testNormalizeDropsEmptyTemplatePairRow(): void
    {
        $pairs = Job::normalizePairs([
            ['local' => '', 'remote' => ''],            // empty template row -> dropped
            ['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/'],
        ]);
        $this->assertCount(1, $pairs);
        $this->assertSame('/mnt/user/a/', $pairs[0]['local']);
    }

    // --- mandatory connection for SSH jobs ---------------------------------

    public function testSshJobWithoutConnectionRejected(): void
    {
        // An SSH job with no connectionId must be rejected (there is no host /
        // auth to build the transport from).
        $job = Job::normalize([
            'name'      => 'ssh-noconn',
            'schedule'  => '0 3 * * *',
            'transport' => 'SSH',
            'direction' => 'PUSH',
            'pairs'     => [['local' => '/mnt/user/docs/', 'remote' => '/srv/backup/docs/']],
        ]);
        $this->assertSame('', $job['connectionId']);
        $res = Job::validate($job);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'connection') !== false));
    }

    public function testLocalJobWithoutConnectionAllowed(): void
    {
        // LOCAL transport never uses a connection, so an empty connectionId is
        // fine (the rest of the job is valid).
        $job = $this->validLocalJob();
        $this->assertSame('', $job['connectionId']);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    public function testSshJobWithConnectionAccepted(): void
    {
        $job = Job::normalize([
            'name'         => 'ssh-ok',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PUSH',
            'connectionId' => 'c-rpi',
            'pairs'        => [['local' => '/mnt/user/docs/', 'remote' => '/srv/backup/docs/']],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    public function testSshJobWithMissingConnectionRejectedWhenCredsSupplied(): void
    {
        // When a credentials structure is supplied, a connectionId that does not
        // reference an existing connection is rejected (server source of truth).
        $creds = Credentials::defaults();
        $creds['connections'][] = ['id' => 'c-known', 'name' => 'known'];

        $job = Job::normalize([
            'name'         => 'ssh-ghost',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PUSH',
            'connectionId' => 'c-ghost',
            'pairs'        => [['local' => '/mnt/user/docs/', 'remote' => '/srv/backup/docs/']],
        ]);
        $res = Job::validate($job, $creds);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'does not exist') !== false));

        // ...and the SAME job validates when the connection DOES exist.
        $job['connectionId'] = 'c-known';
        $res2 = Job::validate($job, $creds);
        $this->assertTrue($res2['valid'], 'errors: ' . implode(' | ', $res2['errors']));
    }

    // --- VAL-01: scalar option validation ----------------------------------

    /**
     * Integer scalars must be whole numbers; a non-numeric value is rejected at
     * save time rather than failing the rsync run mid-flight.
     */
    #[DataProvider('integerScalarProvider')]
    public function testNonNumericIntegerScalarRejected(string $key, string $flag): void
    {
        $job = $this->validLocalJob(['rsyncOptions' => [$key => 'abc']]);
        $res = Job::validate($job);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, $flag) !== false));

        // a valid whole number passes
        $ok = Job::validate($this->validLocalJob(['rsyncOptions' => [$key => '42']]));
        $this->assertTrue($ok['valid'], 'errors: ' . implode(' | ', $ok['errors']));
    }

    #[DataProvider('remoteProgramPathProvider')]
    public function testRemoteRsyncPathValueIsConstrainedToABareAbsolutePath(string $value, bool $ok): void
    {
        $res = Job::validate($this->validLocalJob(['rsyncOptions' => ['remoteRsyncPath' => $value]]));
        if ($ok) {
            $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
            return;
        }
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter(
            $res['errors'],
            static fn($e) => stripos($e, '--rsync-path') !== false
        ));
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function remoteProgramPathProvider(): array
    {
        return [
            'blank is optional'    => ['', true],
            'usr bin'              => ['/usr/bin/rsync', true],
            'usr local bin'        => ['/usr/local/bin/rsync', true],
            'opt with dash'        => ['/opt/pkg-tools/bin/rsync', true],
            'relative'             => ['rsync', false],
            'sudo prefix'          => ['sudo rsync', false],
            'command separator'    => ['rsync; rm -rf /', false],
            'and-and'              => ['/usr/bin/rsync && touch /tmp/x', false],
            'pipe'                 => ['/usr/bin/rsync | sh', false],
            'backtick'             => ['/usr/bin/`whoami`', false],
            'dollar expansion'     => ['/usr/bin/$FOO', false],
            'traversal'            => ['/usr/../bin/rsync', false],
            'trailing slash'       => ['/usr/bin/', false],
            'newline'              => ["/usr/bin/rsync\ntouch /tmp/x", false],
            'trailing newline'     => ["/usr/bin/rsync\n", true],
            'dot segment'          => ['/usr/./bin/rsync', false],
            'bare dot'             => ['/.', false],
            'over length'          => ['/usr/bin/' . str_repeat('r', 4100), false],
        ];
    }

    /**
     * A colon is a legal POSIX filename character, so "::" appears in perfectly
     * good absolute paths. Testing the raw string for it would fail an
     * ALREADY-SAVED job at run time, because Runner::guardrailErrors shares
     * checkRemotePath. Guards the fix for that regression.
     */
    #[DataProvider('colonBearingAbsolutePathProvider')]
    public function testAbsolutePathContainingColonsIsNotTreatedAsADaemonAddress(string $remote): void
    {
        $job = Job::normalize([
            'name'         => 'nas',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PUSH',
            'connectionId' => 'c-nas',
            'pairs'        => [['local' => '/mnt/user/media/', 'remote' => $remote]],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));

        // ...and the run-time guardrail, which is what would break a saved job.
        $this->assertSame([], Runner::guardrailErrors(
            ['direction' => 'PUSH'],
            ['local' => '/mnt/user/media/', 'remote' => $remote],
            'SSH',
            []
        ));
    }

    /** @return array<string,array{0:string}> */
    public static function colonBearingAbsolutePathProvider(): array
    {
        return [
            'double colon in dir' => ['/mnt/tank/anime/Fate::Zero'],
            'snapshot suffix'     => ['/srv/backup::mirror/photos'],
            'timestamp dir'       => ['/data/2024-01-01::12:00:00/backup'],
            'single colon'        => ['/mnt/tank/a:b/c'],
        ];
    }

    #[DataProvider('daemonShapedRemotePathProvider')]
    public function testDaemonShapedRemotePathIsRejectedWithASpecificMessage(string $remote): void
    {
        $job = Job::normalize([
            'name'         => 'nas',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PULL',
            'connectionId' => 'c-nas',
            'pairs'        => [['local' => '/mnt/user/data/downloads/', 'remote' => $remote]],
        ]);
        $res = Job::validate($job);
        $this->assertFalse($res['valid']);
        $joined = implode(' | ', $res['errors']);
        $this->assertStringContainsString('daemon', $joined);
        // The message must point at the fix, not just name the problem.
        $this->assertStringContainsString('over SSH', $joined);
    }

    /** @return array<string,array{0:string}> */
    public static function daemonShapedRemotePathProvider(): array
    {
        return [
            'bare module name'  => ['rsync_bkp'],
            'double colon'      => ['nas.local::rsync_bkp'],
            'leading colons'    => ['::rsync_bkp'],
            'rsync url'         => ['rsync://nas.local/rsync_bkp'],
        ];
    }

    /**
     * A non-absolute value that is plainly not a module name must not be told it
     * looks like one - it gets the generic message (or, for a pasted host, the
     * host-specific one).
     */
    #[DataProvider('nonModuleInvalidRemoteProvider')]
    public function testNonModuleShapedInvalidRemotePathIsNotCalledAModuleName(string $remote, string $expect): void
    {
        $errors = Job::checkRemotePath($remote, 'remote path');
        $this->assertNotEmpty($errors);
        $joined = implode(' | ', $errors);
        $this->assertStringNotContainsString('module name', $joined);
        $this->assertStringContainsString($expect, $joined);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function nonModuleInvalidRemoteProvider(): array
    {
        return [
            'relative with dot'  => ['./relative/path', 'must be an absolute path'],
            'windows drive'      => ['C:\\backup', 'must be an absolute path'],
            'unc share'          => ['\\\\server\\share', 'must be an absolute path'],
            'host and path'      => ['nas.local:/volume1/data', 'includes a host'],
            'user host and path' => ['pandasharp@nas.local:/volume1/data', 'includes a host'],
        ];
    }

    /**
     * The exact shape reported on the forum: a module name with a leading slash
     * bolted on. It IS a legal absolute path, so it must still save - but the
     * user has to be told, because rsync will not fail until the first run.
     */
    public function testSingleSegmentRemotePathWarnsWithoutBlockingTheSave(): void
    {
        $job = Job::normalize([
            'name'         => 'nas',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PULL',
            'connectionId' => 'c-nas',
            'pairs'        => [['local' => '/mnt/user/data/downloads/', 'remote' => '/rsync_bkp']],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
        $this->assertNotEmpty(array_filter(
            $res['warnings'],
            static fn($w) => stripos($w, 'MODULE') !== false
        ));

        // A deep remote path is ordinary and must stay silent.
        $quiet = Job::validate(Job::normalize([
            'name'         => 'nas',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PULL',
            'connectionId' => 'c-nas',
            'pairs'        => [['local' => '/mnt/user/data/downloads/', 'remote' => '/volume1/Download/rsync_dir/']],
        ]));
        $this->assertSame([], $quiet['warnings']);
    }

    /**
     * Under LOCAL transport the `remote` field is a second path on THIS box, so
     * the daemon advisory would be nonsense. `/data` is the reachable case: it
     * is a single segment (so daemonModuleNote WOULD fire) while also failing
     * the /mnt guardrail, so without the transport gate the user would get the
     * real error plus a confusing rsync-daemon aside.
     */
    public function testLocalTransportNeverGetsTheDaemonModuleAdvisory(): void
    {
        $res = Job::validate($this->validLocalJob([
            'pairs' => [['local' => '/mnt/user/media/', 'remote' => '/data']],
        ]));
        $this->assertFalse($res['valid']);
        $this->assertNotSame('', Job::daemonModuleNote('/data'), 'precondition: /data would warn');
        $this->assertSame([], $res['warnings']);
    }

    /**
     * B2: the Global Settings tab stores the same option object with no job
     * around it, and a job on "use global config" - the default - takes it
     * verbatim. Validating only inside validate() left that path unchecked.
     */
    public function testGlobalRsyncOptionsAreValidatedTheSameAsAJobs(): void
    {
        $this->assertNotEmpty(Job::validateRsyncOptions(['remoteRsyncPath' => 'sudo rsync']));
        $this->assertNotEmpty(Job::validateRsyncOptions(['remoteRsyncPath' => 'rsync; id']));
        $this->assertSame([], Job::validateRsyncOptions(['remoteRsyncPath' => '/usr/local/bin/rsync']));
        $this->assertSame([], Job::validateRsyncOptions(['remoteRsyncPath' => '']));
        $this->assertSame([], Job::validateRsyncOptions([]));
    }

    /** A hand-edited config.json must not sneak the value past the run path. */
    public function testRunTimeGuardrailRejectsABadRemoteRsyncPath(): void
    {
        $pair = ['local' => '/mnt/user/media/', 'remote' => '/srv/backup/media/'];
        $bad  = Runner::guardrailErrors(['direction' => 'PUSH'], $pair, 'SSH', ['remoteRsyncPath' => 'sudo rsync']);
        $this->assertNotEmpty(array_filter($bad, static fn($e) => stripos($e, 'remote rsync path') !== false));

        $ok = Runner::guardrailErrors(['direction' => 'PUSH'], $pair, 'SSH', ['remoteRsyncPath' => '/usr/bin/rsync']);
        $this->assertSame([], $ok);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function integerScalarProvider(): array
    {
        return [
            'maxDelete'     => ['maxDelete', '--max-delete'],
            'timeout'       => ['timeout', '--timeout'],
            'contimeout'    => ['contimeout', '--contimeout'],
            'compressLevel' => ['compressLevel', '--compress-level'],
            'modifyWindow'  => ['modifyWindow', '--modify-window'],
        ];
    }

    public function testSizeScalarAcceptsSuffixesRejectsGarbage(): void
    {
        // rsync SIZE form: number + optional decimal + optional unit suffix.
        foreach (['100', '1.5m', '500K', '2G', '10GiB', '100B', '4MiB'] as $good) {
            $res = Job::validate($this->validLocalJob(['rsyncOptions' => ['maxSize' => $good]]));
            $this->assertTrue($res['valid'], "expected '$good' valid; errors: " . implode(' | ', $res['errors']));
        }
        // "100iB" has a binary "i" with no unit letter -> rejected.
        foreach (['abc', '1.2.3', '5 megs', '; rm', '-1', '100iB'] as $bad) {
            $res = Job::validate($this->validLocalJob(['rsyncOptions' => ['bwlimit' => $bad]]));
            $this->assertFalse($res['valid'], "expected '$bad' invalid");
            $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, '--bwlimit') !== false));
        }
    }

    public function testEmptyNumericScalarsAreOptional(): void
    {
        // Every numeric scalar left blank must NOT raise an error.
        $res = Job::validate($this->validLocalJob(['rsyncOptions' => [
            'maxDelete' => '', 'bwlimit' => '', 'timeout' => '', 'contimeout' => '',
            'maxSize' => '', 'minSize' => '', 'compressLevel' => '', 'modifyWindow' => '',
            'protocol' => '',
        ]]));
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    /** rsync parses integer options base-0: `010` is octal 8, `08` a syntax error. */
    #[DataProvider('integerScalarProvider')]
    public function testIntegerScalarRejectsLeadingZeros(string $key, string $flag): void
    {
        $this->assertNotEmpty(array_filter(
            Job::validateRsyncOptions([$key => '010']),
            static fn($e) => stripos($e, $flag) !== false
        ));
        $this->assertSame([], Job::validateRsyncOptions([$key => '0']));
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function protocolProvider(): array
    {
        return [
            'blank'          => ['', true],
            'minimum'        => ['20', true],
            'rsync 3.0.9'    => ['30', true],
            'maximum'        => ['32', true],
            'zero'           => ['0', false],
            'below minimum'  => ['19', false],
            'above maximum'  => ['33', false],
            'far above'      => ['99', false],
            'octal 25'       => ['031', false],
            'invalid octal'  => ['029', false],
            'overflow'       => ['99999999999999999999999', false],
            'not a number'   => ['abc', false],
            'negative'       => ['-30', false],
        ];
    }

    /** Out-of-range values used to save fine and fail the run with "protocol version mismatch". */
    #[DataProvider('protocolProvider')]
    public function testProtocolIsLimitedToRsyncsSupportedRange(string $value, bool $ok): void
    {
        $res = Job::validate($this->validLocalJob(['rsyncOptions' => ['protocol' => $value]]));
        if ($ok) {
            $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
            return;
        }
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], static fn($e) => stripos($e, '--protocol') !== false));
    }

    /** rsync exits 2 with -A/-X below protocol 30 on SSH and daemon transfers, never on a local copy. */
    public function testProtocolBelow30RejectsAclsAndXattrsOnRemoteTransports(): void
    {
        $ssh = ['transport' => 'SSH', 'connectionId' => 'c-x',
            'pairs' => [['local' => '/mnt/user/media/', 'remote' => '/srv/backup/media/']]];
        $isAclError = static fn($e) => str_contains($e, '-A');
        foreach (['acls', 'xattrs'] as $key) {
            $opts = ['rsyncOptions' => ['protocol' => '29', $key => true]];
            $res = Job::validate($this->validLocalJob($ssh + $opts));
            $this->assertNotEmpty(array_filter($res['errors'], $isAclError), $key);
            $res = Job::validate($this->validDaemonJob($opts));
            $this->assertNotEmpty(array_filter($res['errors'], $isAclError), $key);
            $res = Job::validate($this->validLocalJob($opts));
            $this->assertTrue($res['valid'], "LOCAL $key: " . implode(' | ', $res['errors']));

            $this->assertTrue(Job::protocolTooLowForAclsXattrs(['protocol' => '29', $key => true]));
            $this->assertFalse(Job::protocolTooLowForAclsXattrs(['protocol' => '30', $key => true]));
            $this->assertFalse(Job::protocolTooLowForAclsXattrs(['protocol' => '', $key => true]));
        }
        $this->assertFalse(Job::protocolTooLowForAclsXattrs(['protocol' => '29']));
        // An out-of-range protocol reports the range only, not -A as well.
        $this->assertFalse(Job::protocolTooLowForAclsXattrs(['protocol' => '19', 'acls' => true]));
    }

    // --- VAL-01: tempDir / backupDir guardrails ----------------------------

    public function testLocalReceiverTempDirMustClearMntGuardrail(): void
    {
        // LOCAL transport: --temp-dir on the boot flash must be rejected, same as
        // any local path. (Previously these scalars skipped the guardrail.)
        $bad = $this->validLocalJob(['rsyncOptions' => ['tempDir' => '/boot/staging']]);
        $res = Job::validate($bad);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, '--temp-dir') !== false));

        // a proper /mnt sub-dir passes
        $ok = Job::validate($this->validLocalJob(['rsyncOptions' => ['tempDir' => '/mnt/user/staging/']]));
        $this->assertTrue($ok['valid'], 'errors: ' . implode(' | ', $ok['errors']));
    }

    public function testLocalReceiverBackupDirOnEtcRejected(): void
    {
        $bad = $this->validLocalJob(['rsyncOptions' => ['backupDir' => '/etc/ur-bak']]);
        $res = Job::validate($bad);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, '--backup-dir') !== false));
    }

    public function testSshPushRemoteTempDirUsesRemoteCheck(): void
    {
        // For SSH PUSH the receiver is remote: a non-root absolute path is fine
        // (it is NOT bound to /mnt - that's the local guardrail).
        $job = Job::normalize([
            'name'         => 'remote-temp',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PUSH',
            'connectionId' => 'c-rpi',
            'pairs'        => [['local' => '/mnt/user/docs/', 'remote' => '/srv/backup/docs/']],
            'rsyncOptions' => ['tempDir' => '/srv/tmp/'],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));

        // ...but a remote ROOT temp-dir is still rejected (non-root rule).
        $job['rsyncOptions']['tempDir'] = '/';
        $res2 = Job::validate($job);
        $this->assertFalse($res2['valid']);
        $this->assertNotEmpty(array_filter($res2['errors'], fn($e) => stripos($e, '--temp-dir') !== false));
    }

    // --- issue #139: rsync DAEMON transport --------------------------------

    /**
     * A credentials structure carrying one DAEMON connection, one explicit SSH
     * connection, and one LEGACY connection with NO `transport` key at all -
     * exactly what an upgraded credentials.json holds, and what
     * Credentials::findConnection hands back RAW (it does not merge).
     */
    private function daemonCreds(): array
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = ['id' => 'c-nas',    'name' => 'nas',    'transport' => 'DAEMON'];
        $creds['connections'][] = ['id' => 'c-rpi',    'name' => 'rpi',    'transport' => 'SSH'];
        $creds['connections'][] = ['id' => 'c-legacy', 'name' => 'legacy'];
        return $creds;
    }

    /** A minimal valid DAEMON job: PULL a module into a local sub-directory. */
    private function validDaemonJob(array $overrides = []): array
    {
        return Job::normalize(array_merge([
            'name'         => 'nas-pull',
            'schedule'     => '0 3 * * *',
            'transport'    => 'DAEMON',
            'direction'    => 'PULL',
            'connectionId' => 'c-nas',
            'pairs'        => [['local' => '/mnt/user/backup/nas/', 'remote' => 'rsync_bkp/photos']],
        ], $overrides));
    }

    // --- the enum ----------------------------------------------------------

    public function testTransportsGainsDaemonAndUnknownStillCoercesToSsh(): void
    {
        // Whole array, order included: Rsync/Runner branch on membership and the
        // jobs.php select is built from the same three values.
        $this->assertSame(['SSH', 'LOCAL', 'DAEMON'], Job::TRANSPORTS);

        // A submitted value is upper-cased and trimmed before the whitelist test.
        $this->assertSame('DAEMON', Job::normalize(['name' => 'x', 'transport' => 'daemon'])['transport']);
        $this->assertSame('DAEMON', Job::normalize(['name' => 'x', 'transport' => ' DaEmOn '])['transport']);
        // ...and an unknown value still falls back to SSH (see also
        // testInvalidEnumsCoercedToDefaults, which pins 'FTP').
        $this->assertSame('SSH', Job::normalize(['name' => 'x', 'transport' => 'RSYNCD'])['transport']);
        $this->assertSame('SSH', Job::normalize(['name' => 'x'])['transport']);
    }

    public function testDaemonJobKeepsPullWhileLocalIsStillCoercedToPush(): void
    {
        // Pulling from a NAS module is the primary reported use case, so DAEMON
        // must NOT be swept into the LOCAL direction coercion.
        $this->assertSame('PULL', Job::normalize([
            'name' => 'd', 'transport' => 'DAEMON', 'direction' => 'PULL',
        ])['direction']);
        $this->assertSame('PULL', Job::normalize([
            'name' => 's', 'transport' => 'SSH', 'direction' => 'PULL',
        ])['direction']);
        // LOCAL is unchanged: both sides are on this box, so PULL is meaningless.
        $this->assertSame('PUSH', Job::normalize([
            'name' => 'l', 'transport' => 'LOCAL', 'direction' => 'PULL',
        ])['direction']);
    }

    /**
     * D13/D14 mirror-pair rule, at the point where the two sides could most
     * easily drift: config.json is hand-editable on /boot, so it can hold
     * `"transport": "daemon"`. Runner::run and Runner::guardrailErrors resolve
     * that with strtoupper()+trim() and build a real daemon operand, so
     * Job::validate must classify the SAME BYTES the same way. If it treated the
     * value as an unknown transport it would pick the wrong role labels, skip
     * the Connection requirement and the cross-check, and run the SSH path
     * checker over a module reference - all while the run does the opposite.
     */
    #[DataProvider('lowerCaseTransportProvider')]
    public function testValidateResolvesTheTransportExactlyAsTheRunnerDoes(string $stored): void
    {
        $job = $this->validDaemonJob();
        $job['transport'] = $stored;            // hand-edited: normalize() never writes this

        // Same bytes, same classification as Runner::run (Runner.php:248).
        $this->assertSame('DAEMON', strtoupper(trim($stored)));

        // 1. The Connection is REQUIRED, with the daemon wording.
        $noConn = $job;
        $noConn['connectionId'] = '';
        $this->assertContains(
            'An rsync daemon job must select a Connection.',
            Job::validate($noConn, $this->daemonCreds())['errors']
        );

        // 2. The transport cross-check runs (an SSH connection is refused).
        $wrongConn = $job;
        $wrongConn['connectionId'] = 'c-rpi';
        $this->assertContains(
            'This job uses rsync daemon transport, but the selected Connection uses SSH '
            . 'transport. Pick a Connection whose Transport is "rsync daemon (rsyncd)".',
            Job::validate($wrongConn, $this->daemonCreds())['errors']
        );

        // 3. The DAEMON path checker is used, not checkRemotePath: an absolute
        //    path is wrong for a module reference and must say so in module terms.
        $absolute = $job;
        $absolute['pairs'] = [['local' => '/mnt/user/backup/nas/', 'remote' => '/volume1/Backup']];
        $errors = implode(' | ', Job::validate($absolute, $this->daemonCreds())['errors']);
        $this->assertStringContainsString('(module)', $errors, 'the role label must be the module one');
        $this->assertStringContainsString(
            'An rsync daemon path is relative to the module',
            $errors,
            'checkDaemonModule must be the checker, not checkRemotePath'
        );

        // 4. And the contimeout warning stays SILENT, exactly as it does for the
        //    upper-cased spelling - buildArgv will really emit the flag.
        $withTimeout = $job;
        $withTimeout['rsyncOptions']['contimeout'] = '30';
        $this->assertSame([], Job::validate($withTimeout, $this->daemonCreds())['warnings']);

        // The enum check still reports the raw value as invalid; that is a
        // separate, correct signal and must not change which rules were applied.
        $this->assertContains(
            'Transport must be SSH, LOCAL or DAEMON.',
            Job::validate($job, $this->daemonCreds())['errors']
        );
    }

    /** @return array<string,array{0:string}> */
    public static function lowerCaseTransportProvider(): array
    {
        return [
            'lower case'        => ['daemon'],
            'mixed case'        => ['Daemon'],
            'surrounding space' => [' DAEMON '],
        ];
    }

    /**
     * The absent-key case has to agree too: Runner defaults a missing transport
     * to 'SSH', so validate must apply the SSH rules to it, not the
     * unknown-transport ones.
     */
    public function testValidateTreatsAnAbsentTransportAsSshLikeTheRunnerDoes(): void
    {
        $job = $this->validDaemonJob(['transport' => 'SSH', 'connectionId' => 'c-nas']);
        unset($job['transport']);

        $this->assertContains(
            'This job uses SSH transport, but the selected Connection uses rsync daemon '
            . '(rsyncd) transport. Pick a Connection whose Transport is "SSH".',
            Job::validate($job, $this->daemonCreds())['errors'],
            'an absent transport must take the SSH arm, exactly as Runner.php:248 does'
        );
    }

    public function testTransportEnumMessageNamesAllThreeTransports(): void
    {
        // A hand-edited config.json can hold a transport normalize() would have
        // rejected; the message must list every legal value.
        $job = $this->validLocalJob();
        $job['transport'] = 'FTP';
        $res = Job::validate($job);
        $this->assertFalse($res['valid']);
        $this->assertContains('Transport must be SSH, LOCAL or DAEMON.', $res['errors']);
    }

    // --- checkDaemonModule -------------------------------------------------

    #[DataProvider('legalDaemonModuleProvider')]
    public function testLegalDaemonModuleReferenceAccepted(string $ref): void
    {
        $this->assertSame([], Job::checkDaemonModule($ref, 'Module'));
    }

    /** @return array<string,array{0:string}> */
    public static function legalDaemonModuleProvider(): array
    {
        return [
            'bare module'          => ['rsync_bkp'],
            // A trailing slash is MEANINGFUL to rsync (contents, not the dir),
            // so it must survive - rule 9 may only strip it to read segments.
            'trailing slash'       => ['rsync_bkp/'],
            'one sub-path'         => ['rsync_bkp/photos'],
            'deep sub-path'        => ['rsync_bkp/photos/2026'],
            'dots dashes scores'   => ['my-mod.1_2'],
            'capitalised'          => ['Backup/a/b'],
            'leading digit'        => ['9lives'],
            'single character'     => ['a'],
            'at the 4096 limit'    => [str_repeat('a', 4096)],
            // rsyncd.conf.5 forbids only '/' and ']' in a [module] name, so a
            // leading '_' or '.' is a perfectly ordinary module and must not be
            // rejected. "_backup" is a common NAS default.
            'underscore lead'      => ['_backup'],
            'dot lead'             => ['.hidden'],
            'dot lead sub-path'    => ['.hidden/photos'],
        ];
    }

    /**
     * Every rejected shape, with the EXACT message. Order matters: the rules are
     * "first match wins", so e.g. "-nas::mod" must report the leading dash, not
     * the daemon address.
     */
    #[DataProvider('rejectedDaemonModuleProvider')]
    public function testRejectedDaemonModuleReferenceGivesTheExactMessage(string $ref, string $expected): void
    {
        $this->assertSame([$expected], Job::checkDaemonModule($ref, 'Module'));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function rejectedDaemonModuleProvider(): array
    {
        $required = 'Module must be an rsync daemon module reference '
            . '(for example rsync_bkp or rsync_bkp/photos).';
        $host = static fn(string $p): string => "Module '$p' includes the daemon host. "
            . "The host, port and username come from the job's Connection, so enter only the "
            . 'module reference here (for example rsync_bkp or rsync_bkp/photos).';
        $absolute = static fn(string $p): string => "Module '$p' must not begin with \"/\". "
            . 'An rsync daemon path is relative to the module, so enter the module reference '
            . '(for example rsync_bkp or rsync_bkp/photos), not an absolute filesystem path.';
        $hostPort = static fn(string $p): string => "Module '$p' includes a host or port. "
            . "The host, port and username come from the job's Connection, so enter only the "
            . 'module reference here.';
        $segments = static fn(string $p): string
            => "Module '$p' must not contain \".\", \"..\" or empty path segments.";
        $shape = static fn(string $p): string => "Module '$p' is not a valid rsync daemon module "
            . 'reference. The first segment is the module name (letters, digits, dot, dash or '
            . 'underscore), optionally followed by a path inside it, for example rsync_bkp/photos.';

        return [
            'empty'              => ['', $required],
            'whitespace only'    => ['   ', $required],
            'over length'        => [str_repeat('a', 4097), 'Module is too long.'],
            'interior space'     => ['my mod', "Module 'my mod' contains whitespace or control characters."],
            'interior tab'       => ["a\tb", "Module 'a\tb' contains whitespace or control characters."],
            'interior newline'   => ["a\nb", "Module 'a\nb' contains whitespace or control characters."],
            'nul byte'           => ["a\0b", "Module 'a\0b' contains whitespace or control characters."],
            'delete byte'        => ["a\x7fb", "Module 'a\x7fb' contains whitespace or control characters."],
            'leading dash'       => ['-mod', 'Module \'-mod\' must not begin with "-".'],
            'dash beats address' => ['-nas::mod', 'Module \'-nas::mod\' must not begin with "-".'],
            'rsync url'          => ['rsync://nas.local/mod', $host('rsync://nas.local/mod')],
            'rsync url cased'    => ['RSYNC://nas.local/mod', $host('RSYNC://nas.local/mod')],
            'host and module'    => ['nas.local::rsync_bkp', $host('nas.local::rsync_bkp')],
            'leading colons'     => ['::rsync_bkp', $host('::rsync_bkp')],
            'user host module'   => ['bkp@nas::mod', $host('bkp@nas::mod')],
            'absolute path'      => ['/volume1/Backup', $absolute('/volume1/Backup')],
            'root'               => ['/', $absolute('/')],
            // Rule 5 (daemon address) runs BEFORE rule 6 (leading '/'), and this
            // is the only input that matches both - swap the two blocks and this
            // is the case that notices.
            'address beats absolute' => ['/volume1::rsync_bkp', $host('/volume1::rsync_bkp')],
            'single colon host'  => ['nas:mod', $hostPort('nas:mod')],
            'colon port'         => ['nas:873', $hostPort('nas:873')],
            'semicolon'          => ['mod;rm', "Module 'mod;rm' contains unsafe characters."],
            // Rule 3 runs BEFORE the metacharacter rule, so a shell payload
            // carrying a space is reported as whitespace, not as unsafe.
            'space beats meta'   => ['mod; rm -rf /', "Module 'mod; rm -rf /' contains whitespace or control characters."],
            'space in sub-path'  => ['mod/a b', "Module 'mod/a b' contains whitespace or control characters."],
            'ampersand'          => ['mod&x', "Module 'mod&x' contains unsafe characters."],
            'pipe'               => ['mod|sh', "Module 'mod|sh' contains unsafe characters."],
            'backtick'           => ['mod`id`', "Module 'mod`id`' contains unsafe characters."],
            'dollar expansion'   => ['mod$(id)', "Module 'mod\$(id)' contains unsafe characters."],
            'redirect'           => ['mod>out', "Module 'mod>out' contains unsafe characters."],
            'quote'              => ["mod'x", "Module 'mod'x' contains unsafe characters."],
            'backslash'          => ['mod\\x', "Module 'mod\\x' contains unsafe characters."],
            'dot segment'        => ['./mod', $segments('./mod')],
            'dotdot segment'     => ['../etc', $segments('../etc')],
            'interior dotdot'    => ['mod/../../etc', $segments('mod/../../etc')],
            'interior dot'       => ['mod/./sub', $segments('mod/./sub')],
            'empty segment'      => ['mod//sub', $segments('mod//sub')],
            'dash-lead segment'  => ['-', 'Module \'-\' must not begin with "-".'],
            'plus in module'     => ['mod+x', $shape('mod+x')],
            'percent in module'  => ['mod%2e', $shape('mod%2e')],
        ];
    }

    // --- daemon pairs vs. the PR#138 SSH advisory ---------------------------

    public function testDaemonPairAcceptsAModuleReferenceAndRaisesNoDaemonAdvisory(): void
    {
        // The PR#138 warning exists to catch a module name typed into an SSH job.
        // On a DAEMON job a module name is exactly right, so it must be silent.
        $res = Job::validate($this->validDaemonJob([
            'pairs' => [['local' => '/mnt/user/backup/nas/', 'remote' => 'rsync_bkp']],
        ]), $this->daemonCreds());
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
        $this->assertSame([], $res['errors']);
        $this->assertSame([], $res['warnings']);
    }

    public function testTheSameModuleReferenceIsStillRejectedUnderSshTransport(): void
    {
        // Regression on PR#138: DAEMON must not have relaxed the SSH rule.
        $ssh = Job::validate(Job::normalize([
            'name'         => 'nas',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PULL',
            'connectionId' => 'c-rpi',
            'pairs'        => [['local' => '/mnt/user/backup/nas/', 'remote' => 'rsync_bkp']],
        ]), $this->daemonCreds());
        $this->assertFalse($ssh['valid']);
        $this->assertSame([
            "Pair #1 source (remote) 'rsync_bkp' looks like an rsync daemon module name, not a path. "
                . Job::DAEMON_MODULE_HINT,
        ], $ssh['errors']);

        // ...and the single-segment ADVISORY still fires on an SSH pair.
        $warned = Job::validate(Job::normalize([
            'name'         => 'nas',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PULL',
            'connectionId' => 'c-rpi',
            'pairs'        => [['local' => '/mnt/user/backup/nas/', 'remote' => '/rsync_bkp']],
        ]), $this->daemonCreds());
        $this->assertTrue($warned['valid'], 'errors: ' . implode(' | ', $warned['errors']));
        $this->assertSame([
            "Pair #1 source (remote) path '/rsync_bkp' " . Job::daemonModuleNote('/rsync_bkp'),
        ], $warned['warnings']);
    }

    public function testDaemonPairRejectsAnAbsoluteFilesystemPath(): void
    {
        $res = Job::validate($this->validDaemonJob([
            'pairs' => [['local' => '/mnt/user/backup/nas/', 'remote' => '/volume1/Backup/data']],
        ]), $this->daemonCreds());
        $this->assertFalse($res['valid']);
        $this->assertSame([
            "Pair #1 source (module) '/volume1/Backup/data' must not begin with \"/\". "
                . 'An rsync daemon path is relative to the module, so enter the module reference '
                . '(for example rsync_bkp or rsync_bkp/photos), not an absolute filesystem path.',
        ], $res['errors']);
    }

    public function testDaemonPairLabelsSayModuleNotRemote(): void
    {
        // $remoteQualifier is 'module' for DAEMON, 'local' for LOCAL, 'remote'
        // for SSH and for any unknown hand-edited value.
        $res = Job::validate($this->validDaemonJob([
            'direction' => 'PUSH',
            'pairs'     => [['local' => '/mnt/user/backup/nas/', 'remote' => '']],
        ]), $this->daemonCreds());
        $this->assertSame(['Pair #1: destination (module) path is required.'], $res['errors']);

        $junk = $this->validDaemonJob(['pairs' => [['local' => '/mnt/user/a/b/', 'remote' => '']]]);
        $junk['transport'] = 'FTP';
        $this->assertContains('Pair #1: destination (remote) path is required.', Job::validate($junk)['errors']);
    }

    // --- isSpecificDaemonTarget + the --delete guard (D12) ------------------

    #[DataProvider('specificDaemonTargetProvider')]
    public function testIsSpecificDaemonTarget(string $ref, bool $expected): void
    {
        $this->assertSame($expected, Job::isSpecificDaemonTarget($ref));
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function specificDaemonTargetProvider(): array
    {
        return [
            'module root'      => ['rsync_bkp', true],
            'trailing slash'   => ['rsync_bkp/', true],
            'sub-path'         => ['rsync_bkp/photos', true],
            'padded'           => ['  rsync_bkp  ', true],
            'empty'            => ['', false],
            'whitespace only'  => ['   ', false],
            'absolute'         => ['/data', false],
            'root'             => ['/', false],
            'dot'              => ['.', false],
            'dotdot'           => ['..', false],
            'dot slash'        => ['./', false],
            'slashes only'     => ['///', false],
        ];
    }

    public function testDaemonTargetHasExactParityWithTheSshSubPathRule(): void
    {
        // D12: a module ROOT is allowed as a --delete destination for exactly the
        // reason '/data' is allowed over SSH - one segment is enough. The two
        // predicates must agree segment-for-segment, which is why the guard is
        // BRANCHED rather than skipped for daemon.
        foreach (['data' => '/data', 'a/b' => '/a/b', 'a/b/c/' => '/a/b/c/'] as $module => $ssh) {
            $this->assertSame(
                Job::isSpecificSubPath($ssh),
                Job::isSpecificDaemonTarget($module),
                "'$module' must match '$ssh'"
            );
        }
    }

    public function testDaemonDeleteToAModuleRootIsAllowedAndTheRunnerAgrees(): void
    {
        // Precondition: the SSH predicate rejects every module reference (it
        // demands a leading '/'), so without the transport branch this job would
        // be unsaveable with --delete on.
        $this->assertFalse(Job::isSpecificSubPath('rsync_bkp'));

        $pair = ['local' => '/mnt/user/media/', 'remote' => 'rsync_bkp'];
        $res  = Job::validate($this->validDaemonJob([
            'direction'    => 'PUSH',
            'pairs'        => [$pair],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '100'],
        ]), $this->daemonCreds());
        $this->assertSame([], $res['errors']);

        // D13 mirror: the run-time guard must reach the same verdict.
        $this->assertSame([], Runner::guardrailErrors(
            ['direction' => 'PUSH'],
            $pair,
            'DAEMON',
            ['delete' => true]
        ));
    }

    public function testDaemonPullDeleteChecksTheLOCALDestinationAndTheRunnerAgrees(): void
    {
        // PULL flips the destination to the local side, so the SSH predicate -
        // not isSpecificDaemonTarget - must be used there. 'restore' is the
        // discriminating value: isSpecificDaemonTarget('restore') is TRUE, so if
        // the daemon predicate leaked onto the local side the delete error would
        // silently vanish.
        $this->assertTrue(Job::isSpecificDaemonTarget('restore'));
        $this->assertFalse(Job::isSpecificSubPath('restore'));

        $pair = ['local' => 'restore', 'remote' => 'rsync_bkp'];
        $res  = Job::validate($this->validDaemonJob([
            'pairs'        => [$pair],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '10'],
        ]), $this->daemonCreds());
        $this->assertSame([
            'Pair #1 destination (local) must be an absolute path.',
            'Pair #1: a delete option is enabled, so the destination must be a specific sub-directory, not a root.',
        ], $res['errors']);

        $this->assertSame([
            'local path must be an absolute path.',
            'a delete option is enabled, so the destination must be a specific sub-directory, not a root.',
        ], Runner::guardrailErrors(['direction' => 'PULL'], $pair, 'DAEMON', ['delete' => true]));
    }

    /**
     * D14 row 2/10: for an unknown hand-edited transport the destination is the
     * `remote` side unconditionally, because that is what Runner::resolvePair
     * does with it. 'LOCAL' would pass under BOTH the old and the new form here,
     * so the junk value is the only case that catches a divergence.
     */
    public function testUnknownTransportTreatsRemoteAsTheDeleteDestinationOnBothSides(): void
    {
        $pair = ['local' => '/mnt/user/a/b/', 'remote' => '/'];
        $job  = $this->validLocalJob([
            'direction'    => 'PULL',
            'pairs'        => [$pair],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '10'],
        ]);
        $job['transport'] = 'FTP';
        $job['direction'] = 'PULL';

        $errors = Job::validate($job)['errors'];
        $this->assertSame([
            'Transport must be SSH, LOCAL or DAEMON.',
            "Pair #1 destination (remote) '/' must be a specific sub-directory, not the filesystem root.",
            'Pair #1: a delete option is enabled, so the destination must be a specific sub-directory, not a root.',
        ], $errors);

        $this->assertSame([
            "remote path '/' must be a specific sub-directory, not the filesystem root.",
            'a delete option is enabled, so the destination must be a specific sub-directory, not a root.',
        ], Runner::guardrailErrors(['direction' => 'PULL'], $pair, 'FTP', ['delete' => true]));
    }

    // --- tempDir / backupDir ------------------------------------------------

    public function testDaemonReceiverTempDirAndBackupDirUseTheModuleCheck(): void
    {
        // rsync resolves both flags relative to the MODULE root on a daemon
        // receiver, so a relative reference is right and an absolute one is not.
        $ok = Job::validate($this->validDaemonJob([
            'direction'    => 'PUSH',
            'pairs'        => [['local' => '/mnt/user/media/', 'remote' => 'rsync_bkp']],
            'rsyncOptions' => ['tempDir' => 'tmp', 'backupDir' => 'old/2026'],
        ]), $this->daemonCreds());
        $this->assertSame([], $ok['errors']);

        $bad = Job::validate($this->validDaemonJob([
            'direction'    => 'PUSH',
            'pairs'        => [['local' => '/mnt/user/media/', 'remote' => 'rsync_bkp']],
            'rsyncOptions' => ['tempDir' => '/volume1/tmp', 'backupDir' => 'nas::old'],
        ]), $this->daemonCreds());
        $this->assertSame([
            "Option --temp-dir '/volume1/tmp' must not begin with \"/\". An rsync daemon path is "
                . 'relative to the module, so enter the module reference (for example rsync_bkp or '
                . 'rsync_bkp/photos), not an absolute filesystem path.',
            "Option --backup-dir 'nas::old' includes the daemon host. The host, port and username "
                . "come from the job's Connection, so enter only the module reference here "
                . '(for example rsync_bkp or rsync_bkp/photos).',
        ], $bad['errors']);
    }

    public function testDaemonPullReceiverTempDirStillClearsTheMntGuardrail(): void
    {
        // On a PULL the receiver is THIS box, so the local guardrail applies even
        // under daemon transport - staging onto the boot flash stays impossible.
        $res = Job::validate($this->validDaemonJob([
            'rsyncOptions' => ['tempDir' => '/boot/staging'],
        ]), $this->daemonCreds());
        $this->assertSame([
            "Option --temp-dir '/boot/staging' must be a sub-directory of /mnt "
                . '(for example /mnt/user/share/...).',
        ], $res['errors']);
    }

    /**
     * D20 / fact 13: --temp-dir and --backup-dir are never module names, so the
     * daemon-shaped discriminators inside checkRemotePath must not fire on them.
     * Before this, an SSH PUSH with tempDir "tmp" was told it "looks like an
     * rsync daemon module name, not a path".
     */
    public function testSshPushTempDirNoLongerGetsTheDaemonModuleWording(): void
    {
        $this->assertSame(
            ['Option --temp-dir must be an absolute path.'],
            Job::checkRemotePath('tmp', 'Option --temp-dir', false)
        );
        $this->assertSame(
            ['Option --backup-dir must be an absolute path.'],
            Job::checkRemotePath('nas::old', 'Option --backup-dir', false)
        );
        // The host-shaped error and the absolute-path sub-path rule still fire.
        $this->assertSame(
            ["Option --temp-dir 'nas:/vol/tmp' includes a host. The host comes from the job's "
                . 'Connection, so enter only the path on the remote host here.'],
            Job::checkRemotePath('nas:/vol/tmp', 'Option --temp-dir', false)
        );
        $this->assertSame(
            ["Option --temp-dir '/' must be a specific sub-directory, not the filesystem root."],
            Job::checkRemotePath('/', 'Option --temp-dir', false)
        );

        // End to end through validate().
        $res = Job::validate(Job::normalize([
            'name'         => 'remote-temp',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'direction'    => 'PUSH',
            'connectionId' => 'c-rpi',
            'pairs'        => [['local' => '/mnt/user/docs/', 'remote' => '/srv/backup/docs/']],
            'rsyncOptions' => ['tempDir' => 'tmp'],
        ]), $this->daemonCreds());
        $this->assertSame(['Option --temp-dir must be an absolute path.'], $res['errors']);
    }

    public function testPairPathDefaultKeepsTheDaemonDiscriminatorsForPairPaths(): void
    {
        // The new third parameter defaults to true, so every existing two-argument
        // call site - including Runner::guardrailErrors - is unchanged.
        $this->assertSame(
            ["remote path 'tmp' looks like an rsync daemon module name, not a path. "
                . Job::DAEMON_MODULE_HINT],
            Job::checkRemotePath('tmp', 'remote path')
        );
        $this->assertSame(
            ["remote path 'nas::old' is an rsync daemon address (host::module or rsync://). "
                . Job::DAEMON_MODULE_HINT],
            Job::checkRemotePath('nas::old', 'remote path')
        );
        $this->assertSame(
            Job::checkRemotePath('tmp', 'remote path'),
            Job::checkRemotePath('tmp', 'remote path', true)
        );
    }

    // --- job transport <-> connection transport cross-check -----------------

    #[DataProvider('transportCrossCheckProvider')]
    public function testJobTransportIsCrossCheckedAgainstTheConnection(
        string $jobTransport,
        string $connectionId,
        array $expectedErrors
    ): void {
        $pairs = $jobTransport === 'DAEMON'
            ? [['local' => '/mnt/user/backup/nas/', 'remote' => 'rsync_bkp/photos']]
            : [['local' => '/mnt/user/backup/nas/', 'remote' => '/srv/backup/docs/']];

        $res = Job::validate(Job::normalize([
            'name'         => 'x',
            'schedule'     => '0 3 * * *',
            'transport'    => $jobTransport,
            'direction'    => 'PUSH',
            'connectionId' => $connectionId,
            'pairs'        => $pairs,
        ]), $this->daemonCreds());

        $this->assertSame($expectedErrors, $res['errors']);
        $this->assertSame($expectedErrors === [], $res['valid']);
    }

    /** @return array<string,array{0:string,1:string,2:array<int,string>}> */
    public static function transportCrossCheckProvider(): array
    {
        $daemonWantsDaemon = 'This job uses rsync daemon transport, but the selected Connection uses '
            . 'SSH transport. Pick a Connection whose Transport is "rsync daemon (rsyncd)".';
        $sshWantsSsh = 'This job uses SSH transport, but the selected Connection uses rsync daemon '
            . '(rsyncd) transport. Pick a Connection whose Transport is "SSH".';

        return [
            'daemon job + daemon conn'  => ['DAEMON', 'c-nas', []],
            'ssh job + ssh conn'        => ['SSH', 'c-rpi', []],
            // fact 14: findConnection returns the RAW record. A pre-daemon
            // credentials.json has no `transport` key at all, and EVERY existing
            // SSH job would report a mismatch without the ?? 'SSH' backfill.
            'ssh job + legacy conn'     => ['SSH', 'c-legacy', []],
            'daemon job + ssh conn'     => ['DAEMON', 'c-rpi', [$daemonWantsDaemon]],
            'daemon job + legacy conn'  => ['DAEMON', 'c-legacy', [$daemonWantsDaemon]],
            'ssh job + daemon conn'     => ['SSH', 'c-nas', [$sshWantsSsh]],
            'daemon job + no conn'      => ['DAEMON', '', ['An rsync daemon job must select a Connection.']],
            'ssh job + no conn'         => ['SSH', '', ['An SSH job must select a Connection.']],
            'daemon job + ghost conn'   => ['DAEMON', 'c-ghost', ['The selected Connection does not exist.']],
            'ssh job + ghost conn'      => ['SSH', 'c-ghost', ['The selected Connection does not exist.']],
        ];
    }

    public function testConnectionTransportIsComparedCaseInsensitively(): void
    {
        // A hand-edited credentials.json may hold "daemon"; mergeConnection would
        // upper-case it on read, so validate() must too or the save-time and
        // run-time checks disagree about the same file.
        $creds = Credentials::defaults();
        $creds['connections'][] = ['id' => 'c-nas', 'name' => 'nas', 'transport' => ' daemon '];

        $res = Job::validate($this->validDaemonJob(), $creds);
        $this->assertSame([], $res['errors']);
    }

    public function testCrossCheckIsSkippedWhenNoCredentialsAreSupplied(): void
    {
        // The handler validates without creds in some paths; a daemon job must
        // still save there, exactly as an SSH job does.
        $res = Job::validate($this->validDaemonJob());
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    public function testLocalJobIsNeverCrossCheckedAgainstAConnection(): void
    {
        // LOCAL transport ignores connectionId entirely - even a stale reference
        // to a daemon connection must not produce an error.
        $job = $this->validLocalJob();
        $job['connectionId'] = 'c-nas';
        $res = Job::validate($job, $this->daemonCreds());
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    // --- D7: the --contimeout warning ---------------------------------------

    #[DataProvider('contimeoutWarningProvider')]
    public function testContimeoutWarnsOffDaemonTransportOnly(string $transport, bool $expectWarning): void
    {
        $warning = 'The --contimeout option only applies to rsync daemon (rsyncd) transport; '
            . 'rsync rejects it outright on SSH and Local transfers, so it is not sent for this job.';

        $job = match ($transport) {
            'DAEMON' => $this->validDaemonJob(['rsyncOptions' => ['contimeout' => '30']]),
            'LOCAL'  => $this->validLocalJob(['rsyncOptions' => ['contimeout' => '30']]),
            default  => Job::normalize([
                'name'         => 's',
                'schedule'     => '0 3 * * *',
                'transport'    => 'SSH',
                'direction'    => 'PUSH',
                'connectionId' => 'c-rpi',
                'pairs'        => [['local' => '/mnt/user/a/b/', 'remote' => '/srv/x/']],
                'rsyncOptions' => ['contimeout' => '30'],
            ]),
        };

        $res = Job::validate($job, $this->daemonCreds());
        // NEVER an error: rejecting it would break a save that has carried the
        // value for months.
        $this->assertSame([], $res['errors']);
        $this->assertTrue($res['valid']);
        $this->assertSame($expectWarning ? [$warning] : [], $res['warnings']);
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function contimeoutWarningProvider(): array
    {
        return [
            'ssh warns'          => ['SSH', true],
            'local warns'        => ['LOCAL', true],
            'daemon stays quiet' => ['DAEMON', false],
        ];
    }

    public function testBlankContimeoutNeverWarns(): void
    {
        foreach (['', '   '] as $value) {
            $res = Job::validate($this->validLocalJob(['rsyncOptions' => ['contimeout' => $value]]));
            $this->assertSame([], $res['warnings'], "contimeout " . var_export($value, true) . " must be silent");
        }
    }

    // --- D19: the reworded hint ---------------------------------------------

    public function testDaemonModuleHintNamesTheJobNotThePluginAndOffersTheWayOut(): void
    {
        $this->assertSame(
            'This job transfers over SSH, so use the absolute filesystem path the module points at '
                . 'on the remote host (for example /volume1/Backup/data). To address the module by '
                . 'name instead, set the job Transport to "rsync daemon (rsyncd)".',
            Job::DAEMON_MODULE_HINT
        );
        // The old lead became false the moment DAEMON transport existed.
        $this->assertStringStartsNotWith('This plugin transfers over SSH', Job::DAEMON_MODULE_HINT);
        // ...but 'over SSH' must survive: the PR#138 assertion pins it.
        $this->assertStringContainsString('over SSH', Job::DAEMON_MODULE_HINT);

        // Both messages that embed it stay in lockstep.
        $this->assertStringEndsWith(Job::DAEMON_MODULE_HINT, Job::daemonModuleNote('/rsync_bkp'));
        $this->assertStringEndsWith(Job::DAEMON_MODULE_HINT, Job::checkRemotePath('rsync_bkp', 'x')[0]);
    }

    // --- guardrails and transport hardening -------------------------------

    /** An SSH PUSH --delete job to $remote, with a max-delete cap so no warning is in the way. */
    private function sshDeleteJob(string $remote, array $overrides = []): array
    {
        return Job::normalize(array_merge([
            'id'           => 'j-mirror',
            'name'         => 'mirror',
            'schedule'     => '0 3 * * *',
            'transport'    => 'SSH',
            'connectionId' => 'c-1',
            'pairs'        => [['local' => '/mnt/user/appdata/', 'remote' => $remote]],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '100'],
        ], $overrides));
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function deleteDepthProvider(): array
    {
        return [
            'disk root'                 => ['/mnt/disk', false],
            'disk root, trailing slash' => ['/mnt/disk/', false],
            'one segment'               => ['/srv', false],
            'root'                      => ['/', false],
            'dotdot back to one'        => ['/srv/backup/..', false],
            'disk sub-dir'              => ['/mnt/disk/appdata/', true],
            'two segments'              => ['/srv/backup', true],
        ];
    }

    #[DataProvider('deleteDepthProvider')]
    public function testSshDeleteDestinationMustBeTwoSegmentsDeep(string $remote, bool $ok): void
    {
        $res = Job::validate($this->sshDeleteJob($remote));
        $this->assertSame($ok, $res['valid'], implode(' | ', $res['errors']));

        // Save time and run time agree.
        $runtime = Runner::guardrailErrors(
            ['direction' => 'PUSH'],
            ['local' => '/mnt/user/appdata/', 'remote' => $remote],
            'SSH',
            Config::mergeRsyncOptions(['delete' => true])
        );
        $this->assertSame($ok, $runtime === [], implode(' | ', $runtime));
    }

    public function testDepthRuleLeavesNonDeleteAndDaemonJobsAlone(): void
    {
        $noDelete = $this->sshDeleteJob('/srv', ['rsyncOptions' => []]);
        $this->assertSame([], Job::validate($noDelete)['errors']);
        // The DAEMON rule is unchanged: a bare module root is still a legal target.
        $this->assertSame('', Job::deleteDestError('DAEMON', true, 'rsync_bkp'));
    }

    public function testValidateUsesTheEffectiveOptionsWhenGlobalsAreSupplied(): void
    {
        // Own options: --delete with no cap (would warn). On "use global" with
        // safe globals, that warning was a false alarm.
        $job = $this->sshDeleteJob('/srv/backup', [
            'useGlobalDefaults' => true,
            'rsyncOptions'      => ['delete' => true],
        ]);
        $this->assertNotEmpty(Job::validate($job)['warnings']);
        $this->assertSame([], Job::validate($job, null, ['defaultRsyncOptions' => Config::defaultRsyncOptions()])['warnings']);

        // ...and a real problem in the globals is now caught on the job.
        $global = ['defaultRsyncOptions' => Config::mergeRsyncOptions(['tempDir' => '/'])];
        $this->assertFalse(Job::validate($job, null, $global)['valid']);
        $global = ['defaultRsyncOptions' => Config::mergeRsyncOptions(['delete' => true])];
        $this->assertNotEmpty(Job::validate($job, null, $global)['warnings'], 'no max-delete cap in the globals');

        // A job with its own options ignores the globals.
        $own = $this->sshDeleteJob('/srv/backup');
        $this->assertTrue(Job::validate($own, null, ['defaultRsyncOptions' => Config::mergeRsyncOptions(['tempDir' => '/'])])['valid']);
    }

    public function testTempDirAndBackupDirRejectDotDotSegmentsOnEveryReceiver(): void
    {
        foreach (['SSH' => 'PUSH', 'LOCAL' => 'PUSH', 'DAEMON' => 'PUSH'] as $transport => $direction) {
            foreach (['tempDir', 'backupDir'] as $key) {
                $errors = Job::optionErrors([$key => '../../../../'], $transport, $direction);
                $this->assertNotEmpty($errors, "$transport $key");
                $this->assertStringContainsString('".."', $errors[0]);
            }
        }
        // ".." that lexically resolves to an allowed path is still refused.
        $this->assertNotEmpty(Job::optionErrors(['backupDir' => '/srv/backup/../old'], 'SSH', 'PUSH'));
        $this->assertNotEmpty(Job::optionErrors(['tempDir' => '/'], 'SSH', 'PUSH'));
        $this->assertSame([], Job::optionErrors(['tempDir' => '/srv/tmp', 'backupDir' => '/srv/old'], 'SSH', 'PUSH'));
    }

    public function testGlobalTempDirAndBackupDirAreChecked(): void
    {
        $this->assertNotEmpty(Job::globalOptionPathErrors(['tempDir' => '/']));
        $this->assertNotEmpty(Job::globalOptionPathErrors(['backupDir' => '../../../../']));
        $this->assertNotEmpty(Job::globalOptionPathErrors(['backupDir' => "/srv/x\ny"]));
        // Transport-specific shapes stay legal: relative is right for a daemon receiver.
        $this->assertSame([], Job::globalOptionPathErrors(['tempDir' => '/srv/tmp', 'backupDir' => 'old']));
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function ancestryProvider(): array
    {
        return [
            'equal'          => ['/srv/backup/a', false],
            'equal, slashes' => ['/srv/backup/a/', false],
            'parent'         => ['/srv/backup', false],
            'child'          => ['/srv/backup/a/photos', false],
            'sibling'        => ['/srv/backup/b', true],
            'prefix only'    => ['/srv/backup/ab', true],
        ];
    }

    #[DataProvider('ancestryProvider')]
    public function testDeleteDestinationMustNotOverlapAnotherJobsDestination(string $mine, bool $ok): void
    {
        $other = $this->sshDeleteJob('/srv/backup/a', ['id' => 'j-other', 'name' => 'other', 'rsyncOptions' => []]);
        $job   = $this->sshDeleteJob($mine);
        $errors = Job::ancestryErrors($job, $job['rsyncOptions'], Job::protectedTargets([$job, $other], 'j-mirror'));
        $this->assertSame($ok, $errors === [], implode(' | ', $errors));
        if (!$ok) {
            // The mirror-vs-mirror wording is unchanged.
            $this->assertSame(
                'Pair #1: a delete option is enabled, so the destination must not be, contain, or lie inside '
                . 'the destination of job "other" (\'/srv/backup/a\').',
                $errors[0]
            );
        }
    }

    public function testAncestryOnlyAppliesToDeleteJobsOnTheSameConnection(): void
    {
        $other = $this->sshDeleteJob('/srv/backup/a', ['id' => 'j-other', 'name' => 'other']);

        $elsewhere = $this->sshDeleteJob('/srv/backup', ['connectionId' => 'c-2']);
        $this->assertSame([], Job::ancestryErrors($elsewhere, $elsewhere['rsyncOptions'], Job::protectedTargets([$other], 'j-mirror')));

        $noDelete = $this->sshDeleteJob('/srv/backup', ['rsyncOptions' => []]);
        $this->assertSame([], Job::ancestryErrors($noDelete, $noDelete['rsyncOptions'], Job::protectedTargets([$other], 'j-mirror')));

        // A job never collides with itself.
        $self = $this->sshDeleteJob('/srv/backup/a');
        $this->assertSame([], Job::ancestryErrors($self, $self['rsyncOptions'], Job::protectedTargets([$self], 'j-mirror')));

        // A pull writes to this box: its destination is the local side.
        $pull = Job::destinations(['transport' => 'SSH', 'direction' => 'PULL', 'connectionId' => 'c-1',
            'pairs' => [['local' => '/mnt/user/x', 'remote' => '/srv/x']]]);
        $this->assertSame(['scope' => 'local', 'paths' => [0 => '/mnt/user/x']], $pull);
    }

    public function testTargetMarkerFieldsAreOptionalAndValidated(): void
    {
        $plain = $this->sshDeleteJob('/srv/backup');
        $this->assertArrayNotHasKey('targetMarker', $plain);
        $this->assertArrayNotHasKey('targetMarkerContent', $plain);

        $job = $this->sshDeleteJob('/srv/backup', [
            'targetMarker'        => ' /srv/.unraid-rsync-target ',
            'targetMarkerContent' => '3f2b1c9e-0000-4000-8000-000000000000',
        ]);
        $this->assertSame('/srv/.unraid-rsync-target', $job['targetMarker']);
        $this->assertTrue(Job::validate($job)['valid'], implode(' | ', Job::validate($job)['errors']));

        foreach (['relative/marker', '/srv/dir/', '/srv/../etc/passwd'] as $bad) {
            $this->assertFalse(Job::validate($this->sshDeleteJob('/srv/backup', ['targetMarker' => $bad]))['valid'], $bad);
        }
        $this->assertFalse(Job::validate($this->sshDeleteJob('/srv/backup', ['targetMarkerContent' => 'x']))['valid']);

        // A pull's marker is on this box, so it must clear the local /mnt rule.
        $pull = $this->sshDeleteJob('/srv/backup', ['direction' => 'PULL', 'pairs' => [['local' => '/mnt/disks/usb/backup', 'remote' => '/srv/a']]]);
        $this->assertTrue(Job::validate(array_merge($pull, ['targetMarker' => '/mnt/disks/usb/.unraid-rsync-target']))['valid']);
        $this->assertFalse(Job::validate(array_merge($pull, ['targetMarker' => '/srv/.unraid-rsync-target']))['valid']);
    }

    // --- snapshot mode -------------------------------------------------------

    /**
     * A valid snapshot job: SSH push, marker at the drive root, root under it,
     * and its own options with nothing forced or rejected.
     *
     * @param array<string,mixed> $overrides
     * @param array<string,mixed> $snapshot  merged into the snapshot block
     * @return array<string,mixed>
     */
    private function snapJob(array $overrides = [], array $snapshot = []): array
    {
        return Job::normalize(array_merge([
            'id'                  => 'j-snap',
            'name'                => 'snap',
            'schedule'            => '0 3 * * *',
            'transport'           => 'SSH',
            'direction'           => 'PUSH',
            'connectionId'        => 'c-1',
            'targetMarker'        => '/srv/backup/.unraid-rsync-target',
            'targetMarkerContent' => '3f2b1c9e-0000-4000-8000-000000000000',
            'pairs'               => [['local' => '/mnt/user/appdata/', 'remote' => '', 'sub' => 'appdata']],
            'rsyncOptions'        => ['recursive' => '1', 'times' => '1'],
            'snapshot'            => array_merge(['enabled' => '1', 'root' => '/srv/backup/snapshots/a'], $snapshot),
        ], $overrides));
    }

    /** @return array<int,string> */
    private function snapErrors(array $job, ?array $global = null): array
    {
        return Job::validate($job, null, $global)['errors'];
    }

    public function testAValidSnapshotJobSavesWithNoErrorsOrWarnings(): void
    {
        $job = $this->snapJob();
        $res = Job::validate($job);
        $this->assertSame([], $res['errors']);
        $this->assertSame([], $res['warnings']);
        $this->assertSame([['local' => '/mnt/user/appdata/', 'remote' => '', 'sub' => 'appdata']], $job['pairs']);
        $this->assertSame([
            'enabled' => true, 'root' => '/srv/backup/snapshots/a', 'keepDaily' => 7, 'keepWeekly' => 4,
            'keepMonthly' => 0, 'maxPrunePerRun' => 3, 'pruneDryRun' => true,
        ], $job['snapshot']);
    }

    public function testNormalizeReadsTheFormShapeAndCarriesSubAndSeed(): void
    {
        $job = $this->snapJob(
            ['pairs' => [['local' => ' /mnt/user/a/ ', 'remote' => '', 'sub' => ' a ', 'seed' => 'on'], ['local' => '', 'remote' => '', 'sub' => '']]],
            ['pruneDryRun' => '0', 'keepDaily' => '14']
        );
        $this->assertSame([['local' => '/mnt/user/a/', 'remote' => '', 'sub' => 'a', 'seed' => true]], $job['pairs']);
        $this->assertFalse($job['snapshot']['pruneDryRun']);
        $this->assertSame(14, $job['snapshot']['keepDaily']);

        $off = $this->snapJob([], ['enabled' => '0']);
        $this->assertArrayNotHasKey('snapshot', $off);
        // A row with only a sub is kept, so validation can name what is missing.
        $this->assertCount(1, Job::normalizePairs([['local' => '', 'remote' => '', 'sub' => 'x']]));
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function rejectedSnapshotOptionProvider(): array
    {
        return [
            'inplace'         => [['inplace' => true], '--inplace'],
            'backupDir'       => [['backupDir' => '/srv/old'], '--backup-dir'],
            'checksum'        => [['checksum' => true], '--checksum'],
            'sizeOnly'        => [['sizeOnly' => true], '--size-only'],
            'ignoreExisting'  => [['ignoreExisting' => true], '--ignore-existing'],
            'update'          => [['update' => true], '--update'],
            'times off'       => [['times' => false], 'Preserve times (-t)'],
            'archive, -t off' => [['archive' => true, 'times' => false], 'Preserve times (-t)'],
        ];
    }

    /** @param array<string,mixed> $opts */
    #[DataProvider('rejectedSnapshotOptionProvider')]
    public function testSnapshotModeRejectsAnOptionOnTheEffectiveOptions(array $opts, string $needle): void
    {
        $base = ['recursive' => true, 'times' => true];
        $hit  = static fn(array $errors): bool => array_filter($errors, static fn($e) => str_contains($e, 'Snapshot mode') && str_contains($e, $needle)) !== [];

        // The job's own options.
        $own = $this->snapJob(['rsyncOptions' => array_merge($base, $opts)]);
        $this->assertTrue($hit($this->snapErrors($own)), implode(' | ', $this->snapErrors($own)));

        // The same option arriving through global defaults: judged on the
        // EFFECTIVE options, so a job on "use global config" is caught too.
        $global  = ['defaultRsyncOptions' => Config::mergeRsyncOptions(array_merge($base, $opts))];
        $viaGlob = $this->snapJob(['useGlobalDefaults' => '1']);
        $this->assertTrue($hit($this->snapErrors($viaGlob, $global)));
        $this->assertFalse($hit($this->snapErrors($viaGlob, ['defaultRsyncOptions' => Config::mergeRsyncOptions($base)])));
    }

    /** --append / --append-verify are not in the whitelist, so no input can emit them. */
    public function testAppendCannotBeEmitted(): void
    {
        $opts = Job::normalizeRsyncOptions(['append' => '1', 'appendVerify' => '1', 'append-verify' => '1', 'recursive' => '1']);
        $this->assertArrayNotHasKey('append', $opts);
        foreach (Rsync::optionTokens(Config::mergeRsyncOptions($opts), 'SSH') as $token) {
            $this->assertStringNotContainsString('append', $token);
        }
        $this->assertSame([], preg_grep('/append/i', array_merge(array_keys(Rsync::BOOL_FLAGS), array_values(Rsync::BOOL_FLAGS))));
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function forcedSnapshotOptionProvider(): array
    {
        return [
            'mkpath'         => [['mkpath' => true], '--mkpath is forced off'],
            'delete'         => [['delete' => true], '--delete is forced off'],
            'deleteExcluded' => [['deleteExcluded' => true], '--delete-excluded is forced off'],
            'maxDelete'      => [['maxDelete' => '10'], '--max-delete is forced off'],
            'hardlinks'      => [['hardlinks' => true], 'Preserve hard links (-H) is forced off'],
        ];
    }

    /** @param array<string,mixed> $opts */
    #[DataProvider('forcedSnapshotOptionProvider')]
    public function testSnapshotModeWarnsAboutForcedOffOptions(array $opts, string $needle): void
    {
        $base = ['recursive' => true, 'times' => true];
        $res  = Job::validate($this->snapJob(['rsyncOptions' => array_merge($base, $opts)]));
        $this->assertSame([], $res['errors'], 'forced, not rejected');
        $this->assertSame(["$needle in snapshot mode."], $res['warnings'], 'and no "no max-delete cap" warning');

        $global = ['defaultRsyncOptions' => Config::mergeRsyncOptions(array_merge($base, $opts))];
        $res    = Job::validate($this->snapJob(['useGlobalDefaults' => '1']), null, $global);
        $this->assertContains("$needle in snapshot mode.", $res['warnings']);
    }

    public function testShippedGlobalDefaultsOnlyWarnAboutMkpath(): void
    {
        $res = Job::validate($this->snapJob(['useGlobalDefaults' => '1']), null, Config::defaults()['global']);
        $this->assertSame([], $res['errors']);
        $this->assertSame(['--mkpath is forced off in snapshot mode.'], $res['warnings']);
    }

    /** @return array<string,array{0:array<int,array<string,string>>,1:string}> */
    public static function badSubProvider(): array
    {
        $p = static fn(string ...$subs): array => array_map(
            static fn($i, $s) => ['local' => "/mnt/user/s$i/", 'remote' => '', 'sub' => $s],
            array_keys($subs),
            $subs
        );
        return [
            'dot'         => [$p('.'), "'.' must be 1 to 64"],
            'dotdot'      => [$p('..'), "'..' must be 1 to 64"],
            'leading dash' => [$p('-x'), "'-x' must be 1 to 64"],
            'inner dot'   => [$p('a.b'), "'a.b' must be 1 to 64"],
            '65 chars'    => [$p(str_repeat('a', 65)), 'must be 1 to 64'],
            'latest'      => [$p('latest'), "'latest' is reserved"],
            'LATEST'      => [$p('LATEST'), "'LATEST' is reserved"],
            'duplicate'   => [$p('Data', 'data'), "Pair #2: snapshot sub-directory name 'data' is already used by pair #1"],
            'missing'     => [[['local' => '/mnt/user/a/', 'remote' => '']], 'Pair #1: a snapshot sub-directory name is required.'],
        ];
    }

    /** @param array<int,array<string,string>> $pairs */
    #[DataProvider('badSubProvider')]
    public function testSnapshotSubNamesAreChecked(array $pairs, string $needle): void
    {
        $errors = $this->snapErrors($this->snapJob(['pairs' => $pairs]));
        $this->assertNotEmpty(array_filter($errors, static fn($e) => str_contains($e, $needle)), implode(' | ', $errors));
        $this->assertSame([], $this->snapErrors($this->snapJob(['pairs' => [['local' => '/mnt/user/a/', 'sub' => str_repeat('a', 64)]]])));
    }

    public function testSnapshotPairRemoteMustBeEmpty(): void
    {
        $errors = $this->snapErrors($this->snapJob(['pairs' => [['local' => '/mnt/user/a/', 'remote' => '/srv/backup/a', 'sub' => 'a']]]));
        $this->assertSame(
            ['Pair #1: in snapshot mode the destination is <root>/<stamp>/<sub>; leave the remote path empty.'],
            $errors
        );
    }

    /** @return array<string,array{0:array<string,string>}> */
    public static function wrongTransportProvider(): array
    {
        return [
            'LOCAL'    => [['transport' => 'LOCAL', 'connectionId' => '']],
            'DAEMON'   => [['transport' => 'DAEMON']],
            'SSH PULL' => [['direction' => 'PULL']],
        ];
    }

    /** @param array<string,string> $overrides */
    #[DataProvider('wrongTransportProvider')]
    public function testSnapshotModeNeedsSshPush(array $overrides): void
    {
        $this->assertContains(
            'Snapshot mode needs SSH transport and the PUSH direction.',
            $this->snapErrors($this->snapJob($overrides))
        );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function badRootProvider(): array
    {
        return [
            'empty'          => ['', 'Snapshot root is required.'],
            'relative'       => ['srv/backup/s', 'must be an absolute path'],
            'dotdot'         => ['/srv/backup/s/../../etc', 'must not contain a ".." segment'],
            'control char'   => ["/srv/backup/s\x01", 'control characters'],
            'one segment'    => ['/srv', 'at least two directories deep'],
            'mnt disk root'  => ['/mnt/disk1', 'at least two directories deep'],
            'trailing slash' => ['/srv/backup/s/', "must be written as '/srv/backup/s'"],
            'double slash'   => ['/srv//backup/s', "must be written as '/srv/backup/s'"],
            'outside marker' => ['/srv/other/s', "must be the target marker's directory ('/srv/backup')"],
        ];
    }

    #[DataProvider('badRootProvider')]
    public function testSnapshotRootIsChecked(string $root, string $needle): void
    {
        $errors = $this->snapErrors($this->snapJob([], ['root' => $root]));
        $this->assertNotEmpty(array_filter($errors, static fn($e) => str_contains($e, $needle)), implode(' | ', $errors));
    }

    public function testSnapshotRootMayBeTheMarkerDirectoryItselfOrUnderMnt(): void
    {
        $this->assertSame([], $this->snapErrors($this->snapJob([], ['root' => '/srv/backup'])));
        $this->assertSame([], $this->snapErrors($this->snapJob(
            ['targetMarker' => '/mnt/disk1/.unraid-rsync-target'],
            ['root' => '/mnt/disk1/snapshots']
        )));
    }

    public function testSnapshotModeNeedsMarkerAndContent(): void
    {
        $msg = 'Snapshot mode needs a target marker and its expected content (a UUID is recommended).';
        $job = $this->snapJob();
        unset($job['targetMarkerContent']);
        $this->assertContains($msg, $this->snapErrors($job));
        unset($job['targetMarker']);
        $this->assertContains($msg, $this->snapErrors($job));
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function badRetentionProvider(): array
    {
        return [
            'daily high'    => [['keepDaily' => 367], 'keepDaily must be a whole number from 0 to 366'],
            'daily neg'     => [['keepDaily' => -1], 'keepDaily must be a whole number from 0 to 366'],
            'weekly high'   => [['keepWeekly' => 261], 'keepWeekly must be a whole number from 0 to 260'],
            'monthly high'  => [['keepMonthly' => 121], 'keepMonthly must be a whole number from 0 to 120'],
            'prune zero'    => [['maxPrunePerRun' => 0], 'maxPrunePerRun must be a whole number from 1 to 20'],
            'prune high'    => [['maxPrunePerRun' => 21], 'maxPrunePerRun must be a whole number from 1 to 20'],
            'not a number'  => [['keepWeekly' => 'four'], 'keepWeekly must be a whole number from 0 to 260'],
            'keeps nothing' => [['keepDaily' => 0, 'keepWeekly' => 0, 'keepMonthly' => 0], 'must keep at least one snapshot'],
        ];
    }

    /** @param array<string,mixed> $snapshot */
    #[DataProvider('badRetentionProvider')]
    public function testSnapshotRetentionIsRangeChecked(array $snapshot, string $needle): void
    {
        $errors = $this->snapErrors($this->snapJob([], $snapshot));
        $this->assertNotEmpty(array_filter($errors, static fn($e) => str_contains($e, $needle)), implode(' | ', $errors));
        $this->assertSame([], $this->snapErrors($this->snapJob([], ['keepDaily' => 0, 'keepWeekly' => 0, 'keepMonthly' => 1])));
    }

    public function testSnapshotRootErrorsBetweenSnapshotJobs(): void
    {
        $a = $this->snapJob();
        $other = fn(string $root, string $conn = 'c-1') => $this->snapJob(['id' => 'j-b', 'name' => 'b', 'connectionId' => $conn], ['root' => $root]);

        foreach (['equal' => '/srv/backup/snapshots/a', 'parent' => '/srv/backup/snapshots', 'child' => '/srv/backup/snapshots/a/x'] as $case => $root) {
            $errors = Job::snapshotRootErrors($a, [$a, $other($root)]);
            $this->assertSame(
                ["Snapshot root '/srv/backup/snapshots/a' must not be, contain, or lie inside the snapshot root of job \"b\" ('$root')."],
                $errors,
                $case
            );
        }
        $this->assertSame([], Job::snapshotRootErrors($a, [$a, $other('/srv/backup/snapshots/b')]), 'sibling');
        $this->assertSame([], Job::snapshotRootErrors($a, [$a, $other('/srv/backup/snapshots/a', 'c-2')]), 'other connection');
        // A prefix that is not a path component is not containment.
        $this->assertSame([], Job::snapshotRootErrors($a, [$a, $other('/srv/backup/snapshots/ab')]));
    }

    public function testAMirrorWithoutDeleteInsideASnapshotRootIsRefused(): void
    {
        $snap   = $this->snapJob();
        $mirror = $this->sshDeleteJob('/srv/backup/snapshots/a/stray', ['rsyncOptions' => []]);

        $this->assertSame(
            ["Pair #1: the destination must not be or lie inside the snapshot root of job \"snap\" ('/srv/backup/snapshots/a')."],
            Job::snapshotRootErrors($mirror, [$snap, $mirror])
        );
        $this->assertSame(
            ["Snapshot root '/srv/backup/snapshots/a' contains the destination of job \"mirror\" ('/srv/backup/snapshots/a/stray')."],
            Job::snapshotRootErrors($snap, [$snap, $mirror])
        );
        // Beside the root, or on another connection: fine.
        foreach ([$this->sshDeleteJob('/srv/backup/mirror', ['rsyncOptions' => []]), $this->sshDeleteJob('/srv/backup/snapshots/a', ['rsyncOptions' => [], 'connectionId' => 'c-2'])] as $ok) {
            $this->assertSame([], Job::snapshotRootErrors($ok, [$snap, $ok]));
            $this->assertSame([], Job::snapshotRootErrors($snap, [$snap, $ok]));
        }
    }

    public function testADeleteMirrorOverASnapshotRootsParentIsAnAncestryError(): void
    {
        $snap   = $this->snapJob();
        $mirror = $this->sshDeleteJob('/srv/backup/snapshots');

        $this->assertContains(
            ['scope' => 'SSH:c-1', 'path' => '/srv/backup/snapshots/a', 'label' => 'the snapshot root of job "snap"'],
            Job::protectedTargets([$snap, $mirror], 'j-mirror')
        );
        $errors = Job::ancestryErrors($mirror, $mirror['rsyncOptions'], Job::protectedTargets([$snap, $mirror], 'j-mirror'));
        $this->assertSame([
            'Pair #1: a delete option is enabled, so the destination must not be, contain, or lie inside '
            . 'the snapshot root of job "snap" (\'/srv/backup/snapshots/a\').',
        ], $errors);
        // The snapshot job does not protect its own root against itself.
        $this->assertSame([], array_filter(Job::protectedTargets([$snap], 'j-snap')));
    }

    public function testMirrorOnlyJobSetsAreUnaffected(): void
    {
        $a = $this->sshDeleteJob('/srv/backup/a');
        $b = $this->sshDeleteJob('/srv/backup/b', ['id' => 'j-b', 'name' => 'b']);
        $local = $this->validLocalJob(['id' => 'j-local']);
        $jobs = [$a, $b, $local];

        // protectedTargets is exactly the destination list it always was.
        $this->assertSame([
            ['scope' => 'SSH:c-1', 'path' => '/srv/backup/b', 'label' => 'job "b"'],
            ['scope' => 'local', 'path' => '/mnt/disk1/backup/media/', 'label' => 'job "backup"'],
        ], Job::protectedTargets($jobs, 'j-mirror'));
        foreach ($jobs as $job) {
            $this->assertSame([], Job::snapshotRootErrors($job, $jobs));
            // A disabled block changes nothing, and is not stored.
            $off = Job::normalize(array_merge($job, ['snapshot' => ['enabled' => '0', 'root' => '/x']]));
            $this->assertSame($job, $off);
            $this->assertSame(Job::validate($job), Job::validate(array_merge($job, ['snapshot' => ['enabled' => false]])));
        }
    }
}

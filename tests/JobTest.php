<?php

use PHPUnit\Framework\TestCase;

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
            'notifyMode'=> 'failure-only',
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
            'name'      => 'remote',
            'schedule'  => '15 2 * * 1-5',
            'transport' => 'SSH',
            'direction' => 'PUSH',
            'pairs'     => [['local' => '/mnt/user/docs/', 'remote' => '/srv/backup/docs/']],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    public function testIdSluggedFromName(): void
    {
        $job = Job::normalize(['name' => 'My Music!!']);
        $this->assertSame('j-my-music', $job['id']);
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

    /** @dataProvider invalidCronProvider */
    public function testInvalidCronRejected(string $cron): void
    {
        $res = Job::validate($this->validLocalJob(['schedule' => $cron]));
        $this->assertFalse($res['valid'], "cron '$cron' should be invalid");
    }

    public function invalidCronProvider(): array
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

    /** @dataProvider validCronProvider */
    public function testValidCronAccepted(string $cron): void
    {
        $this->assertTrue(Job::isValidCron($cron), "cron '$cron' should be valid");
    }

    public function validCronProvider(): array
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
        $this->assertSame('normal', $job['logLevel']);
    }

    // --- PATH GUARDRAILS ---------------------------------------------------

    /** @dataProvider forbiddenLocalPathProvider */
    public function testForbiddenLocalSourceRejected(string $path): void
    {
        $job = $this->validLocalJob();
        $job['pairs'] = [['local' => $path, 'remote' => '/mnt/disk1/backup/x/']];
        $res = Job::validate($job);
        $this->assertFalse($res['valid'], "local source '$path' must be rejected");
    }

    /** @dataProvider forbiddenLocalPathProvider */
    public function testForbiddenLocalDestRejected(string $path): void
    {
        // Under LOCAL transport the destination is also guardrail-checked.
        $job = $this->validLocalJob();
        $job['pairs'] = [['local' => '/mnt/user/safe/', 'remote' => $path]];
        $res = Job::validate($job);
        $this->assertFalse($res['valid'], "local dest '$path' must be rejected");
    }

    public function forbiddenLocalPathProvider(): array
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
            'name'      => 'del',
            'schedule'  => '0 3 * * *',
            'transport' => 'SSH',
            'pairs'     => [['local' => '/mnt/user/a/', 'remote' => '/srv/backup/a/']],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '100'],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    public function testDeleteWithoutMaxDeleteWarns(): void
    {
        $job = Job::normalize([
            'name'      => 'del',
            'schedule'  => '0 3 * * *',
            'transport' => 'SSH',
            'pairs'     => [['local' => '/mnt/user/a/', 'remote' => '/srv/backup/a/']],
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
            'name'      => 'pull-src',
            'schedule'  => '0 3 * * *',
            'transport' => 'SSH',
            'direction' => 'PULL',
            'pairs'     => [['local' => '/mnt/user/restore/', 'remote' => '/srv/data/']],
            'rsyncOptions' => ['delete' => true, 'maxDelete' => '10'],
        ]);
        $res = Job::validate($job);
        $this->assertTrue($res['valid'], 'errors: ' . implode(' | ', $res['errors']));
    }

    public function testPullDeleteWithSpecificLocalDestPasses(): void
    {
        // SSH + PULL into a specific local sub-dir with --delete -> valid.
        $job = Job::normalize([
            'name'      => 'pull-ok',
            'schedule'  => '0 3 * * *',
            'transport' => 'SSH',
            'direction' => 'PULL',
            'pairs'     => [['local' => '/mnt/user/restore/data/', 'remote' => '/srv/data/']],
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
            'excludes'       => ['thumbs/', '', 'cache/'],
            'maxDelete'      => '50',
        ]);

        // Only whitelist keys remain.
        $expectedKeys = array_keys(Config::defaultRsyncOptions());
        $this->assertEqualsCanonicalizing($expectedKeys, array_keys($opts));

        // Coercions.
        $this->assertTrue($opts['archive']);
        $this->assertTrue($opts['compress']);   // '1' -> true
        $this->assertSame('50', $opts['maxDelete']);
        // Empty exclude entry stripped.
        $this->assertSame(['thumbs/', 'cache/'], $opts['excludes']);
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
}

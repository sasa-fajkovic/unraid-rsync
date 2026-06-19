<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for Config.php: round-trip, schemaVersion stamping, defaults merge, and
 * atomic save behaviour. All file I/O is confined to the temp UR_CONFIG_BASE
 * set up in bootstrap.php.
 */
final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        // Start each test from a clean slate (no config.json on disk).
        $path = Config::path();
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function testLoadWithoutFileReturnsDefaults(): void
    {
        $cfg = Config::load();
        $this->assertSame(Config::SCHEMA_VERSION, $cfg['schemaVersion']);
        $this->assertSame([], $cfg['jobs']);
        $this->assertArrayHasKey('defaultRsyncOptions', $cfg['global']);
        // Every whitelist key is present.
        $this->assertEqualsCanonicalizing(
            array_keys(Config::defaultRsyncOptions()),
            array_keys($cfg['global']['defaultRsyncOptions'])
        );
    }

    public function testRetentionDefaultAndClamp(): void
    {
        // Default retention is 100; clamp to [1, 9999]; non-numeric -> default.
        $this->assertSame(100, Config::defaults()['global']['retention']);
        $this->assertSame(100, Config::clampRetention('abc'));
        $this->assertSame(100, Config::clampRetention(null));
        $this->assertSame(1, Config::clampRetention(0));
        $this->assertSame(1, Config::clampRetention(-5));
        $this->assertSame(9999, Config::clampRetention(10000));
        $this->assertSame(9999, Config::clampRetention(999999));
        $this->assertSame(42, Config::clampRetention(42));
        $this->assertSame(42, Config::clampRetention('42')); // integer string
        // Non-INTEGER numerics must fall back to the default, not be mangled by a
        // bare (int) cast ("1e3"->1, "2.9"->2).
        $this->assertSame(100, Config::clampRetention('1e3'));
        $this->assertSame(100, Config::clampRetention('2.9'));
        $this->assertSame(100, Config::clampRetention(2.9));
        $this->assertSame(100, Config::clampRetention(''));
    }

    public function testMergeDefaultsClampsRetention(): void
    {
        $merged = Config::mergeDefaults(['global' => ['retention' => 50000]]);
        $this->assertSame(9999, $merged['global']['retention']);
        // missing -> default
        $merged2 = Config::mergeDefaults(['global' => []]);
        $this->assertSame(100, $merged2['global']['retention']);
    }

    public function testLogDirDefaultsEmpty(): void
    {
        // RAM-only is the default: the persistent log dir is unset.
        $this->assertSame('', Config::defaults()['global']['logDir']);
    }

    #[DataProvider('validLogDirProvider')]
    public function testSanitizeLogDirAcceptsMntPaths(string $in, string $expected): void
    {
        $this->assertSame($expected, Config::sanitizeLogDir($in));
    }

    public static function validLogDirProvider(): array
    {
        return [
            'appdata share'      => ['/mnt/user/appdata/unraid.rsync/logs', '/mnt/user/appdata/unraid.rsync/logs'],
            'trailing slash'     => ['/mnt/user/appdata/logs/', '/mnt/user/appdata/logs'],
            'surrounding spaces' => ['  /mnt/cache/logs  ', '/mnt/cache/logs'],
            'disk share'         => ['/mnt/disk1/backups/logs', '/mnt/disk1/backups/logs'],
            'unassigned device'  => ['/mnt/disks/usb/logs', '/mnt/disks/usb/logs'],
        ];
    }

    #[DataProvider('invalidLogDirProvider')]
    public function testSanitizeLogDirRejectsUnsafePaths($in): void
    {
        // Anything outside /mnt/<top>/<leaf>, relative, traversing, or non-string
        // collapses to '' (RAM-only), never throws.
        $this->assertSame('', Config::sanitizeLogDir($in));
    }

    public static function invalidLogDirProvider(): array
    {
        return [
            'empty'           => [''],
            'relative'        => ['relative/path'],
            'mnt root'        => ['/mnt'],
            'mnt user root'   => ['/mnt/user'],
            'system etc'      => ['/etc/cron.d'],
            'boot flash'      => ['/boot/config/plugins/unraid.rsync/logs'],
            'tmp'             => ['/tmp/x/y'],
            'traversal'       => ['/mnt/user/../../etc/logs'],
            'dot segment'     => ['/mnt/user/./logs'],
            'newline inject'  => ["/mnt/user/logs\nX-Evil: 1"],
            'nul byte'        => ["/mnt/user/lo\0gs"],
            'non-string int'  => [123],
            'non-string null' => [null],
            'non-string arr'  => [['/mnt/user/logs']],
        ];
    }

    public function testMergeDefaultsSanitizesLogDir(): void
    {
        $ok = Config::mergeDefaults(['global' => ['logDir' => '/mnt/user/appdata/ur/logs']]);
        $this->assertSame('/mnt/user/appdata/ur/logs', $ok['global']['logDir']);
        // Invalid -> '' (RAM-only), never persisted as-is.
        $bad = Config::mergeDefaults(['global' => ['logDir' => '/etc/evil']]);
        $this->assertSame('', $bad['global']['logDir']);
        // Missing -> ''.
        $none = Config::mergeDefaults(['global' => []]);
        $this->assertSame('', $none['global']['logDir']);
    }

    public function testLogDirAccessorReadsSavedConfig(): void
    {
        $cfg = Config::defaults();
        $cfg['global']['logDir'] = '/mnt/user/appdata/ur/logs';
        Config::save($cfg);
        $this->assertSame('/mnt/user/appdata/ur/logs', Config::logDir());
    }

    public function testDefaultProfileIsRecursiveNonArchiveCopy(): void
    {
        // The shipped default profile (what a brand-new job inherits): recurse +
        // preserve times + human-readable ON; archive + delete OFF. Guards the
        // "90% user" defaults so a future edit can't silently change them.
        $d = Config::defaultRsyncOptions();
        $this->assertTrue($d['recursive'], 'recursive (-r) must default ON, else folder backups copy nothing');
        $this->assertTrue($d['times'], 'times (-t) must default ON for sane incrementals');
        $this->assertTrue($d['humanReadable']);
        $this->assertTrue($d['mkpath'], 'mkpath (--mkpath) must default ON so a missing destination path is auto-created');
        $this->assertFalse($d['archive'], 'archive (-a) must default OFF (cross-host owner/perm footgun)');
        $this->assertFalse($d['delete'], 'delete must default OFF (destructive; per-job opt-in)');
        $this->assertFalse($d['deleteExcluded']);
        $this->assertSame('', $d['maxDelete']);
    }

    public function testSaveThenLoadRoundTrip(): void
    {
        $cfg = Config::defaults();
        $cfg['jobs'][] = Job::normalize([
            'name'     => 'music',
            'schedule' => '0 3 * * *',
            'transport'=> 'LOCAL',
            'pairs'    => [['local' => '/mnt/user/media/music/', 'remote' => '/mnt/disk1/backup/music/']],
            'rsyncOptions' => ['archive' => true, 'compress' => true],
        ]);

        Config::save($cfg);
        $this->assertFileExists(Config::path());

        $loaded = Config::load();
        $this->assertCount(1, $loaded['jobs']);
        $this->assertSame('music', $loaded['jobs'][0]['name']);
        $this->assertSame('LOCAL', $loaded['jobs'][0]['transport']);
        $this->assertTrue($loaded['jobs'][0]['rsyncOptions']['archive']);
        $this->assertTrue($loaded['jobs'][0]['rsyncOptions']['compress']);
        $this->assertSame(
            [['local' => '/mnt/user/media/music/', 'remote' => '/mnt/disk1/backup/music/']],
            $loaded['jobs'][0]['pairs']
        );
    }

    public function testSaveStampsSchemaVersion(): void
    {
        $cfg = Config::defaults();
        unset($cfg['schemaVersion']); // simulate a caller that forgot it
        Config::save($cfg);

        $raw = json_decode(file_get_contents(Config::path()), true);
        $this->assertSame(Config::SCHEMA_VERSION, $raw['schemaVersion']);
    }

    public function testSaveProducesPrettyUnescapedSlashes(): void
    {
        $cfg = Config::defaults();
        $cfg['jobs'][] = Job::normalize([
            'name'  => 'paths',
            'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/b/']],
        ]);
        Config::save($cfg);
        $raw = file_get_contents(Config::path());

        // JSON_PRETTY_PRINT -> indented (contains newlines + 4-space indent).
        $this->assertStringContainsString("\n    ", $raw);
        // JSON_UNESCAPED_SLASHES -> forward slashes are not escaped as \/.
        $this->assertStringContainsString('/mnt/user/a/', $raw);
        $this->assertStringNotContainsString('\\/mnt', $raw);
    }

    public function testMergeDefaultsFillsMissingOptionKeys(): void
    {
        // A config with only a couple of option keys set.
        $partial = [
            'schemaVersion' => 1,
            'global' => ['defaultRsyncOptions' => ['archive' => false]],
            'jobs' => [],
        ];
        $merged = Config::mergeDefaults($partial);

        $opts = $merged['global']['defaultRsyncOptions'];
        // The explicitly-set key is preserved...
        $this->assertFalse($opts['archive']);
        // ...and every other whitelist key is filled from defaults.
        foreach (array_keys(Config::defaultRsyncOptions()) as $k) {
            $this->assertArrayHasKey($k, $opts);
        }
    }

    public function testMergeDefaultsDropsUnknownOptionKeys(): void
    {
        $partial = [
            'global' => ['defaultRsyncOptions' => [
                'archive'    => true,
                'rsh'        => 'ssh -i /evil', // not whitelisted
                'remove'     => true,           // not whitelisted
            ]],
        ];
        $merged = Config::mergeDefaults($partial);
        $opts = $merged['global']['defaultRsyncOptions'];

        $this->assertArrayNotHasKey('rsh', $opts);
        $this->assertArrayNotHasKey('remove', $opts);
        $this->assertTrue($opts['archive']);
    }

    public function testMergeJobNormalisesPairsAndOptions(): void
    {
        $merged = Config::mergeJob([
            'name'  => 'j',
            'pairs' => [
                ['local' => '/mnt/user/a/', 'remote' => '/mnt/disk1/a/'],
                'not-an-array',
                ['local' => '/mnt/user/b/'], // missing remote -> filled empty
            ],
            'rsyncOptions' => ['archive' => true, 'bogus' => 'x'],
        ]);

        // Only the two array pairs survive; remote defaulted to '' where absent.
        $this->assertCount(2, $merged['pairs']);
        $this->assertSame('', $merged['pairs'][1]['remote']);
        $this->assertArrayNotHasKey('bogus', $merged['rsyncOptions']);
        $this->assertArrayHasKey('compress', $merged['rsyncOptions']); // filled default
    }

    public function testMigrateStampsCurrentVersion(): void
    {
        $migrated = Config::migrate(['schemaVersion' => 1, 'jobs' => []]);
        $this->assertSame(Config::SCHEMA_VERSION, $migrated['schemaVersion']);

        // A config with no version is treated as v1 and stamped.
        $migrated2 = Config::migrate(['jobs' => []]);
        $this->assertSame(Config::SCHEMA_VERSION, $migrated2['schemaVersion']);
    }

    public function testLoadThrowsOnMalformedJson(): void
    {
        file_put_contents(Config::path(), '{ this is not json ');
        $this->expectException(RuntimeException::class);
        Config::load();
    }

    public function testMigrateThrowsOnNewerSchema(): void
    {
        // A config from a newer plugin build must NOT be silently downgraded.
        $this->expectException(RuntimeException::class);
        Config::migrate(['schemaVersion' => Config::SCHEMA_VERSION + 1, 'jobs' => []]);
    }

    public function testLoadThrowsOnNewerSchema(): void
    {
        file_put_contents(
            Config::path(),
            json_encode(['schemaVersion' => Config::SCHEMA_VERSION + 5, 'jobs' => []])
        );
        $this->expectException(RuntimeException::class);
        Config::load();
    }

    public function testLoadThrowsWhenExistingFileUnreadable(): void
    {
        $path = Config::path();
        file_put_contents($path, json_encode(Config::defaults()));
        // Make it unreadable. Skip if the running user can read it anyway
        // (e.g. root in some CI containers ignores file mode bits).
        chmod($path, 0000);
        if (is_readable($path)) {
            chmod($path, 0644);
            $this->markTestSkipped('cannot make file unreadable as the current user');
        }
        try {
            $this->expectException(RuntimeException::class);
            Config::load();
        } finally {
            chmod($path, 0644); // restore so setUp/shutdown can clean up
        }
    }

    public function testSaveIsAtomicNoTempLeftBehind(): void
    {
        $cfg = Config::defaults();
        Config::save($cfg);
        // No leftover temp files in the base dir (tempnam prefix '.config.json.').
        $leftovers = glob(rtrim(UR_CONFIG_BASE, '/') . '/.config.json.*');
        $this->assertSame([], $leftovers ?: []);
    }
}

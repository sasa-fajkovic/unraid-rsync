<?php

use PHPUnit\Framework\TestCase;

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

    public function testSaveIsAtomicNoTempLeftBehind(): void
    {
        $cfg = Config::defaults();
        Config::save($cfg);
        // No leftover temp files in the base dir (tempnam prefix '.config.json.').
        $leftovers = glob(rtrim(UR_CONFIG_BASE, '/') . '/.config.json.*');
        $this->assertSame([], $leftovers ?: []);
    }
}

<?php

declare(strict_types=1);

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

    /**
     * Config pins the process timezone to the SERVER's at load (issue #135):
     * PHP falls back to UTC when php.ini leaves date.timezone unset, which would
     * make Cron::nextRun() compute an entire UTC offset away from when crond
     * actually fires. $TZ is the first source, ahead of /etc/localtime.
     */
    public function testSystemTimezonePrefersTzEnv(): void
    {
        // systemTimezoneFrom([]) is the UNCACHED seam with no ident.cfg sources,
        // so this asserts the resolution order rather than systemTimezone()'s memo.
        $prev = getenv('TZ');
        try {
            putenv('TZ=Australia/Sydney');
            $this->assertSame('Australia/Sydney', Config::systemTimezoneFrom([]));
        } finally {
            putenv($prev === false ? 'TZ' : "TZ=$prev");
        }
    }

    /**
     * The zone is interpolated into page JS and handed to
     * toLocaleString({timeZone}), which throws RangeError on an unknown zone -
     * so an unrecognised value must never be returned. It falls through to the
     * next source (/etc/localtime, then the ambient default, then UTC).
     */
    public function testSystemTimezoneRejectsUnknownZone(): void
    {
        $prev = getenv('TZ');
        try {
            putenv('TZ=Not/AZone');
            $tz = Config::systemTimezoneFrom([]);
            $this->assertNotSame('Not/AZone', $tz);
            $this->assertContains($tz, DateTimeZone::listIdentifiers());
            // Always usable as a real zone.
            $this->assertNotNull(new DateTimeZone($tz));
        } finally {
            putenv($prev === false ? 'TZ' : "TZ=$prev");
        }
    }

    public function testSystemTimezoneIsAlwaysAValidIdentifier(): void
    {
        try {
            foreach (['', 'UTC', 'Europe/Berlin', '../../etc/passwd', "UTC\n", 'right/Europe/Berlin'] as $candidate) {
                putenv("TZ=$candidate");
                $this->assertContains(Config::systemTimezoneFrom([]), DateTimeZone::listIdentifiers());
            }
        } finally {
            // Restore the suite-wide pin from bootstrap.php even on failure - a
            // leaked TZ would cascade into every other date-sensitive test.
            putenv('TZ=UTC');
        }
    }

    /**
     * The critical regression guard for the C1 failure mode: on a stock Unraid
     * box php-fpm may scrub $TZ, Slackware may write /etc/localtime as a plain
     * COPY (so readlink misses), and php.ini may leave date.timezone unset - all
     * three rungs miss, we fall back to UTC, and issue #135 silently reproduces
     * (our stamps UTC, rsync's own lines local) while looking fixed.
     *
     * Unraid's own ident.cfg is the source that survives that, so it must be
     * consulted BEFORE the ambient PHP default.
     */
    public function testSystemTimezoneFallsBackToUnraidIdentCfg(): void
    {
        $prev = getenv('TZ');
        $dir  = sys_get_temp_dir() . '/ur-ident-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $file = $dir . '/ident.cfg';
        try {
            putenv('TZ'); // unset: emulate a scrubbed php-fpm environment
            file_put_contents($file, "NAME=\"Tower\"\ntimeZone=\"Australia/Sydney\"\nUSE_SSL=\"no\"\n");
            $this->assertSame('Australia/Sydney', Config::systemTimezoneFrom([$file]));

            // Unquoted and oddly-spaced forms parse too.
            file_put_contents($file, "timeZone = Europe/Lisbon\n");
            $this->assertSame('Europe/Lisbon', Config::systemTimezoneFrom([$file]));

            // A garbage value must not be returned; it falls through.
            file_put_contents($file, "timeZone=\"Mars/Olympus\"\n");
            $this->assertNotSame('Mars/Olympus', Config::systemTimezoneFrom([$file]));
            $this->assertContains(Config::systemTimezoneFrom([$file]), DateTimeZone::listIdentifiers());

            // A missing file is simply skipped.
            $this->assertContains(
                Config::systemTimezoneFrom([$dir . '/nope.cfg']),
                DateTimeZone::listIdentifiers()
            );
        } finally {
            putenv($prev === false ? 'TZ' : "TZ=$prev");
            @unlink($file);
            @rmdir($dir);
        }
    }

    /** $TZ still wins over ident.cfg - it is what libc hands rsync and crond. */
    public function testTzEnvBeatsIdentCfg(): void
    {
        $prev = getenv('TZ');
        $dir  = sys_get_temp_dir() . '/ur-ident2-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $file = $dir . '/ident.cfg';
        try {
            file_put_contents($file, "timeZone=\"Australia/Sydney\"\n");
            putenv('TZ=Europe/Lisbon');
            $this->assertSame('Europe/Lisbon', Config::systemTimezoneFrom([$file]));
        } finally {
            putenv($prev === false ? 'TZ' : "TZ=$prev");
            @unlink($file);
            @rmdir($dir);
        }
    }

    /**
     * systemTimezone() is memoised because it is called several times per page
     * render and each resolution touches the USB flash and builds the whole tz
     * database list. Prove the cache actually holds - a regression here is
     * invisible except as flash I/O.
     */
    public function testSystemTimezoneIsMemoised(): void
    {
        $prev  = getenv('TZ');
        $first = Config::systemTimezone();
        try {
            // Change the highest-priority source; the cached answer must persist.
            putenv('TZ=Pacific/Kiritimati');
            $this->assertSame($first, Config::systemTimezone());
            // ...while the uncached seam does observe the change.
            $this->assertSame('Pacific/Kiritimati', Config::systemTimezoneFrom([]));
        } finally {
            putenv($prev === false ? 'TZ' : "TZ=$prev");
        }
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

    public function testSecretsDirDefaultsEmpty(): void
    {
        // /boot is the default: credentials.json stays beside config.json.
        $this->assertSame('', Config::defaults()['global']['secretsDir']);
    }

    #[DataProvider('validLogDirProvider')]
    public function testSanitizeSecretsDirAcceptsMntPaths(string $in, string $expected): void
    {
        // Same confinement rule as the log dir (shared sanitizeMntDir).
        $this->assertSame($expected, Config::sanitizeSecretsDir($in));
    }

    #[DataProvider('invalidLogDirProvider')]
    public function testSanitizeSecretsDirRejectsUnsafePaths($in): void
    {
        $this->assertSame('', Config::sanitizeSecretsDir($in));
    }

    #[DataProvider('validLogDirProvider')]
    #[DataProvider('invalidLogDirProvider')]
    public function testSanitizeSecretsDirMatchesLogDir($in, $expected = null): void
    {
        // secrets and logs MUST share one confinement rule - guard against the
        // two validators drifting apart. ($expected is unused; it lets this test
        // accept both the 2-arg valid provider and the 1-arg invalid provider.)
        $this->assertSame(Config::sanitizeLogDir($in), Config::sanitizeSecretsDir($in));
    }

    public function testMergeDefaultsSanitizesSecretsDir(): void
    {
        $ok = Config::mergeDefaults(['global' => ['secretsDir' => '/mnt/user/system/unraid.rsync']]);
        $this->assertSame('/mnt/user/system/unraid.rsync', $ok['global']['secretsDir']);
        // Invalid -> '' (/boot), never persisted as-is.
        $bad = Config::mergeDefaults(['global' => ['secretsDir' => '/boot/config/plugins/unraid.rsync']]);
        $this->assertSame('', $bad['global']['secretsDir']);
        // Missing -> ''.
        $none = Config::mergeDefaults(['global' => []]);
        $this->assertSame('', $none['global']['secretsDir']);
    }

    public function testSecretsDirAccessorReadsSavedConfig(): void
    {
        $cfg = Config::defaults();
        $cfg['global']['secretsDir'] = '/mnt/user/system/unraid.rsync';
        Config::save($cfg);
        $this->assertSame('/mnt/user/system/unraid.rsync', Config::secretsDir());
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
            'transport' => 'LOCAL',
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

    /**
     * A v1 rsync-options object, as the pre-2026.08 plugin stored it: separate
     * excludes/includes lists, no owner/group/devices keys, and (here) archive
     * on with perms/symlinks off - the shipped defaults, which is exactly the
     * combination v1 handled wrongly.
     *
     * @return array<string,mixed>
     */
    private function v1Options(): array
    {
        return [
            'recursive' => true,
            'archive'   => true,
            'perms'     => false,
            'symlinks'  => false,
            'times'     => true,
            'excludes'  => ['*', 'thumbs/'],
            'includes'  => ['A*'],
        ];
    }

    public function testMigrateV1FoldsExcludesAndIncludesIntoOrderedFilters(): void
    {
        $migrated = Config::migrate([
            'schemaVersion' => 1,
            'global' => ['defaultRsyncOptions' => $this->v1Options()],
            'jobs'   => [['id' => 'j1', 'rsyncOptions' => $this->v1Options()]],
        ]);

        foreach ([
            $migrated['global']['defaultRsyncOptions'],
            $migrated['jobs'][0]['rsyncOptions'],
        ] as $opts) {
            // Includes come FIRST: in v1 they were emitted after the excludes
            // and so could never override them (issue #128).
            $this->assertSame([
                ['type' => 'include', 'pattern' => 'A*'],
                ['type' => 'exclude', 'pattern' => '*'],
                ['type' => 'exclude', 'pattern' => 'thumbs/'],
            ], $opts['filters']);
            $this->assertArrayNotHasKey('excludes', $opts);
            $this->assertArrayNotHasKey('includes', $opts);
        }

        $this->assertSame(2, $migrated['schemaVersion']);
    }

    public function testMigrateV1ForcesArchiveImpliedOptionsOn(): void
    {
        $migrated = Config::migrate([
            'schemaVersion' => 1,
            'global' => ['defaultRsyncOptions' => $this->v1Options()],
            'jobs'   => [['id' => 'j1', 'rsyncOptions' => $this->v1Options()]],
        ]);

        foreach ([
            $migrated['global']['defaultRsyncOptions'],
            $migrated['jobs'][0]['rsyncOptions'],
        ] as $opts) {
            foreach (Config::ARCHIVE_IMPLIED_KEYS as $key) {
                $this->assertTrue($opts[$key], "-a already implied '$key', so v2 must record it as on");
            }
        }
    }

    /**
     * The whole point of forcing the implied options on: an upgrade must not
     * change what rsync actually does. v1 emitted a bare -a (silently
     * preserving perms/links/owner/group/devices); the migrated v2 config must
     * emit no --no-* negation at all, so the effective behaviour is identical.
     */
    public function testMigrateV1PreservesEffectiveArchiveBehaviour(): void
    {
        $migrated = Config::migrate([
            'schemaVersion' => 1,
            'global' => ['defaultRsyncOptions' => $this->v1Options()],
            'jobs'   => [],
        ]);
        $tokens = Rsync::optionTokens(
            Config::mergeRsyncOptions($migrated['global']['defaultRsyncOptions'])
        );

        $this->assertContains('-a', $tokens);
        foreach (Rsync::ARCHIVE_IMPLIED as $noFlag) {
            $this->assertNotContains(
                $noFlag,
                $tokens,
                "migrating must not start negating $noFlag on an existing archive job"
            );
        }
    }

    public function testMigrateV1AddsNewBooleanKeysWhenArchiveIsOff(): void
    {
        $migrated = Config::migrate([
            'schemaVersion' => 1,
            'global' => ['defaultRsyncOptions' => ['archive' => false, 'compress' => true]],
            'jobs'   => [],
        ]);
        $opts = $migrated['global']['defaultRsyncOptions'];

        foreach (['owner', 'group', 'devices'] as $key) {
            $this->assertArrayHasKey($key, $opts);
            $this->assertFalse($opts[$key]);
        }
        // Nothing is forced on when -a is off; the user's own choices stand.
        $this->assertFalse($opts['perms']);
        $this->assertSame([], $opts['filters']);
    }

    public function testMigrateIsANoOpOnAV2Config(): void
    {
        $v2 = [
            'schemaVersion' => 2,
            'global' => ['defaultRsyncOptions' => [
                'archive' => true,
                'perms'   => false,   // deliberate in v2: means "emit --no-perms"
                'filters' => [['type' => 'exclude', 'pattern' => '*']],
            ]],
            'jobs' => [],
        ];
        $migrated = Config::migrate($v2);
        $this->assertSame($v2, $migrated);
        // A deliberate v2 negation must survive - re-running the v1 arm would
        // silently flip perms back on and undo the user's choice.
        $this->assertFalse($migrated['global']['defaultRsyncOptions']['perms']);
    }

    public function testLoadMigratesAV1FileOnDisk(): void
    {
        file_put_contents(Config::path(), json_encode([
            'schemaVersion' => 1,
            'global' => ['defaultRsyncOptions' => $this->v1Options()],
            'jobs'   => [],
        ]));
        $cfg = Config::load();

        $this->assertSame(2, $cfg['schemaVersion']);
        $this->assertSame([
            ['type' => 'include', 'pattern' => 'A*'],
            ['type' => 'exclude', 'pattern' => '*'],
            ['type' => 'exclude', 'pattern' => 'thumbs/'],
        ], $cfg['global']['defaultRsyncOptions']['filters']);
        $this->assertTrue($cfg['global']['defaultRsyncOptions']['perms']);
    }

    public function testNormalizeFiltersAcceptsTheParallelFormShape(): void
    {
        // What the options form posts: parallel type[]/pattern[] arrays, paired
        // by index, so the reorder buttons never have to renumber field names.
        $this->assertSame(
            [
                ['type' => 'include', 'pattern' => '*/'],
                ['type' => 'exclude', 'pattern' => '*'],
            ],
            Config::normalizeFilters([
                'type'    => ['include', 'exclude', 'exclude'],
                'pattern' => ['*/', '*', '   '],   // blank row dropped
            ])
        );
    }

    public function testNormalizeFiltersRejectsUnknownTypesAndJunk(): void
    {
        $this->assertSame(
            [['type' => 'exclude', 'pattern' => 'keep']],
            Config::normalizeFilters([
                ['type' => 'protect', 'pattern' => 'nope'],   // not whitelisted
                ['type' => 'exclude', 'pattern' => 'keep'],
                ['type' => 'exclude'],                        // no pattern
                'a string, not an entry',
                ['type' => ['nested'], 'pattern' => 'x'],
            ])
        );
        $this->assertSame([], Config::normalizeFilters('not an array'));
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

    // --- issue #139: the DAEMON transport must be additive within schema v2 ---
    //
    // The daemon transport deliberately does NOT bump Config::SCHEMA_VERSION and
    // adds NO key to defaultJob() or defaultRsyncOptions(): 'transport' merely
    // gains a legal VALUE. A bump would make an OLDER plugin's migrate() throw
    // ("newer than this plugin supports") and take the whole config down on a
    // downgrade, so these tests pin the "no schema movement" side of the
    // contract as hard as the behaviour side.

    /**
     * A canonical, fully-merged v2 config exactly as this plugin writes it -
     * one legacy SSH job (with contimeout set, the option the daemon work
     * changed the EMISSION of but not the STORAGE of), one LOCAL job, and one
     * DAEMON job whose pair remote is a module reference.
     *
     * @return array<string,mixed>
     */
    private function canonicalV2Config(): array
    {
        $cfg = Config::defaults();
        $cfg['global']['retention'] = 42;

        $ssh = Config::defaultJob();
        $ssh['id']           = 'j-ssh';
        $ssh['name']         = 'SSH job';
        $ssh['transport']    = 'SSH';
        $ssh['connectionId'] = 'c-ssh';
        $ssh['pairs']        = [['local' => '/mnt/user/a/', 'remote' => '/volume1/a/']];
        $ssh['rsyncOptions']['contimeout'] = '30';

        $local = Config::defaultJob();
        $local['id']        = 'j-local';
        $local['name']      = 'Local job';
        $local['transport'] = 'LOCAL';
        $local['direction'] = 'PUSH';
        $local['pairs']     = [['local' => '/mnt/user/b/', 'remote' => '/mnt/disk1/b/']];

        $daemon = Config::defaultJob();
        $daemon['id']           = 'j-daemon';
        $daemon['name']         = 'Daemon job';
        $daemon['transport']    = 'DAEMON';
        $daemon['connectionId'] = 'c-daemon';
        $daemon['direction']    = 'PULL';
        $daemon['pairs']        = [['local' => '/mnt/user/photos/', 'remote' => 'rsync_bkp/photos']];
        $daemon['rsyncOptions']['contimeout'] = '15';

        $cfg['jobs'] = [$ssh, $local, $daemon];
        return $cfg;
    }

    /**
     * An existing schemaVersion-2 config.json on disk must survive load() and a
     * subsequent save() COMPLETELY unchanged - same keys, same order, same
     * values, same version. This is the no-breaking-change proof for every
     * pre-daemon install: nothing is added, dropped, reordered or re-stamped.
     */
    public function testExistingV2ConfigOnDiskRoundTripsCompletelyUnchanged(): void
    {
        $disk = $this->canonicalV2Config();
        file_put_contents(
            Config::path(),
            json_encode($disk, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // load() = migrate() + mergeDefaults(); on an already-canonical v2 file
        // both must be the identity, key order included.
        $loaded = Config::load();
        $this->assertSame($disk, $loaded);

        // ...and a save/load cycle on top of that is the identity too.
        Config::save($loaded);
        $this->assertSame($disk, Config::load());

        // The on-disk version did NOT move, and the daemon job is stored as the
        // literal 'DAEMON' rather than coerced or dropped.
        $raw = json_decode((string) file_get_contents(Config::path()), true);
        $this->assertSame(2, $raw['schemaVersion']);
        $this->assertSame(2, Config::SCHEMA_VERSION);
        $this->assertSame(['SSH', 'LOCAL', 'DAEMON'], array_column($raw['jobs'], 'transport'));
        $this->assertSame('rsync_bkp/photos', $raw['jobs'][2]['pairs'][0]['remote']);
        // contimeout is still STORED for the SSH job (buildArgv stops EMITTING
        // it off-daemon; removing the whitelist key would strand the value).
        $this->assertSame('30', $raw['jobs'][0]['rsyncOptions']['contimeout']);
    }

    /**
     * defaultJob() keeps exactly its 15 keys, in order. The daemon transport is
     * a new VALUE for 'transport', never a new key - a new key would change the
     * shape every stored job is merged to.
     */
    public function testDefaultJobKeySetIsUnchangedAtFifteenKeys(): void
    {
        $this->assertSame([
            'id',
            'name',
            'enabled',
            'manualOnly',
            'schedule',
            'transport',
            'connectionId',
            'direction',
            'pairs',
            'useGlobalDefaults',
            'rsyncOptions',
            'logLevel',
            'preHook',
            'postHook',
            'notifyMode',
        ], array_keys(Config::defaultJob()));
        $this->assertCount(15, Config::defaultJob());
        // A brand-new job still defaults to SSH, not to the new transport.
        $this->assertSame('SSH', Config::defaultJob()['transport']);
    }

    /**
     * defaultRsyncOptions() keeps exactly its 40 keys, in order. --port and
     * --password-file are carried in the transport-pieces bag, NEVER in the
     * option whitelist: a user-editable --password-file would be an
     * arbitrary-file-read primitive aimed at a remote daemon, and a whitelisted
     * --port would split the source of truth with the Connection's own port.
     */
    public function testDefaultRsyncOptionsKeySetIsUnchangedAtFortyKeys(): void
    {
        $keys = array_keys(Config::defaultRsyncOptions());
        $this->assertSame([
            'recursive',
            'archive',
            'compress',
            'humanReadable',
            'times',
            'omitDirTimes',
            'omitLinkTimes',
            'perms',
            'owner',
            'group',
            'devices',
            'xattrs',
            'acls',
            'symlinks',
            'hardlinks',
            'sparse',
            'numericIds',
            'partial',
            'inplace',
            'checksum',
            'update',
            'wholeFile',
            'sizeOnly',
            'ignoreExisting',
            'delete',
            'deleteExcluded',
            'mkpath',
            'filters',
            'maxDelete',
            'bwlimit',
            'timeout',
            'contimeout',
            'maxSize',
            'minSize',
            'chmod',
            'tempDir',
            'backupDir',
            'compressLevel',
            'modifyWindow',
            'remoteRsyncPath',
        ], $keys);
        $this->assertCount(40, $keys);

        foreach (['port', 'daemonPort', 'passwordFile', 'passwordfile', 'password'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $keys,
                "'$forbidden' must never become a whitelisted rsync option: --port and "
                . '--password-file are supplied by the transport, not by the user.'
            );
        }
        // contimeout stays whitelisted - buildArgv drops it off-daemon, but
        // removing the key would strand every stored value.
        $this->assertContains('contimeout', $keys);
        $this->assertSame('', Config::defaultRsyncOptions()['contimeout']);
    }

    /**
     * mergeDefaults() neither drops nor adds anything on an already-canonical
     * config: it is the identity, and it is idempotent. (The drop/fill
     * behaviour on a RAGGED config is covered by the two tests above this
     * block; this one guards the complete-config case that every real install
     * hits on every single page load.)
     */
    public function testMergeDefaultsIsTheIdentityOnACanonicalV2Config(): void
    {
        $cfg = $this->canonicalV2Config();
        $once = Config::mergeDefaults($cfg);
        $this->assertSame($cfg, $once);
        $this->assertSame($once, Config::mergeDefaults($once));

        // Top-level: exactly the three canonical keys, in order.
        $this->assertSame(['schemaVersion', 'global', 'jobs'], array_keys($once));
        $this->assertSame(
            ['defaultRsyncOptions', 'retention', 'logDir', 'secretsDir'],
            array_keys($once['global'])
        );
        // Every job keeps the full 15-key default-job shape, in order.
        foreach ($once['jobs'] as $i => $job) {
            $this->assertSame(array_keys(Config::defaultJob()), array_keys($job), "job #$i key shape");
            $this->assertSame(
                array_keys(Config::defaultRsyncOptions()),
                array_keys($job['rsyncOptions']),
                "job #$i rsyncOptions key shape"
            );
        }
    }

    /**
     * mergeDefaults() drops unknown TOP-LEVEL and unknown JOB keys (the
     * option-level case is already covered), and fills a missing job
     * 'transport' from the default rather than inventing one.
     */
    public function testMergeDefaultsDropsUnknownTopLevelAndJobKeys(): void
    {
        $merged = Config::mergeDefaults([
            'schemaVersion' => 2,
            'global'        => ['retention' => 7],
            'jobs'          => [['id' => 'j1', 'name' => 'n', 'daemonSecret' => 'oops']],
            'cronLines'     => ['* * * * * evil'],   // not a config key
        ]);

        $this->assertSame(['schemaVersion', 'global', 'jobs'], array_keys($merged));
        $this->assertArrayNotHasKey('cronLines', $merged);
        $this->assertArrayNotHasKey('daemonSecret', $merged['jobs'][0]);
        $this->assertSame('SSH', $merged['jobs'][0]['transport']);
        $this->assertSame(7, $merged['global']['retention']);
    }

    /** A DAEMON job survives Config::save() -> Config::load() intact. */
    public function testDaemonTransportSurvivesASaveLoadRoundTrip(): void
    {
        $cfg = Config::defaults();
        $cfg['jobs'][] = Job::normalize([
            'id'           => 'j-daemon',
            'name'         => 'nas modules',
            'transport'    => 'DAEMON',
            'connectionId' => 'c-daemon',
            'direction'    => 'PULL',
            'pairs'        => [
                ['local' => '/mnt/user/photos/', 'remote' => 'rsync_bkp/photos'],
                ['local' => '/mnt/user/docs/',   'remote' => 'rsync_bkp'],
            ],
        ]);
        Config::save($cfg);

        $loaded = Config::load();
        // assertEquals, not assertSame: Job::normalize() emits 'filters' LAST
        // inside rsyncOptions while Config::mergeJob() emits it in whitelist
        // position - a pre-existing, purely cosmetic key-order difference in the
        // stored JSON object (Rsync::optionTokens iterates its own flag lists,
        // never the stored order). The values must match exactly.
        $this->assertEquals($cfg['jobs'][0], $loaded['jobs'][0]);
        $this->assertSame('DAEMON', $loaded['jobs'][0]['transport']);
        // PULL is NOT rewritten for DAEMON (only LOCAL is forced to PUSH) - it
        // is the primary reported daemon use case.
        $this->assertSame('PULL', $loaded['jobs'][0]['direction']);
        // The module references survive verbatim: no leading slash added, no
        // trailing slash stripped, no host prefixed.
        $this->assertSame(
            [
                ['local' => '/mnt/user/photos/', 'remote' => 'rsync_bkp/photos'],
                ['local' => '/mnt/user/docs/',   'remote' => 'rsync_bkp'],
            ],
            $loaded['jobs'][0]['pairs']
        );
        $this->assertSame(2, $loaded['schemaVersion']);
    }

    /** migrate() must not touch a v2 config just because it carries a daemon job. */
    public function testMigrateIsANoOpOnAV2ConfigCarryingADaemonJob(): void
    {
        $v2 = [
            'schemaVersion' => 2,
            'global' => ['defaultRsyncOptions' => ['filters' => [['type' => 'exclude', 'pattern' => '*']]]],
            'jobs'   => [[
                'id'        => 'j-daemon',
                'transport' => 'DAEMON',
                'pairs'     => [['local' => '/mnt/user/x/', 'remote' => 'rsync_bkp']],
            ]],
        ];
        $this->assertSame($v2, Config::migrate($v2));
    }

    /**
     * A hand-edited config.json carrying a junk transport must be handled
     * safely - and the ownership of that coercion is a real boundary worth
     * pinning:
     *
     *   Config  PRESERVES the value verbatim (mergeJob is a shape merge, not a
     *           validator) - so nothing is silently rewritten under the user;
     *   Job::normalize() is the layer that COERCES an unrecognised value back
     *           to 'SSH', which is what every save and every run goes through.
     *
     * Adding 'DAEMON' to Job::TRANSPORTS must not change either half.
     */
    public function testJunkTransportIsPreservedByConfigAndCoercedByJobNormalize(): void
    {
        file_put_contents(Config::path(), json_encode([
            'schemaVersion' => 2,
            'global'        => [],
            'jobs'          => [
                ['id' => 'j-junk',    'transport' => 'FTP'],
                ['id' => 'j-lower',   'transport' => 'daemon'],
                ['id' => 'j-missing'],
            ],
        ]));

        // Loading never throws and never rewrites the stored value...
        $loaded = Config::load();
        $this->assertSame('FTP', $loaded['jobs'][0]['transport']);
        $this->assertSame('daemon', $loaded['jobs'][1]['transport']);
        // ...except where the key is absent, which is filled from the default.
        $this->assertSame('SSH', $loaded['jobs'][2]['transport']);

        // Job::normalize() is where an unrecognised value is coerced. 'FTP' is
        // not a transport and falls back to SSH; 'daemon' is accepted because
        // normalize() has always upper-cased first (unchanged behaviour, now
        // reaching one more legal value).
        $this->assertSame('SSH', Job::normalize($loaded['jobs'][0])['transport']);
        $this->assertSame('DAEMON', Job::normalize($loaded['jobs'][1])['transport']);
        $this->assertSame('SSH', Job::normalize($loaded['jobs'][2])['transport']);
        $this->assertSame('DAEMON', Job::normalize(['transport' => ' DAEMON '])['transport']);
        $this->assertSame('SSH', Job::normalize(['transport' => 'RSYNCD'])['transport']);

        // And re-saving what the UI normalised leaves only legal values on disk.
        $cfg = $loaded;
        $cfg['jobs'] = array_map([Job::class, 'normalize'], $cfg['jobs']);
        Config::save($cfg);
        $raw = json_decode((string) file_get_contents(Config::path()), true);
        $this->assertSame(['SSH', 'DAEMON', 'SSH'], array_column($raw['jobs'], 'transport'));
        foreach (array_column($raw['jobs'], 'transport') as $t) {
            $this->assertContains($t, Job::TRANSPORTS);
        }
        $this->assertSame(2, $raw['schemaVersion']);
    }
}

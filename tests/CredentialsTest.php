<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for Credentials.php: round-trip + schemaVersion, defaults merge,
 * connection normalisation, validation, the used_by referential-integrity
 * logic, and the reversible password obfuscation. All file I/O is confined to
 * the temp UR_CONFIG_BASE set up in bootstrap.php.
 */
final class CredentialsTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the secrets-dir override BEFORE resolving path() so a leak from a
        // prior test can't point us at the wrong file.
        Credentials::$secretsDirOverride = null;
        $path = Credentials::path();
        if (is_file($path)) {
            unlink($path);
        }
    }

    protected function tearDown(): void
    {
        Credentials::$secretsDirOverride = null;
    }

    public function testLoadWithoutFileReturnsDefaults(): void
    {
        $creds = Credentials::load();
        $this->assertSame(Credentials::SCHEMA_VERSION, $creds['schemaVersion']);
        $this->assertSame([], $creds['keys']);
        $this->assertSame([], $creds['connections']);
    }

    public function testSaveStampsSchemaVersionAndRoundTrips(): void
    {
        $creds = Credentials::defaults();
        $creds['keys'][] = [
            'id' => 'k-a', 'name' => 'alpha',
            'privateKey' => "-----BEGIN-----\nx\n-----END-----\n",
            'publicKey' => 'ssh-ed25519 AAAA alpha', 'fingerprint' => 'SHA256:abc',
        ];
        $creds['connections'][] = [
            'id' => 'c-a', 'name' => 'conn', 'host' => 'h', 'port' => 2222,
            'username' => 'u', 'authMethod' => 'KEY', 'keyId' => 'k-a',
            'strictHostKey' => 'yes', 'connectTimeout' => 20,
        ];
        // simulate a caller that forgot the version
        unset($creds['schemaVersion']);
        Credentials::save($creds);
        $this->assertFileExists(Credentials::path());

        $raw = json_decode(file_get_contents(Credentials::path()), true);
        $this->assertSame(Credentials::SCHEMA_VERSION, $raw['schemaVersion']);

        $loaded = Credentials::load();
        $this->assertCount(1, $loaded['keys']);
        $this->assertCount(1, $loaded['connections']);
        $this->assertSame('alpha', $loaded['keys'][0]['name']);
        $this->assertSame(2222, $loaded['connections'][0]['port']);
        $this->assertSame('yes', $loaded['connections'][0]['strictHostKey']);
        $this->assertSame(20, $loaded['connections'][0]['connectTimeout']);
    }

    public function testSaveProducesPrettyUnescapedSlashes(): void
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = ['id' => 'c-x', 'name' => 'x', 'host' => 'a/b', 'username' => 'u'];
        Credentials::save($creds);
        $raw = file_get_contents(Credentials::path());
        $this->assertStringContainsString("\n    ", $raw);     // pretty-printed
        $this->assertStringNotContainsString('\\/', $raw);     // slashes unescaped
    }

    public function testMergeTrimsIdentityFields(): void
    {
        $k = Credentials::mergeKey(['id' => '  k-1 ', 'name' => "  alpha\t", 'privateKey' => "  PRIV  \n"]);
        $this->assertSame('k-1', $k['id']);
        $this->assertSame('alpha', $k['name']);
        // Key material is NOT trimmed (only the trailing key write normalises it).
        $this->assertSame("  PRIV  \n", $k['privateKey']);

        $c = Credentials::mergeConnection([
            'id' => ' c-1 ', 'name' => '  conn ', 'host' => "  h.example \n",
            'username' => ' user ', 'keyId' => ' k-1 ', 'authMethod' => 'PASSWORD',
            'password' => '  spaced-pass  ',
        ]);
        $this->assertSame('c-1', $c['id']);
        $this->assertSame('conn', $c['name']);
        $this->assertSame('h.example', $c['host']);
        $this->assertSame('user', $c['username']);
        $this->assertSame('k-1', $c['keyId']);
        // Password is NOT trimmed - leading/trailing spaces may be significant.
        $this->assertSame('  spaced-pass  ', $c['password']);
    }

    public function testValidateKeyRejectsWhitespaceOnlyDuplicate(): void
    {
        // "backup" vs "backup " (trailing space) must still collide.
        $res = Credentials::validateKey(
            ['name' => 'backup', 'publicKey' => 'p'],
            ['backup ']
        );
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'unique') !== false));
    }

    public function testMergeConnectionClampsEnumsAndPort(): void
    {
        $c = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u',
            'port' => 99999,            // out of range -> default 22
            'authMethod' => 'telnet',   // invalid -> default KEYFILE
            'strictHostKey' => 'maybe', // invalid -> default accept-new
            'connectTimeout' => -5,     // out of range -> default 10
        ]);
        $this->assertSame(22, $c['port']);
        // The default auth method is now KEYFILE (the common Unraid case): an
        // invalid/unknown authMethod clamps to it.
        $this->assertSame('KEYFILE', $c['authMethod']);
        $this->assertSame('accept-new', $c['strictHostKey']);
        $this->assertSame(10, $c['connectTimeout']);
    }

    public function testMergeDropsUnknownKeysAndConnectionFields(): void
    {
        $merged = Credentials::mergeDefaults([
            'keys' => [['id' => 'k', 'name' => 'n', 'evil' => 'x']],
            'connections' => [['id' => 'c', 'name' => 'n', 'rogue' => 'y']],
        ]);
        $this->assertArrayNotHasKey('evil', $merged['keys'][0]);
        $this->assertArrayNotHasKey('rogue', $merged['connections'][0]);
        // canonical fields filled
        $this->assertArrayHasKey('fingerprint', $merged['keys'][0]);
        $this->assertArrayHasKey('remoteHostKey', $merged['connections'][0]);
    }

    public function testMigrateThrowsOnNewerSchema(): void
    {
        $this->expectException(RuntimeException::class);
        Credentials::migrate(['schemaVersion' => Credentials::SCHEMA_VERSION + 1]);
    }

    public function testLoadThrowsOnMalformedJson(): void
    {
        file_put_contents(Credentials::path(), '{ not json ');
        $this->expectException(RuntimeException::class);
        Credentials::load();
    }

    public function testLoadThrowsWhenExistingFileUnreadable(): void
    {
        $path = Credentials::path();
        file_put_contents($path, json_encode(Credentials::defaults()));
        chmod($path, 0000);
        if (is_readable($path)) {
            chmod($path, 0600);
            $this->markTestSkipped('cannot make file unreadable as the current user');
        }
        try {
            $this->expectException(RuntimeException::class);
            Credentials::load();
        } finally {
            chmod($path, 0600);
        }
    }

    // --- validation --------------------------------------------------------

    public function testValidateKeyRequiresNameAndMaterial(): void
    {
        $res = Credentials::validateKey(['name' => '', 'publicKey' => '', 'privateKey' => '']);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty($res['errors']);
    }

    public function testValidateKeyUniqueName(): void
    {
        $res = Credentials::validateKey(
            ['name' => 'Dup', 'publicKey' => 'ssh-ed25519 AAAA'],
            ['dup'] // existing names (lowercased compare)
        );
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'unique') !== false));
    }

    public function testValidateConnectionKeyAuthRequiresExistingKey(): void
    {
        $creds = Credentials::defaults();
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'KEY', 'keyId' => 'k-missing',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'key') !== false));
    }

    public function testValidateConnectionPasses(): void
    {
        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'ssh-ed25519 AAAA'];
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'KEY', 'keyId' => 'k-1',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertTrue($res['valid'], implode(' | ', $res['errors']));
    }

    #[DataProvider('unsafeSshTokenProvider')]
    public function testValidateConnectionRejectsUnsafeHost(string $host): void
    {
        $creds = Credentials::defaults();
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => $host, 'username' => 'u',
            'authMethod' => 'PASSWORD',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertFalse($res['valid'], "host '$host' must be rejected");
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'host') !== false));
    }

    #[DataProvider('unsafeSshTokenProvider')]
    public function testValidateConnectionRejectsUnsafeUsername(string $user): void
    {
        $creds = Credentials::defaults();
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h.example', 'username' => $user,
            'authMethod' => 'PASSWORD',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertFalse($res['valid'], "username '$user' must be rejected");
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'username') !== false));
    }

    public static function unsafeSshTokenProvider(): array
    {
        return [
            'leading dash'  => ['-oProxyCommand=evil'],
            'space'         => ['a b'],
            'semicolon'     => ['h;id'],
            'backtick'      => ['h`id`'],
            'pipe'          => ['h|nc'],
            'dollar'        => ['h$(id)'],
            'at sign'       => ['user@evil'],
        ];
    }

    public function testIsSafeSshTokenAcceptsNormalValues(): void
    {
        $this->assertTrue(Credentials::isSafeSshToken('my-host.sub.example.net')); // hyphenated + multi-label
        $this->assertTrue(Credentials::isSafeSshToken('10.0.0.5'));
        $this->assertTrue(Credentials::isSafeSshToken('backup-user'));
        $this->assertFalse(Credentials::isSafeSshToken('-bad'));
        $this->assertFalse(Credentials::isSafeSshToken(''));
        // '@' is rejected: the destination is built as user@host.
        $this->assertFalse(Credentials::isSafeSshToken('user@host'));
    }

    public function testUsedByConnectionWithNullConfigReturnsNoJobs(): void
    {
        // $config is nullable; a null config must not throw - it means no jobs.
        $used = Credentials::usedBy(Credentials::defaults(), 'connection', 'c-1', null);
        $this->assertSame([], $used['jobs']);
    }

    public function testValidateConnectionPasswordAuthDoesNotRequireKey(): void
    {
        // PASSWORD auth needs no keyId; with a password set, it validates.
        $creds = Credentials::defaults();
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'PASSWORD', 'password' => 'secret',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertTrue($res['valid'], implode(' | ', $res['errors']));
        // ...and the keyId requirement is specific to KEY auth, so no key error.
        $this->assertSame([], array_filter($res['errors'], fn($e) => stripos($e, 'key') !== false));
    }

    public function testValidateConnectionPasswordAuthRequiresPassword(): void
    {
        // A PASSWORD connection with an EMPTY password is rejected (it would
        // always fail to authenticate at run time).
        $creds = Credentials::defaults();
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'PASSWORD', 'password' => '',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'password') !== false));
    }

    public function testValidateConnectionPasswordAuthWithPasswordPasses(): void
    {
        // A PASSWORD connection WITH a password set is accepted.
        $creds = Credentials::defaults();
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'PASSWORD', 'password' => 'hunter2',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertTrue($res['valid'], implode(' | ', $res['errors']));
    }

    // --- KEYFILE auth (existing key file already on this system) ------------

    public function testDefaultConnectionUsesKeyfileAndDefaultPath(): void
    {
        // KEYFILE is the default for NEW connections (the common Unraid case),
        // pre-filled with the conventional root ed25519 path.
        $c = Credentials::defaultConnection();
        $this->assertSame('KEYFILE', $c['authMethod']);
        $this->assertSame('/root/.ssh/id_ed25519', $c['keyFilePath']);
        $this->assertSame(Credentials::DEFAULT_KEY_FILE_PATH, $c['keyFilePath']);
    }

    public function testMergeConnectionBackfillsMissingKeyFilePath(): void
    {
        // An existing (pre-KEYFILE) connection on disk has no keyFilePath; merge
        // must backfill the default rather than leave it undefined.
        $c = Credentials::mergeConnection([
            'id' => 'c-old', 'name' => 'old', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'PASSWORD',
        ]);
        $this->assertArrayHasKey('keyFilePath', $c);
        $this->assertSame(Credentials::DEFAULT_KEY_FILE_PATH, $c['keyFilePath']);
        // ...and the existing authMethod is preserved (migration is safe).
        $this->assertSame('PASSWORD', $c['authMethod']);
    }

    public function testMergeConnectionTrimsKeyFilePath(): void
    {
        $c = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'KEYFILE', 'keyFilePath' => "  /root/.ssh/id_ed25519  \n",
        ]);
        $this->assertSame('/root/.ssh/id_ed25519', $c['keyFilePath']);
    }

    public function testValidateConnectionKeyfileRequiresPath(): void
    {
        // KEYFILE with an empty (whitespace-only -> '') path is rejected: the
        // path is required (existence is only checked at run time, not here).
        $creds = Credentials::defaults();
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'KEYFILE', 'keyFilePath' => '   ',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'key file path') !== false));
    }

    public function testValidateConnectionKeyfileRequiresAbsolutePath(): void
    {
        $creds = Credentials::defaults();
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'KEYFILE', 'keyFilePath' => 'relative/id_ed25519',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty(array_filter($res['errors'], fn($e) => stripos($e, 'absolute') !== false));
    }

    public function testValidateConnectionKeyfileDoesNotRequireFileToExist(): void
    {
        // The path is valid (absolute + safe) even though the file does not
        // exist yet - existence is a RUN-time check, not a save-time one.
        $creds = Credentials::defaults();
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h.example', 'username' => 'u',
            'authMethod' => 'KEYFILE', 'keyFilePath' => '/root/.ssh/does-not-exist-yet',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertTrue($res['valid'], implode(' | ', $res['errors']));
        // ...and no managed-key requirement leaks in for KEYFILE.
        $this->assertSame([], array_filter($res['errors'], fn($e) => stripos($e, 'select an SSH key') !== false));
    }

    #[DataProvider('unsafeKeyFilePathProvider')]
    public function testValidateConnectionRejectsUnsafeKeyFilePath(string $path): void
    {
        $creds = Credentials::defaults();
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h.example', 'username' => 'u',
            'authMethod' => 'KEYFILE', 'keyFilePath' => $path,
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertFalse($res['valid'], "path '$path' must be rejected");
    }

    public static function unsafeKeyFilePathProvider(): array
    {
        return [
            'relative'        => ['root/.ssh/id'],
            'leading dash'    => ['-oProxyCommand=evil'],
            'space'           => ['/root/.ssh/id rsa'],
            'semicolon'       => ['/root/.ssh/id;id'],
            'backtick'        => ['/root/.ssh/`id`'],
            'dollar paren'    => ['/root/.ssh/$(id)'],
            'pipe'            => ['/root/.ssh/id|nc'],
            'traversal'       => ['/root/../etc/shadow'],
        ];
    }

    public function testIsSafeKeyFilePath(): void
    {
        $this->assertTrue(Credentials::isSafeKeyFilePath('/root/.ssh/id_ed25519'));
        $this->assertTrue(Credentials::isSafeKeyFilePath('/mnt/user/keys/backup.key'));
        $this->assertFalse(Credentials::isSafeKeyFilePath(''));
        $this->assertFalse(Credentials::isSafeKeyFilePath('id_ed25519'));        // relative
        $this->assertFalse(Credentials::isSafeKeyFilePath('-i'));                // leading dash
        $this->assertFalse(Credentials::isSafeKeyFilePath('/root/.ssh/a b'));    // space
        $this->assertFalse(Credentials::isSafeKeyFilePath('/root/../etc'));      // traversal
        $this->assertFalse(Credentials::isSafeKeyFilePath("/root/\x00id"));      // NUL
    }

    public function testKeyfileConnectionDoesNotCountAsKeyDependency(): void
    {
        // usedBy('key') must ignore a KEYFILE connection even if it carries a
        // stale keyId - it does not consume a MANAGED key.
        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'p'];
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'kf', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'KEYFILE', 'keyId' => 'k-1', 'keyFilePath' => '/root/.ssh/id_ed25519',
        ]);
        $used = Credentials::usedBy($creds, 'key', 'k-1');
        $this->assertSame([], $used['connections']);
    }

    public function testValidateConnectionKeyAuthUnaffectedByPasswordRule(): void
    {
        // KEY auth must NOT require a password (the password rule is PASSWORD-only).
        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'ssh-ed25519 AAAA'];
        $conn = Credentials::mergeConnection([
            'id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'KEY', 'keyId' => 'k-1', 'password' => '',
        ]);
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertTrue($res['valid'], implode(' | ', $res['errors']));
    }

    // --- used_by referential integrity -------------------------------------

    public function testUsedByKeyBlockedByConnection(): void
    {
        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'p'];
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'web', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'KEY', 'keyId' => 'k-1',
        ]);
        $used = Credentials::usedBy($creds, 'key', 'k-1');
        $this->assertCount(1, $used['connections']);
        $this->assertSame('web', $used['connections'][0]['name']);
    }

    public function testUsedByKeyIgnoresPasswordConnections(): void
    {
        // A PASSWORD connection that happens to carry a stale keyId must NOT
        // count as a dependency (it doesn't consume the key).
        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'p'];
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'pw', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'PASSWORD', 'keyId' => 'k-1',
        ]);
        $used = Credentials::usedBy($creds, 'key', 'k-1');
        $this->assertSame([], $used['connections']);
    }

    public function testUsedByConnectionListsDependentJobs(): void
    {
        $creds  = Credentials::defaults();
        $config = Config::defaults();
        $config['jobs'][] = ['id' => 'j-1', 'name' => 'music', 'connectionId' => 'c-1', 'enabled' => true];
        $config['jobs'][] = ['id' => 'j-2', 'name' => 'photos', 'connectionId' => 'c-other', 'enabled' => true];

        $used = Credentials::usedBy($creds, 'connection', 'c-1', $config);
        $this->assertCount(1, $used['jobs']);
        $this->assertSame('music', $used['jobs'][0]['name']);
    }

    // --- password obfuscation ----------------------------------------------

    public function testObfuscationRoundTrip(): void
    {
        $plain = 'S3cr3t! pa$$w0rd with spaces';
        $stored = Credentials::obfuscate($plain);
        $this->assertNotSame($plain, $stored, 'stored form must not be the plaintext');
        $this->assertSame($plain, Credentials::deobfuscate($stored));
    }

    public function testObfuscationEmptyStringRoundTrips(): void
    {
        $this->assertSame('', Credentials::obfuscate(''));
        $this->assertSame('', Credentials::deobfuscate(''));
    }

    public function testDeobfuscateInvalidBase64ReturnsEmpty(): void
    {
        $this->assertSame('', Credentials::deobfuscate('not valid base64 @@@'));
    }

    public function testObfuscationHandlesUtf8(): void
    {
        $plain = 'pässwörd-日本語';
        $this->assertSame($plain, Credentials::deobfuscate(Credentials::obfuscate($plain)));
    }

    // --- id generation -----------------------------------------------------

    public function testGenerateIdSlugsAndDedupes(): void
    {
        $first  = Credentials::generateId('My Key!', 'k-', []);
        $this->assertSame('k-my-key', $first);
        $second = Credentials::generateId('My Key!', 'k-', ['k-my-key']);
        $this->assertSame('k-my-key-2', $second);
    }

    public function testFindKeyAndConnection(): void
    {
        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'a'];
        $creds['connections'][] = ['id' => 'c-1', 'name' => 'b'];
        $this->assertNotNull(Credentials::findKey($creds, 'k-1'));
        $this->assertNull(Credentials::findKey($creds, 'nope'));
        $this->assertNotNull(Credentials::findConnection($creds, 'c-1'));
        $this->assertNull(Credentials::findConnection($creds, 'nope'));
    }

    // --- secrets-dir override (global.secretsDir) --------------------------

    public function testPathDefaultsToConfigBaseWhenOverrideUnset(): void
    {
        Credentials::$secretsDirOverride = null;
        $this->assertSame(rtrim(UR_CONFIG_BASE, '/') . '/credentials.json', Credentials::path());
    }

    public function testEmptyStringOverrideFallsBackToConfigBase(): void
    {
        // '' is the "unset" sentinel too, so it must not produce "/credentials.json".
        Credentials::$secretsDirOverride = '';
        $this->assertSame(rtrim(UR_CONFIG_BASE, '/') . '/credentials.json', Credentials::path());
    }

    public function testPathHonoursSecretsDirOverride(): void
    {
        $dir = sys_get_temp_dir() . '/ur-secrets-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Credentials::$secretsDirOverride = $dir;
        $this->assertSame($dir . '/credentials.json', Credentials::path());
        // Trailing slash is trimmed, exactly like the /boot base.
        Credentials::$secretsDirOverride = $dir . '/';
        $this->assertSame($dir . '/credentials.json', Credentials::path());
    }

    public function testSaveAndLoadUseOverrideDirNotBoot(): void
    {
        $dir = sys_get_temp_dir() . '/ur-secrets-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $bootFile = rtrim(UR_CONFIG_BASE, '/') . '/credentials.json';
        $this->assertFileDoesNotExist($bootFile);

        Credentials::$secretsDirOverride = $dir; // dir does not exist yet; save() mkdir's it
        $creds = Credentials::defaults();
        $creds['connections'][] = ['id' => 'c-x', 'name' => 'on-array', 'host' => 'h', 'username' => 'u'];
        Credentials::save($creds);

        // Written under the override, NOT on /boot.
        $this->assertFileExists($dir . '/credentials.json');
        $this->assertFileDoesNotExist($bootFile);

        $loaded = Credentials::load();
        $this->assertSame('on-array', $loaded['connections'][0]['name']);

        // Clearing the override makes the /boot path authoritative again (empty
        // keychain, since nothing was written there).
        Credentials::$secretsDirOverride = null;
        $this->assertSame([], Credentials::load()['connections']);

        // cleanup the override dir
        @unlink($dir . '/credentials.json');
        @rmdir($dir);
    }

    public function testSaveUnderOverrideTightensPermsTo600(): void
    {
        $dir = sys_get_temp_dir() . '/ur-secrets-' . getmypid() . '-' . bin2hex(random_bytes(4));
        Credentials::$secretsDirOverride = $dir;
        Credentials::save(Credentials::defaults());
        $file = $dir . '/credentials.json';
        $this->assertFileExists($file);
        // On a perms-respecting filesystem (the test temp dir) the chmod 600 sticks
        // - this is the real at-rest protection an /mnt path gives over FAT32.
        clearstatcache();
        $this->assertSame(0600, fileperms($file) & 0777);
        @unlink($file);
        @rmdir($dir);
    }
    // --- rsync daemon (rsyncd) detection ------------------------------------

    public function testRsyncDaemonNoteFlagsPort873(): void
    {
        $note = Credentials::rsyncDaemonNote(873, 'nas.local');
        $this->assertStringContainsString('873', $note);
        $this->assertSame('', Credentials::rsyncDaemonNote(22, 'nas.local'));
        $this->assertSame('', Credentials::rsyncDaemonNote(2222, 'nas.local'));
    }

    public function testRsyncDaemonNoteFlagsDaemonStyleHosts(): void
    {
        $this->assertNotSame('', Credentials::rsyncDaemonNote(22, 'rsync://nas/module'));
        $this->assertNotSame('', Credentials::rsyncDaemonNote(22, 'nas::module'));
    }

    /**
     * An IPv6 literal is full of colons but is a perfectly ordinary SSH host.
     * Matching a bare "::" would warn on EVERY IPv6 connection.
     */
    public function testRsyncDaemonNoteDoesNotFlagIpv6Hosts(): void
    {
        foreach (['fe80::1', '2001:db8::1', '::1', '[2001:db8::1]', '2001:DB8::1'] as $host) {
            $this->assertSame('', Credentials::rsyncDaemonNote(22, $host), "IPv6 host $host must not warn");
        }
        // ...but an IPv6 host on the daemon PORT still warns, on the port.
        $this->assertStringContainsString('873', Credentials::rsyncDaemonNote(873, '2001:db8::1'));
    }

    // --- connection TRANSPORT field (rsync daemon vs. SSH) ------------------

    /**
     * The frozen key order of a merged connection. Whole-array assertions below
     * depend on it, and it is load-bearing in one specific place: `transport`
     * must be resolved BEFORE `port`, because the port default reads it.
     */
    private const MERGED_KEY_ORDER = [
        'id', 'name', 'host', 'username', 'keyId', 'keyFilePath', 'password',
        'remoteHostKey', 'transport', 'port', 'authMethod', 'strictHostKey',
        'connectTimeout',
    ];

    private const DAEMON_HOST_ERROR = 'Host is not valid for an rsync daemon Connection: it must not contain '
        . '":", "/", "?", "#", "[" or "]", must not begin with "-", and must not contain '
        . 'whitespace or shell characters. rsync would silently reinterpret such a value '
        . 'as an SSH target or as a local path.';

    private const DAEMON_USER_ERROR = 'Username is not valid for an rsync daemon Connection: it must not contain '
        . '":", "/", "?", "#", "[" or "]", must not begin with "-", and must not contain '
        . 'whitespace or shell characters. rsync would silently reinterpret such a value '
        . 'as an SSH target or as a local path.';

    private const DAEMON_IPV6_ERROR = 'IPv6 daemon hosts are not supported yet. Use the host name or an IPv4 '
        . 'address for an rsync daemon Connection.';

    private const DAEMON_SECRET_ERROR = 'The module secret must not contain line breaks: rsync reads only the first '
        . 'line of the password file, so everything after it would be silently discarded. '
        . 'Type a new secret into this Connection to replace the stored one - leaving the '
        . 'field blank keeps it.';

    private const DAEMON_SECRET_LENGTH_ERROR = 'The module secret must be 511 bytes or shorter: '
        . 'rsync reads at most that much of the password file, so a longer secret is silently '
        . 'truncated and authentication fails with no explanation. Type a shorter secret into '
        . 'this Connection to replace the stored one - leaving the field blank keeps it.';

    public function testDefaultConnectionCarriesSshTransport(): void
    {
        $c = Credentials::defaultConnection();
        // The field is ADDITIVE within credentials.json schema v1: exactly one
        // new key, defaulting to SSH, and positioned right after 'name'.
        $this->assertSame([
            'id', 'name', 'transport', 'host', 'port', 'username', 'authMethod',
            'keyId', 'keyFilePath', 'password', 'remoteHostKey', 'strictHostKey',
            'connectTimeout',
        ], array_keys($c));
        $this->assertSame('SSH', $c['transport']);
        $this->assertSame(22, $c['port']);
        $this->assertSame(1, Credentials::SCHEMA_VERSION, 'the transport field must NOT bump the schema');
    }

    public function testConnTransportsEnum(): void
    {
        // LOCAL is a JOB transport, never a CONNECTION transport.
        $this->assertSame(['SSH', 'DAEMON'], Credentials::CONN_TRANSPORTS);
        $this->assertSame(873, Credentials::RSYNCD_PORT);
    }

    public function testMergeConnectionKeyOrderIsFrozenAndPutsTransportBeforePort(): void
    {
        $keys = array_keys(Credentials::mergeConnection([]));
        $this->assertSame(self::MERGED_KEY_ORDER, $keys);
        // Structural proof of the ordering constraint: the port default reads the
        // RESOLVED transport, so transport must be written first.
        $this->assertLessThan(
            array_search('port', $keys, true),
            array_search('transport', $keys, true),
            'transport must be resolved before the port default'
        );
    }

    #[DataProvider('connectionTransportClampProvider')]
    public function testMergeConnectionClampsTransport(mixed $stored, string $expected): void
    {
        $c = Credentials::mergeConnection(['id' => 'c', 'name' => 'n', 'host' => 'h', 'username' => 'u', 'transport' => $stored]);
        $this->assertSame($expected, $c['transport']);
    }

    public static function connectionTransportClampProvider(): array
    {
        return [
            'exact SSH'         => ['SSH', 'SSH'],
            'exact DAEMON'      => ['DAEMON', 'DAEMON'],
            'lowercase daemon'  => ['daemon', 'DAEMON'],
            'mixed + padded'    => ["  DaEmOn \n", 'DAEMON'],
            'lowercase ssh'     => ['ssh', 'SSH'],
            'junk FTP'          => ['FTP', 'SSH'],
            'job-only LOCAL'    => ['LOCAL', 'SSH'],
            'empty string'      => ['', 'SSH'],
            'null'              => [null, 'SSH'],
            'int'               => [0, 'SSH'],
        ];
    }

    public function testMergeConnectionBackfillsALegacyRecordToSshAndChangesNothingElse(): void
    {
        // A record exactly as a pre-daemon plugin wrote it: no transport key at
        // all. The whole merged array is asserted (order included) so any other
        // drift in the merge is caught, not just the new key.
        $legacy = [
            'id' => 'c-nas', 'name' => 'NAS', 'host' => 'nas.example', 'port' => 22,
            'username' => 'backup', 'authMethod' => 'KEYFILE', 'keyId' => '',
            'keyFilePath' => '/root/.ssh/id_ed25519', 'password' => '',
            'remoteHostKey' => 'nas.example ssh-ed25519 AAAA',
            'strictHostKey' => 'accept-new', 'connectTimeout' => 10,
        ];
        $this->assertSame([
            'id'             => 'c-nas',
            'name'           => 'NAS',
            'host'           => 'nas.example',
            'username'       => 'backup',
            'keyId'          => '',
            'keyFilePath'    => '/root/.ssh/id_ed25519',
            'password'       => '',
            'remoteHostKey'  => 'nas.example ssh-ed25519 AAAA',
            'transport'      => 'SSH',
            'port'           => 22,
            'authMethod'     => 'KEYFILE',
            'strictHostKey'  => 'accept-new',
            'connectTimeout' => 10,
        ], Credentials::mergeConnection($legacy));
    }

    public function testLegacyCredentialsFileOnDiskLoadsAsSshTransport(): void
    {
        // The on-disk no-breaking-change path: an existing credentials.json with
        // no transport key anywhere still loads, keeps schemaVersion 1, and every
        // connection comes back as SSH on its stored port.
        file_put_contents(Credentials::path(), json_encode([
            'schemaVersion' => 1,
            'keys'          => [],
            'connections'   => [
                ['id' => 'c-1', 'name' => 'a', 'host' => 'h1', 'username' => 'u1', 'port' => 22],
                ['id' => 'c-2', 'name' => 'b', 'host' => 'h2', 'username' => 'u2', 'port' => 2222],
            ],
        ]));
        $loaded = Credentials::load();
        $this->assertSame(1, $loaded['schemaVersion']);
        $this->assertSame(['SSH', 'SSH'], array_column($loaded['connections'], 'transport'));
        $this->assertSame([22, 2222], array_column($loaded['connections'], 'port'));
    }

    #[DataProvider('transportAwarePortProvider')]
    public function testMergeConnectionPortDefaultFollowsTheResolvedTransport(array $in, int $expected): void
    {
        $this->assertSame($expected, Credentials::mergeConnection($in)['port']);
    }

    public static function transportAwarePortProvider(): array
    {
        return [
            // DAEMON with no stored port lands on 873. This is ALSO the ordering
            // proof: if the port default ran before the transport clamp it would
            // read an unresolved transport and give 22.
            'daemon, no port'         => [['transport' => 'DAEMON'], 873],
            'daemon lowercase, no port' => [['transport' => 'daemon'], 873],
            'daemon, out-of-range'    => [['transport' => 'DAEMON', 'port' => 99999], 873],
            'daemon, zero'            => [['transport' => 'DAEMON', 'port' => 0], 873],
            // An explicitly stored port always wins, even the SSH default on a
            // connection that was switched to DAEMON (that is what rsyncDaemonNote
            // warns about, rather than silently rewriting the user's value).
            'daemon, stored 22'       => [['transport' => 'DAEMON', 'port' => 22], 22],
            'daemon, stored 8730'     => [['transport' => 'DAEMON', 'port' => 8730], 8730],
            // SSH and unknown transports keep the pre-daemon behaviour exactly.
            'ssh, no port'            => [['transport' => 'SSH'], 22],
            'ssh, out-of-range'       => [['transport' => 'SSH', 'port' => 99999], 22],
            'ssh, stored 2222'        => [['transport' => 'SSH', 'port' => 2222], 2222],
            'legacy, no transport'    => [['host' => 'h'], 22],
            'legacy, stored 22'       => [['host' => 'h', 'port' => 22], 22],
            'junk transport, no port' => [['transport' => 'FTP'], 22],
            'junk transport, bad port' => [['transport' => 'FTP', 'port' => -1], 22],
        ];
    }

    // --- daemon token safety (rsync parse_hostspec reinterpretation) --------

    #[DataProvider('unsafeDaemonTokenProvider')]
    public function testIsSafeDaemonTokenRejectsHostspecBreakCharacters(string $value): void
    {
        $this->assertFalse(Credentials::isSafeDaemonToken($value));
    }

    /**
     * Every one of these is accepted by isSafeSshToken today, so the daemon rule
     * is demonstrably the thing that catches them - and isSafeSshToken itself is
     * unchanged, which is what keeps every existing SSH connection saveable.
     */
    #[DataProvider('unsafeDaemonTokenProvider')]
    public function testIsSafeSshTokenIsUnchangedByTheDaemonRule(string $value): void
    {
        $this->assertTrue(Credentials::isSafeSshToken($value));
    }

    public static function unsafeDaemonTokenProvider(): array
    {
        return [
            // ':' - rsync's parse_hostspec breaks the authority at the first ':',
            // so a username "a:b" makes "a:b@nas::mod" an SSH connection to host
            // "a" over the DEFAULT remote shell: no pinned host key, no key, no port.
            'colon'            => ['a:b'],
            'leading colon'    => [':nas'],
            'trailing colon'   => ['nas:'],
            'double colon'     => ['nas::mod'],
            // '/' - parse_hostspec returns NULL and rsync treats the WHOLE operand
            // as a local path, so a "backup" quietly writes into this box.
            'slash'            => ['nas/evil'],
            'leading slash'    => ['/nas'],
            // URL-ish and IPv6-bracketed pastes.
            'question mark'    => ['nas?x'],
            'hash'             => ['nas#x'],
            'open bracket'     => ['[nas'],
            'close bracket'    => ['nas]'],
            'bracketed ipv6'   => ['[2001:db8::1]'],
            'bare ipv6'        => ['2001:db8::1'],
            'padded colon'     => ['  a:b  '],
        ];
    }

    public function testIsSafeDaemonTokenAcceptsOrdinaryHostsAndUsernames(): void
    {
        foreach (['nas.local', 'my-nas.sub.example.net', '10.0.0.5', 'moduser', 'backup_user', 'a'] as $v) {
            $this->assertTrue(Credentials::isSafeDaemonToken($v), "'$v' must be accepted");
        }
        // ...and everything isSafeSshToken already rejects stays rejected.
        foreach (['', '   ', '-oProxyCommand=evil', 'user@evil', 'a b', 'h;id', 'h`id`', 'h|nc', 'h$(id)'] as $v) {
            $this->assertFalse(Credentials::isSafeDaemonToken($v), "'$v' must be rejected");
        }
    }

    // --- validateConnection: DAEMON arm -------------------------------------

    public function testValidateConnectionAcceptsADaemonConnectionWithNoSshAuthFields(): void
    {
        // A daemon card hides the auth controls but a hidden <select> still POSTs
        // its value, so the SSH-only rules must not fire: no authMethod enum, no
        // strictHostKey enum, no key-file / managed-key / password requirement.
        // The module secret is OPTIONAL (an anonymous rsyncd module is legal).
        $conn = [
            'transport' => 'DAEMON', 'name' => 'NAS module', 'host' => 'nas.local',
            'username' => 'moduser', 'port' => 873,
            'authMethod' => 'telnet', 'strictHostKey' => 'maybe',
            'keyId' => '', 'keyFilePath' => '', 'password' => '',
        ];
        $res = Credentials::validateConnection($conn, Credentials::defaults());
        $this->assertSame(['valid' => true, 'errors' => []], $res);

        // The SAME record on SSH transport still gets both enum errors - proof the
        // daemon arm is a real branch and not a blanket relaxation.
        $res = Credentials::validateConnection(['transport' => 'SSH'] + $conn, Credentials::defaults());
        $this->assertSame([
            'Auth method must be KEYFILE, KEY or PASSWORD.',
            'Strict host key checking must be accept-new, yes or no.',
        ], $res['errors']);
    }

    public function testValidateConnectionDaemonStillRequiresNameHostUsernameAndPort(): void
    {
        // The shared rules (and their exact order) are the same on both arms.
        $res = Credentials::validateConnection(
            ['transport' => 'DAEMON', 'name' => '', 'host' => '', 'username' => '', 'port' => 0],
            Credentials::defaults()
        );
        $this->assertSame([
            'Connection name is required.',
            'Host is required.',
            'Username is required.',
            'Port must be between 1 and 65535.',
        ], $res['errors']);
    }

    #[DataProvider('unsafeDaemonTokenProvider')]
    public function testValidateConnectionRejectsUnsafeDaemonHost(string $host): void
    {
        $res = Credentials::validateConnection([
            'transport' => 'DAEMON', 'name' => 'n', 'host' => $host,
            'username' => 'moduser', 'port' => 873,
        ], Credentials::defaults());
        // An IPv6 literal gets its own, more specific message; everything else
        // gets the charset message. Either way, exactly ONE error.
        $expected = in_array($host, ['2001:db8::1', '[2001:db8::1]'], true)
            ? self::DAEMON_IPV6_ERROR
            : self::DAEMON_HOST_ERROR;
        $this->assertSame([$expected], $res['errors'], "host '$host'");
    }

    #[DataProvider('unsafeDaemonTokenProvider')]
    public function testValidateConnectionRejectsUnsafeDaemonUsername(string $user): void
    {
        $res = Credentials::validateConnection([
            'transport' => 'DAEMON', 'name' => 'n', 'host' => 'nas.local',
            'username' => $user, 'port' => 873,
        ], Credentials::defaults());
        // The IPv6 exemption is host-only: a username is always the charset rule.
        $this->assertSame([self::DAEMON_USER_ERROR], $res['errors'], "username '$user'");
    }

    #[DataProvider('ipv6DaemonHostProvider')]
    public function testValidateConnectionRejectsIpv6DaemonHostsWithASpecificMessage(string $host): void
    {
        $res = Credentials::validateConnection([
            'transport' => 'DAEMON', 'name' => 'n', 'host' => $host,
            'username' => 'moduser', 'port' => 873,
        ], Credentials::defaults());
        $this->assertSame([self::DAEMON_IPV6_ERROR], $res['errors'], "host '$host'");
    }

    public static function ipv6DaemonHostProvider(): array
    {
        return [
            'link local'  => ['fe80::1'],
            'doc prefix'  => ['2001:db8::1'],
            'loopback'    => ['::1'],
            'bracketed'   => ['[2001:db8::1]'],
            'uppercase'   => ['2001:DB8::1'],
        ];
    }

    /**
     * The concrete rsync-side reinterpretations the daemon charset rule exists to
     * stop. Each pair is (host, username) as stored on the Connection; the third
     * column is the operand rsync would have been handed.
     */
    #[DataProvider('hostspecReinterpretationProvider')]
    public function testDaemonConnectionRejectsParseHostspecReinterpretations(
        string $host,
        string $username,
        array $expectedErrors
    ): void {
        $res = Credentials::validateConnection([
            'transport' => 'DAEMON', 'name' => 'n', 'host' => $host,
            'username' => $username, 'port' => 873,
        ], Credentials::defaults());
        $this->assertSame($expectedErrors, $res['errors']);
        $this->assertFalse($res['valid']);
    }

    public static function hostspecReinterpretationProvider(): array
    {
        return [
            // "a:b@nas::mod" -> parse_hostspec breaks at the first ':' and rsync
            // opens an SSH connection to host "a" over the DEFAULT remote shell.
            'username with colon' => ['nas.local', 'a:b', [self::DAEMON_USER_ERROR]],
            // "u@nas/evil::mod" -> parse_hostspec returns NULL and rsync treats
            // the whole operand as a LOCAL path.
            'host with slash'     => ['nas/evil', 'moduser', [self::DAEMON_HOST_ERROR]],
            'username with slash' => ['nas.local', 'u/x', [self::DAEMON_USER_ERROR]],
            // A pasted daemon address in the Host field.
            'host with ::'        => ['nas::rsync_bkp', 'moduser', [self::DAEMON_HOST_ERROR]],
            // Both sides bad -> both errors, host first (shared rule order).
            'both'                => ['nas/evil', 'a:b', [self::DAEMON_HOST_ERROR, self::DAEMON_USER_ERROR]],
        ];
    }

    #[DataProvider('daemonSecretProvider')]
    public function testValidateConnectionDaemonSecretLineBreakRule(string $plain, array $expectedErrors): void
    {
        $res = Credentials::validateConnection([
            'transport' => 'DAEMON', 'name' => 'n', 'host' => 'nas.local',
            'username' => 'moduser', 'port' => 873,
            'password' => Credentials::obfuscate($plain),
        ], Credentials::defaults());
        $this->assertSame($expectedErrors, $res['errors']);
    }

    public static function daemonSecretProvider(): array
    {
        return [
            // rsync's getpassf() strtok()s the password file at the first \n or
            // \r, so anything after one is silently discarded.
            'newline'        => ["first\nsecond", [self::DAEMON_SECRET_ERROR]],
            'carriage ret'   => ["first\rsecond", [self::DAEMON_SECRET_ERROR]],
            'trailing nl'    => ["hunter2\n", [self::DAEMON_SECRET_ERROR]],
            'nul byte'       => ["hun\x00ter2", [self::DAEMON_SECRET_ERROR]],
            'ordinary'       => ['hunter2', []],
            'spaces + utf8'  => ['  pässwörd with spaces  ', []],
            'anonymous'      => ['', []],
            // rsync's getpassf() reads with `read(fd, buffer, sizeof buffer - 1)`
            // into a char buffer[512] (authenticate.c:204): byte 512 onwards is
            // discarded in silence, so a longer secret authenticates with a
            // PREFIX of itself and the run dies with an unexplained
            // "auth failed on module". Reject it at save time instead.
            'exactly 511'    => [str_repeat('a', 511), []],
            '512 bytes'      => [str_repeat('a', 512), [self::DAEMON_SECRET_LENGTH_ERROR]],
            '4 KiB'          => [str_repeat('x', 4096), [self::DAEMON_SECRET_LENGTH_ERROR]],
            // Bytes, not characters: a 300-character UTF-8 secret whose bytes
            // exceed 511 is truncated by rsync just the same.
            'multibyte over' => [str_repeat('ä', 300), [self::DAEMON_SECRET_LENGTH_ERROR]],
            // One error at a time: a value that breaks both rules reports the
            // line break, which is the one the user can see.
            'both rules'     => [str_repeat('a', 600) . "\nmore", [self::DAEMON_SECRET_ERROR]],
        ];
    }

    /**
     * The constant is the rsync-imposed limit, not a taste: getpassf() reads
     * `sizeof buffer - 1` of a `char buffer[512]`.
     */
    public function testDaemonSecretMaxBytesMatchesRsyncsPasswordFileRead(): void
    {
        $this->assertSame(511, Credentials::DAEMON_SECRET_MAX_BYTES);
    }

    /**
     * A stored secret that fails a content rule cannot be cleared by submitting
     * the card blank - blank PRESERVES the stored value - so the message has to
     * say how to get out of the 422 it causes.
     */
    public function testDaemonSecretErrorsSayHowToReplaceTheStoredValue(): void
    {
        foreach (["a\nb", str_repeat('a', 512)] as $plain) {
            $res = Credentials::validateConnection([
                'transport' => 'DAEMON', 'name' => 'n', 'host' => 'nas.local',
                'username' => 'moduser', 'port' => 873,
                'password' => Credentials::obfuscate($plain),
            ], Credentials::defaults());
            $this->assertCount(1, $res['errors']);
            $this->assertStringContainsString(
                'Type a ',
                $res['errors'][0],
                'the message must tell the user to type a fresh secret into the card'
            );
            $this->assertStringContainsString('leaving the field blank keeps it', $res['errors'][0]);
        }
    }

    // --- validateConnection: the SSH arm is byte-identical to today ---------

    /**
     * The SSH rules, asserted as WHOLE error arrays with their order, so a stray
     * daemon branch leaking into the SSH arm (or a reordered shared rule) fails
     * here rather than silently changing what an existing save reports.
     */
    #[DataProvider('sshValidationProvider')]
    public function testValidateConnectionSshArmIsUnchanged(array $conn, array $expectedErrors): void
    {
        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'ssh-ed25519 AAAA'];
        $res = Credentials::validateConnection($conn, $creds);
        $this->assertSame($expectedErrors, $res['errors']);
        $this->assertSame($expectedErrors === [], $res['valid']);
    }

    public static function sshValidationProvider(): array
    {
        $ok = ['name' => 'n', 'host' => 'nas.local', 'username' => 'u', 'port' => 22, 'strictHostKey' => 'accept-new'];
        return [
            'everything missing' => [
                ['name' => '', 'host' => '', 'username' => '', 'port' => 0, 'authMethod' => 'telnet', 'strictHostKey' => 'maybe'],
                [
                    'Connection name is required.',
                    'Host is required.',
                    'Username is required.',
                    'Port must be between 1 and 65535.',
                    'Auth method must be KEYFILE, KEY or PASSWORD.',
                    'Strict host key checking must be accept-new, yes or no.',
                ],
            ],
            'unsafe host and username' => [
                ['name' => 'n', 'host' => '-oProxyCommand=evil', 'username' => 'a b', 'port' => 22,
                 'authMethod' => 'PASSWORD', 'password' => 'x', 'strictHostKey' => 'accept-new'],
                [
                    'Host contains unsafe characters or begins with "-".',
                    'Username contains unsafe characters or begins with "-".',
                ],
            ],
            'keyfile without a path' => [
                $ok + ['authMethod' => 'KEYFILE', 'keyFilePath' => '   '],
                ['Existing-key-file connections require a key file path.'],
            ],
            'keyfile relative path' => [
                $ok + ['authMethod' => 'KEYFILE', 'keyFilePath' => 'relative/id_ed25519'],
                ['The key file path must be an absolute path (starting with "/") and must not contain unsafe characters.'],
            ],
            'key auth without a key' => [
                $ok + ['authMethod' => 'KEY', 'keyId' => ''],
                ['Key-based connections must select an SSH key.'],
            ],
            'key auth, missing key' => [
                $ok + ['authMethod' => 'KEY', 'keyId' => 'k-nope'],
                ['The selected SSH key does not exist.'],
            ],
            'key auth, present key' => [
                $ok + ['authMethod' => 'KEY', 'keyId' => 'k-1'],
                [],
            ],
            'password auth, empty' => [
                $ok + ['authMethod' => 'PASSWORD', 'password' => ''],
                ['Password-based connections require a password.'],
            ],
            'password auth, set' => [
                $ok + ['authMethod' => 'PASSWORD', 'password' => 'hunter2'],
                [],
            ],
            // A legacy record has no transport key at all: it must take the SSH
            // arm, not trip the daemon charset rule.
            'legacy record, no transport key' => [
                $ok + ['authMethod' => 'KEYFILE', 'keyFilePath' => '/root/.ssh/id_ed25519'],
                [],
            ],
            // An unknown hand-edited transport also takes the SSH arm, matching
            // mergeConnection's clamp.
            'junk transport takes the SSH arm' => [
                ['transport' => 'FTP'] + $ok + ['authMethod' => 'telnet'],
                ['Auth method must be KEYFILE, KEY or PASSWORD.'],
            ],
        ];
    }

    /**
     * ':' and '/' remain LEGAL in an SSH host/username - they are only fatal for
     * the daemon operand. Tightening the SSH rule would break existing saves.
     */
    public function testSshHostAndUsernameStillAcceptColonAndSlash(): void
    {
        $creds = Credentials::defaults();
        foreach ([['a:b', 'u'], ['nas/evil', 'u'], ['nas.local', 'a:b'], ['nas.local', 'u/x']] as [$host, $user]) {
            $res = Credentials::validateConnection([
                'name' => 'n', 'host' => $host, 'username' => $user, 'port' => 22,
                'authMethod' => 'KEYFILE', 'keyFilePath' => '/root/.ssh/id_ed25519',
                'strictHostKey' => 'accept-new',
            ], $creds);
            $this->assertSame([], $res['errors'], "SSH '$user@$host' must still validate");
        }
    }

    /**
     * The transport is read case-insensitively and with surrounding whitespace
     * stripped, exactly as mergeConnection clamps it - so a hand-edited
     * credentials.json cannot slip past the daemon rules by lowercasing.
     */
    public function testValidateConnectionTransportMatchIsCaseInsensitive(): void
    {
        foreach (['daemon', 'DaEmOn', "  DAEMON \n"] as $t) {
            $res = Credentials::validateConnection([
                'transport' => $t, 'name' => 'n', 'host' => 'a:b',
                'username' => 'moduser', 'port' => 873,
            ], Credentials::defaults());
            $this->assertSame([self::DAEMON_HOST_ERROR], $res['errors'], "transport '$t'");
        }
    }

    // --- rsyncDaemonNote: the new third parameter ---------------------------

    /**
     * The two-argument form (every pre-daemon call site) must behave exactly as
     * it does today, and must be indistinguishable from an explicit 'SSH'.
     */
    #[DataProvider('sshDaemonNoteProvider')]
    public function testRsyncDaemonNoteDefaultsToTodaysSshBehaviour(int $port, string $host, string $expected): void
    {
        $this->assertSame($expected, Credentials::rsyncDaemonNote($port, $host));
        $this->assertSame($expected, Credentials::rsyncDaemonNote($port, $host, 'SSH'));
        // Any unknown/hand-edited value takes the SSH arm too.
        $this->assertSame($expected, Credentials::rsyncDaemonNote($port, $host, 'FTP'));
        $this->assertSame($expected, Credentials::rsyncDaemonNote($port, $host, ''));
    }

    public static function sshDaemonNoteProvider(): array
    {
        $daemonShaped = 'This looks like an rsync daemon address (rsync:// or host::module). '
            . 'This Connection uses SSH transport, so enter just the hostname or IP here '
            . 'and put the remote path on the job\'s pair. To use an rsync daemon instead, '
            . 'set this Connection\'s Transport to "rsync daemon (rsyncd)".';
        $port873 = 'Port 873 is the rsync daemon (rsyncd) port, which is a different protocol '
            . 'from rsync-over-SSH. Either enable SSH on the remote host and use its SSH port '
            . '(usually 22), or set this Connection\'s Transport to "rsync daemon (rsyncd)".';
        return [
            'plain host, 22'        => [22, 'nas.local', ''],
            'plain host, 2222'      => [2222, 'nas.local', ''],
            'plain host, 873'       => [873, 'nas.local', $port873],
            'ipv6 host, 22'         => [22, '2001:db8::1', ''],
            'ipv6 host, 873'        => [873, '2001:db8::1', $port873],
            'rsync url'             => [22, 'rsync://nas/module', $daemonShaped],
            'double colon'          => [22, 'nas::module', $daemonShaped],
            // The host shape wins over the port, as it does today.
            'double colon on 873'   => [873, 'nas::module', $daemonShaped],
            'no host given'         => [22, '', ''],
        ];
    }

    /**
     * On DAEMON transport the PORT test is inverted rather than dropped: 873 is
     * now correct and anything else is the likely misconfiguration (typically a
     * connection left on the SSH default 22).
     */
    #[DataProvider('daemonNoteProvider')]
    public function testRsyncDaemonNoteInvertsForDaemonTransport(int $port, string $host, string $expected): void
    {
        $this->assertSame($expected, Credentials::rsyncDaemonNote($port, $host, 'DAEMON'));
        // Case/whitespace insensitive, matching mergeConnection's clamp.
        $this->assertSame($expected, Credentials::rsyncDaemonNote($port, $host, '  daemon '));
    }

    public static function daemonNoteProvider(): array
    {
        $daemonShaped = 'This looks like a full rsync daemon address (rsync:// or host::module). '
            . 'Enter just the hostname or IP here; the module goes in the job\'s pair.';
        return [
            'correct port'      => [873, 'nas.local', ''],
            'left on SSH 22'    => [22, 'nas.local', 'Port 22 is unusual for an rsync daemon; rsyncd normally listens on 873.'],
            'odd port'          => [2222, 'nas.local', 'Port 2222 is unusual for an rsync daemon; rsyncd normally listens on 873.'],
            // The host shape still wins over the port.
            'rsync url on 873'  => [873, 'rsync://nas/module', $daemonShaped],
            'double colon on 22' => [22, 'nas::module', $daemonShaped],
        ];
    }

    // --- usedBy is unaffected by the transport field ------------------------

    public function testUsedByConnectionListsJobsRegardlessOfTransport(): void
    {
        $creds = Credentials::defaults();
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-d', 'name' => 'nas-rsyncd', 'transport' => 'DAEMON',
            'host' => 'nas.local', 'username' => 'moduser',
        ]);
        $config = Config::defaults();
        $config['jobs'][] = ['id' => 'j-1', 'name' => 'photos', 'connectionId' => 'c-d', 'enabled' => true];
        $config['jobs'][] = ['id' => 'j-2', 'name' => 'music', 'connectionId' => 'c-other', 'enabled' => true];

        $used = Credentials::usedBy($creds, 'connection', 'c-d', $config);
        $this->assertSame([['id' => 'j-1', 'name' => 'photos']], $used['jobs']);
    }

    public function testUsedByKeyIgnoresADaemonConnectionThatIsNotKeyAuth(): void
    {
        // A daemon connection carries the default KEYFILE authMethod, so it must
        // not hold a managed key hostage.
        $creds = Credentials::defaults();
        $creds['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'p'];
        $creds['connections'][] = Credentials::mergeConnection([
            'id' => 'c-d', 'name' => 'nas-rsyncd', 'transport' => 'DAEMON',
            'host' => 'nas.local', 'username' => 'moduser', 'keyId' => 'k-1',
        ]);
        $this->assertSame([], Credentials::usedBy($creds, 'key', 'k-1')['connections']);
    }
}

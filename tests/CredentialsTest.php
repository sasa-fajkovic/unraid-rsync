<?php

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
        $path = Credentials::path();
        if (is_file($path)) {
            unlink($path);
        }
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
}

<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Phase 3 Credentials actions in handler.php: section-aware
 * saveCredentials (keys/connections never clobber each other), CSRF, the
 * used_by delete semantics (key blocked by a connection; connection delete
 * disables dependent jobs), password preservation on edit, and that secrets are
 * never echoed back.
 *
 * The shell-out actions (generateKey/importKey/discoverHostKey/testConnection)
 * are covered at the unit level in KeyToolsTest / SshTest with stubbed binaries;
 * here we focus on the handler's persistence + integrity logic, which needs no
 * live ssh tooling.
 */
final class HandlerCredentialsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('UR_HANDLER_TESTING')) {
            define('UR_HANDLER_TESTING', true);
        }
        require_once __DIR__ . '/../source/include/handler.php';
    }

    protected function setUp(): void
    {
        $_POST    = [];
        $_GET     = [];
        $_REQUEST = [];
        // Reset the handler's intended-status-code test seam instead of calling
        // http_response_code(200), which warns under CLI/PHP 8.4 once output has
        // begun (failOnWarning would fail the test). See sendResponse.
        $GLOBALS['ur_last_response_code'] = 200;
        $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        foreach ([Credentials::path(), Config::path()] as $p) {
            if (is_file($p)) {
                unlink($p);
            }
        }
    }

    private function runCapture(callable $fn): array
    {
        ob_start();
        $fn();
        $out = ob_get_clean();
        return [json_decode($out, true), (int) ($GLOBALS['ur_last_response_code'] ?? 200)];
    }

    private function seedCreds(array $creds): void
    {
        Credentials::save($creds);
    }

    // --- saveCredentials: section-aware ------------------------------------

    public function testSaveConnectionsCreatesAndAssignsId(): void
    {
        // A key must exist for a KEY-auth connection to validate.
        $seed = Credentials::defaults();
        $seed['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'ssh-ed25519 AAAA'];
        $this->seedCreds($seed);

        $_POST = [
            'action'              => 'saveCredentials',
            'csrf_token'          => 'test-token',
            'connections_present' => '1',
            'connections'         => [
                0 => [
                    'id' => '', 'name' => 'web', 'host' => 'h.example', 'port' => '2222',
                    'username' => 'sasa', 'authMethod' => 'KEY', 'keyId' => 'k-1',
                    'strictHostKey' => 'accept-new', 'connectTimeout' => '10',
                ],
            ],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);

        $creds = Credentials::load();
        $this->assertCount(1, $creds['connections']);
        $this->assertSame('c-web', $creds['connections'][0]['id']); // slugged
        $this->assertSame(2222, $creds['connections'][0]['port']);
        // The key section was untouched.
        $this->assertCount(1, $creds['keys']);
    }

    public function testSaveConnectionsDoesNotWipeKeys(): void
    {
        $seed = Credentials::defaults();
        $seed['keys'][] = ['id' => 'k-1', 'name' => 'keep', 'publicKey' => 'p', 'privateKey' => 'PRIV', 'fingerprint' => 'SHA256:x'];
        $this->seedCreds($seed);

        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => ['id' => '', 'name' => 'c', 'host' => 'h', 'username' => 'u', 'authMethod' => 'PASSWORD', 'password' => 'pw']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $creds = Credentials::load();
        $this->assertCount(1, $creds['keys'], 'keys survive a connections-only save');
        $this->assertSame('keep', $creds['keys'][0]['name']);
        // Password was obfuscated (not stored as plaintext).
        $this->assertNotSame('pw', $creds['connections'][0]['password']);
        $this->assertSame('pw', Credentials::deobfuscate($creds['connections'][0]['password']));
    }

    public function testSaveKeysRenameOnlyPreservesMaterial(): void
    {
        $seed = Credentials::defaults();
        $seed['keys'][] = ['id' => 'k-1', 'name' => 'old', 'publicKey' => 'PUB', 'privateKey' => 'PRIV', 'fingerprint' => 'SHA256:fp'];
        $this->seedCreds($seed);

        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'keys_present' => '1',
            // The keys form carries only id + name - never key material.
            'keys' => [0 => ['id' => 'k-1', 'name' => 'renamed']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $creds = Credentials::load();
        $this->assertSame('renamed', $creds['keys'][0]['name']);
        // Secret material preserved from the on-disk key (matched by id).
        $this->assertSame('PRIV', $creds['keys'][0]['privateKey']);
        $this->assertSame('PUB', $creds['keys'][0]['publicKey']);
        $this->assertSame('SHA256:fp', $creds['keys'][0]['fingerprint']);
    }

    public function testSaveConnectionEmptyPasswordPreservesExisting(): void
    {
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'c', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'PASSWORD', 'password' => Credentials::obfuscate('orig'),
        ]);
        $this->seedCreds($seed);

        // Edit the host but leave password blank -> existing password preserved.
        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => [
                'id' => 'c-1', 'name' => 'c', 'host' => 'h2', 'username' => 'u',
                'authMethod' => 'PASSWORD', 'password' => '',
            ]],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $creds = Credentials::load();
        $this->assertSame('h2', $creds['connections'][0]['host']);
        $this->assertSame('orig', Credentials::deobfuscate($creds['connections'][0]['password']));
    }

    public function testSaveConnectionSwitchToKeyClearsPassword(): void
    {
        $seed = Credentials::defaults();
        $seed['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'p'];
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'c', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'PASSWORD', 'password' => Credentials::obfuscate('orig'),
        ]);
        $this->seedCreds($seed);

        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => [
                'id' => 'c-1', 'name' => 'c', 'host' => 'h', 'username' => 'u',
                'authMethod' => 'KEY', 'keyId' => 'k-1', 'password' => '',
            ]],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $creds = Credentials::load();
        $this->assertSame('KEY', $creds['connections'][0]['authMethod']);
        $this->assertSame('', $creds['connections'][0]['password']);
    }

    public function testSaveConnectionsDoesNotDeleteOmittedConnectionByOmission(): void
    {
        // Two saved connections; the Connections form submits an edit of only
        // ONE of them. The other must be PRESERVED (deletion is deleteConnection's
        // job, not a side effect of a partial save).
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection(['id' => 'c-1', 'name' => 'one', 'host' => 'h1', 'username' => 'u', 'authMethod' => 'PASSWORD', 'password' => Credentials::obfuscate('pw1')]);
        $seed['connections'][] = Credentials::mergeConnection(['id' => 'c-2', 'name' => 'two', 'host' => 'h2', 'username' => 'u', 'authMethod' => 'PASSWORD', 'password' => Credentials::obfuscate('pw2')]);
        $this->seedCreds($seed);

        // Leave the password blank on the edit -> the existing password is
        // preserved (so the password-required rule is still satisfied).
        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => ['id' => 'c-1', 'name' => 'one-edited', 'host' => 'h1', 'username' => 'u', 'authMethod' => 'PASSWORD', 'password' => '']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $creds = Credentials::load();
        $byId = [];
        foreach ($creds['connections'] as $c) {
            $byId[$c['id']] = $c['name'];
        }
        $this->assertSame('one-edited', $byId['c-1'] ?? null); // edited
        $this->assertSame('two', $byId['c-2'] ?? null);        // preserved, not deleted
    }

    public function testSaveConnectionsClearingFieldsDoesNotSilentlyDeleteSavedRow(): void
    {
        // Clearing a saved connection's visible fields must NOT silently drop it
        // (a row carrying an id is an edit, not an empty template) - it surfaces
        // a validation error instead, so the user cannot orphan jobs this way.
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection(['id' => 'c-1', 'name' => 'keep', 'host' => 'h', 'username' => 'u', 'authMethod' => 'PASSWORD']);
        $this->seedCreds($seed);

        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => ['id' => 'c-1', 'name' => '', 'host' => '', 'username' => '', 'authMethod' => 'PASSWORD', 'password' => '']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(422, $code, json_encode($body));
        // The connection is still on disk, untouched.
        $this->assertNotNull(Credentials::findConnection(Credentials::load(), 'c-1'));
    }

    public function testSaveKeysDoesNotDeleteOmittedKeyByOmission(): void
    {
        // The keys form submits only one of two keys; the other is preserved
        // (deletion is deleteKey's job, which enforces the usedBy block).
        $seed = Credentials::defaults();
        $seed['keys'][] = ['id' => 'k-1', 'name' => 'one', 'publicKey' => 'P1', 'privateKey' => 'X1', 'fingerprint' => 'SHA256:1'];
        $seed['keys'][] = ['id' => 'k-2', 'name' => 'two', 'publicKey' => 'P2', 'privateKey' => 'X2', 'fingerprint' => 'SHA256:2'];
        $this->seedCreds($seed);

        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'keys_present' => '1',
            'keys' => [0 => ['id' => 'k-1', 'name' => 'one-renamed']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $creds = Credentials::load();
        $byId = [];
        foreach ($creds['keys'] as $k) {
            $byId[$k['id']] = $k;
        }
        $this->assertSame('one-renamed', $byId['k-1']['name']);
        $this->assertSame('X1', $byId['k-1']['privateKey']);   // material preserved
        $this->assertArrayHasKey('k-2', $byId);                // NOT deleted by omission
        $this->assertSame('two', $byId['k-2']['name']);
        $this->assertSame('X2', $byId['k-2']['privateKey']);
    }

    // --- KEYFILE auth save round-trip --------------------------------------

    public function testSaveKeyfileConnectionCreatesAndStoresPath(): void
    {
        // A KEYFILE connection needs neither a managed key nor a password; only
        // an absolute key file path (existence is a run-time concern).
        $_POST = [
            'action'              => 'saveCredentials',
            'csrf_token'          => 'test-token',
            'connections_present' => '1',
            'connections'         => [
                0 => [
                    'id' => '', 'name' => 'keyfileconn', 'host' => 'h.example', 'port' => '22',
                    'username' => 'root', 'authMethod' => 'KEYFILE',
                    'keyFilePath' => '/root/.ssh/id_ed25519',
                    'strictHostKey' => 'accept-new', 'connectTimeout' => '10',
                ],
            ],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);

        $creds = Credentials::load();
        $this->assertCount(1, $creds['connections']);
        $c = $creds['connections'][0];
        $this->assertSame('KEYFILE', $c['authMethod']);
        $this->assertSame('/root/.ssh/id_ed25519', $c['keyFilePath']);
        // No password, no managed-key reference stored for a KEYFILE connection.
        $this->assertSame('', $c['password']);
        $this->assertSame('', $c['keyId']);
    }

    public function testSaveKeyfileConnectionRejectsRelativePath(): void
    {
        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => [
                'id' => '', 'name' => 'bad', 'host' => 'h', 'username' => 'u',
                'authMethod' => 'KEYFILE', 'keyFilePath' => 'relative/key',
            ]],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(422, $code, json_encode($body));
        $this->assertArrayHasKey('errors', $body);
        $this->assertFalse(is_file(Credentials::path()));
    }

    public function testSwitchingToKeyfileClearsKeyIdAndPassword(): void
    {
        // A connection that was PASSWORD becomes KEYFILE: the stored password and
        // any keyId are cleared so no stale credential lingers.
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'c', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'PASSWORD', 'password' => Credentials::obfuscate('orig'),
        ]);
        $this->seedCreds($seed);

        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => [
                'id' => 'c-1', 'name' => 'c', 'host' => 'h', 'username' => 'u',
                'authMethod' => 'KEYFILE', 'keyFilePath' => '/root/.ssh/id_ed25519', 'password' => '',
            ]],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $c = Credentials::load()['connections'][0];
        $this->assertSame('KEYFILE', $c['authMethod']);
        $this->assertSame('/root/.ssh/id_ed25519', $c['keyFilePath']);
        $this->assertSame('', $c['password']);
        $this->assertSame('', $c['keyId']);
    }

    public function testSaveCredentialsRejectsInvalidConnection(): void
    {
        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            // KEY auth with a non-existent key -> validation error.
            'connections' => [0 => ['id' => '', 'name' => 'bad', 'host' => 'h', 'username' => 'u', 'authMethod' => 'KEY', 'keyId' => 'k-nope']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(422, $code);
        $this->assertArrayHasKey('errors', $body);
        $this->assertFalse(is_file(Credentials::path()));
    }

    public function testSaveCredentialsRefusedWhenUnreadable(): void
    {
        file_put_contents(
            Credentials::path(),
            json_encode(['schemaVersion' => Credentials::SCHEMA_VERSION + 9])
        );
        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'keys_present' => '1', 'keys' => [0 => ['id' => '', 'name' => 'x']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(409, $code);
        // On-disk newer file untouched.
        $raw = json_decode(file_get_contents(Credentials::path()), true);
        $this->assertSame(Credentials::SCHEMA_VERSION + 9, $raw['schemaVersion']);
    }

    // --- deleteKey: blocked by connection ----------------------------------

    public function testDeleteKeyBlockedByConnection(): void
    {
        $seed = Credentials::defaults();
        $seed['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'p'];
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'web', 'host' => 'h', 'username' => 'u',
            'authMethod' => 'KEY', 'keyId' => 'k-1',
        ]);
        $this->seedCreds($seed);

        $_POST = ['action' => 'deleteKey', 'csrf_token' => 'test-token', 'id' => 'k-1'];
        [$body, $code] = $this->runCapture(fn() => ur_action_delete_key());
        $this->assertSame(409, $code);
        $this->assertArrayHasKey('usedBy', $body);
        $this->assertStringContainsString('web', $body['error']);

        // The key is still there.
        $creds = Credentials::load();
        $this->assertNotNull(Credentials::findKey($creds, 'k-1'));
    }

    public function testDeleteKeyAllowedWhenUnused(): void
    {
        $seed = Credentials::defaults();
        $seed['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'p'];
        $this->seedCreds($seed);

        $_POST = ['action' => 'deleteKey', 'csrf_token' => 'test-token', 'id' => 'k-1'];
        [$body, $code] = $this->runCapture(fn() => ur_action_delete_key());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([], Credentials::load()['keys']);
    }

    // --- deleteConnection: disables dependent jobs -------------------------

    public function testDeleteConnectionDisablesDependentJobs(): void
    {
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-1', 'name' => 'rpi', 'host' => 'h', 'username' => 'u', 'authMethod' => 'PASSWORD',
        ]);
        $this->seedCreds($seed);

        // Two enabled jobs reference c-1; one references another connection.
        $config = Config::defaults();
        $config['jobs'][] = Job::normalize(['name' => 'music', 'connectionId' => 'c-1', 'enabled' => true,
            'transport' => 'SSH', 'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/srv/a/']]]);
        $config['jobs'][] = Job::normalize(['name' => 'photos', 'connectionId' => 'c-1', 'enabled' => true,
            'transport' => 'SSH', 'pairs' => [['local' => '/mnt/user/b/', 'remote' => '/srv/b/']]]);
        $config['jobs'][] = Job::normalize(['name' => 'other', 'connectionId' => 'c-2', 'enabled' => true,
            'transport' => 'SSH', 'pairs' => [['local' => '/mnt/user/c/', 'remote' => '/srv/c/']]]);
        Config::save($config);

        $_POST = ['action' => 'deleteConnection', 'csrf_token' => 'test-token', 'id' => 'c-1'];
        [$body, $code] = $this->runCapture(fn() => ur_action_delete_connection());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertEqualsCanonicalizing(['music', 'photos'], $body['disabledJobs']);

        // The connection is gone.
        $this->assertNull(Credentials::findConnection(Credentials::load(), 'c-1'));

        // The two dependent jobs are disabled; the unrelated one is untouched.
        $jobs = [];
        foreach (Config::load()['jobs'] as $j) {
            $jobs[$j['name']] = $j['enabled'];
        }
        $this->assertFalse($jobs['music']);
        $this->assertFalse($jobs['photos']);
        $this->assertTrue($jobs['other']);
    }

    public function testDeleteConnectionNoDependentsLeavesConfig(): void
    {
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection(['id' => 'c-1', 'name' => 'x', 'host' => 'h', 'username' => 'u', 'authMethod' => 'PASSWORD']);
        $this->seedCreds($seed);

        $_POST = ['action' => 'deleteConnection', 'csrf_token' => 'test-token', 'id' => 'c-1'];
        [$body, $code] = $this->runCapture(fn() => ur_action_delete_connection());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([], $body['disabledJobs']);
    }

    public function testSaveCredentialsWarnsWhenPasswordConnAndSshpassMissing(): void
    {
        // Saving a PASSWORD connection while sshpass is unavailable still
        // succeeds, but the response carries a clear warning so the user knows
        // password auth won't work yet.
        Ssh::$sshpassPathOverride = ''; // simulate "sshpass not installed"
        try {
            $_POST = [
                'action' => 'saveCredentials', 'csrf_token' => 'test-token',
                'connections_present' => '1',
                'connections' => [0 => ['id' => '', 'name' => 'pw', 'host' => 'h.example', 'username' => 'u', 'authMethod' => 'PASSWORD', 'password' => 'secret']],
            ];
            [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
            $this->assertSame(200, $code, json_encode($body));
            $this->assertTrue($body['ok']);
            $this->assertNotEmpty($body['warnings']);
            $this->assertNotEmpty(array_filter($body['warnings'], fn($w) => stripos($w, 'sshpass') !== false));
        } finally {
            Ssh::$sshpassPathOverride = null;
        }
    }

    public function testSaveCredentialsKeyOnlyHasNoSshpassWarning(): void
    {
        Ssh::$sshpassPathOverride = '';
        try {
            $seed = Credentials::defaults();
            $seed['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'p'];
            $this->seedCreds($seed);
            $_POST = [
                'action' => 'saveCredentials', 'csrf_token' => 'test-token',
                'connections_present' => '1',
                'connections' => [0 => ['id' => '', 'name' => 'web', 'host' => 'h', 'username' => 'u', 'authMethod' => 'KEY', 'keyId' => 'k-1']],
            ];
            [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
            $this->assertSame(200, $code, json_encode($body));
            $this->assertSame([], $body['warnings']); // KEY auth -> no sshpass warning
        } finally {
            Ssh::$sshpassPathOverride = null;
        }
    }

    // --- generateKey input validation (no ssh-keygen needed) ---------------

    public function testGenerateKeyRejectsUnsupportedTypeWith422(): void
    {
        // An unsupported key type is client input -> 422 BEFORE any ssh-keygen
        // call, so this is testable without the binary. (It must NOT be a 500.)
        $_POST = ['action' => 'generateKey', 'csrf_token' => 'test-token', 'name' => 'k', 'type' => 'dsa'];
        [$body, $code] = $this->runCapture(fn() => ur_action_generate_key());
        $this->assertSame(422, $code, json_encode($body));
        $this->assertStringContainsString('Unsupported key type', $body['error']);
        // Nothing persisted.
        $this->assertFalse(is_file(Credentials::path()));
    }

    public function testGenerateKeyRejectsEmptyNameWith422(): void
    {
        $_POST = ['action' => 'generateKey', 'csrf_token' => 'test-token', 'name' => '', 'type' => 'ed25519'];
        [, $code] = $this->runCapture(fn() => ur_action_generate_key());
        $this->assertSame(422, $code);
    }

    // --- CSRF on the new actions -------------------------------------------

    public function testCsrfEnforcedOnSaveCredentials(): void
    {
        $_POST = ['action' => 'saveCredentials', 'csrf_token' => 'wrong', 'keys_present' => '1'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        [$body, $code] = $this->runCapture(fn() => ur_handle_request());
        $this->assertSame(403, $code);
        $this->assertStringContainsString('CSRF', $body['error']);
    }

    public function testCsrfMismatchReturnsCleanJson(): void
    {
        // A mismatched token must produce a parseable JSON error envelope (NOT an
        // HTML fatal), so the client can surface a clear message. Status is read
        // from the handler's intended-status seam (http_response_code() is
        // unreliable under CLI once output has begun - see sendResponse).
        $_POST = ['action' => 'generateKey', 'csrf_token' => 'wrong', 'name' => 'k'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        ur_handle_request();
        $out = ob_get_clean();
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'response must be valid JSON, got: ' . $out);
        $this->assertSame(403, (int) ($GLOBALS['ur_last_response_code'] ?? 0));
        $this->assertArrayHasKey('error', $decoded);
    }

    public function testCsrfMissingTokenReturnsJsonError(): void
    {
        // Token field absent entirely -> still a clean JSON 403.
        $_POST = ['action' => 'deleteKey'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        ur_handle_request();
        $out = ob_get_clean();
        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'response must be valid JSON, got: ' . $out);
        $this->assertSame(403, (int) ($GLOBALS['ur_last_response_code'] ?? 0));
    }

    public function testExpectedCsrfTokenFallsBackToVarIni(): void
    {
        // ROOT-CAUSE regression guard: when $GLOBALS['var'] is NOT populated
        // (a direct POST to handler.php), the expected token must come from the
        // Unraid state file via parse_ini_file - the fix for the silent-403 bug.
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "version=\"7.3.1\"\ncsrf_token=\"from-state-ini\"\n");
        try {
            unset($GLOBALS['var']); // simulate the standalone-endpoint case
            $this->assertSame('from-state-ini', ur_expected_csrf_token());
        } finally {
            @unlink($path);
            $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        }
    }

    public function testCsrfValidatesAgainstVarIniTokenOnDirectPost(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "csrf_token=\"direct-token\"\n");
        try {
            unset($GLOBALS['var']); // direct POST: front controller never set $var
            $_POST = ['csrf_token' => 'direct-token'];
            [, $code] = $this->runCapture(fn() => $this->assertTrue(ur_check_csrf()));
            $this->assertSame(200, $code);
        } finally {
            @unlink($path);
            $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        }
    }

    // --- CSRF match-any (live-diagnosed: a stale $var/$_SESSION token must NOT
    //     mask the correct var.ini token) ------------------------------------

    /**
     * THE live bug: $GLOBALS['var']['csrf_token'] and $_SESSION['csrf_token'] both
     * hold STALE/different values, while the supplied token matches the canonical
     * var.ini token. The old "first non-empty source wins" logic 403'd; match-any
     * must accept it.
     */
    public function testCsrfMatchesVarIniEvenWhenVarAndSessionDiffer(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "csrf_token=\"canonical-token\"\n");
        $prevSession = $_SESSION ?? null;
        try {
            $GLOBALS['var']        = ['csrf_token' => 'stale-var-token'];
            $_SESSION              = ['csrf_token' => 'stale-session-token'];
            $_POST                 = ['csrf_token' => 'canonical-token'];
            [, $code] = $this->runCapture(fn() => $this->assertTrue(ur_check_csrf()));
            $this->assertSame(200, $code);
        } finally {
            @unlink($path);
            if ($prevSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $prevSession;
            }
            $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        }
    }

    /** A token matching ONLY $_SESSION (var + var.ini differ) must pass. */
    public function testCsrfMatchesSessionOnly(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "csrf_token=\"other-ini-token\"\n");
        $prevSession = $_SESSION ?? null;
        try {
            $GLOBALS['var'] = ['csrf_token' => 'other-var-token'];
            $_SESSION       = ['csrf_token' => 'session-token'];
            $_POST          = ['csrf_token' => 'session-token'];
            [, $code] = $this->runCapture(fn() => $this->assertTrue(ur_check_csrf()));
            $this->assertSame(200, $code);
        } finally {
            @unlink($path);
            if ($prevSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $prevSession;
            }
            $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        }
    }

    /** A token matching NO candidate must 403. */
    public function testCsrfMismatchEverywhereRejected(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "csrf_token=\"ini-token\"\n");
        $prevSession = $_SESSION ?? null;
        try {
            $GLOBALS['var'] = ['csrf_token' => 'var-token'];
            $_SESSION       = ['csrf_token' => 'session-token'];
            $_POST          = ['csrf_token' => 'totally-wrong'];
            [, $code] = $this->runCapture(fn() => ur_check_csrf());
            $this->assertSame(403, $code);
        } finally {
            @unlink($path);
            if ($prevSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $prevSession;
            }
            $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        }
    }

    /** With NO candidates available at all, any supplied token 403s. */
    public function testCsrfNoCandidatesRejected(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @unlink($path); // ensure no var.ini candidate
        $prevSession = $_SESSION ?? null;
        try {
            unset($GLOBALS['var']);
            unset($_SESSION);
            $_POST = ['csrf_token' => 'anything'];
            [, $code] = $this->runCapture(fn() => ur_check_csrf());
            $this->assertSame(403, $code);
        } finally {
            if ($prevSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $prevSession;
            }
            $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        }
    }

    /** An empty supplied token 403s even when candidates exist. */
    public function testCsrfEmptySuppliedRejectedWithCandidates(): void
    {
        $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        $_POST          = ['csrf_token' => ''];
        [, $code] = $this->runCapture(fn() => ur_check_csrf());
        $this->assertSame(403, $code);
    }

    /** ur_csrf_token_candidates() returns all non-empty sources, de-duplicated. */
    public function testCsrfCandidatesAreCollectedAndDeduped(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        // var.ini token DUPLICATES the $var token -> must appear once.
        file_put_contents($path, "csrf_token=\"shared-token\"\n");
        $prevSession = $_SESSION ?? null;
        try {
            $GLOBALS['var'] = ['csrf_token' => 'shared-token'];
            $_SESSION       = ['csrf_token' => 'session-token'];
            $candidates = ur_csrf_token_candidates();
            $this->assertContains('shared-token', $candidates);
            $this->assertContains('session-token', $candidates);
            // de-duplicated: 'shared-token' present exactly once.
            $this->assertSame(1, count(array_keys($candidates, 'shared-token', true)));
            // no empty entries.
            $this->assertNotContains('', $candidates);
        } finally {
            @unlink($path);
            if ($prevSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $prevSession;
            }
            $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        }
    }

    public function testGetMethodRejectedForCredentialActions(): void
    {
        $_GET = ['action' => 'deleteKey'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        [, $code] = $this->runCapture(fn() => ur_handle_request());
        $this->assertSame(405, $code);
    }

    // --- Supplied-token recovery: the webGui front controller strips csrf_token
    //     out of $_POST before our handler runs, so ur_supplied_csrf_token must
    //     recover it from $_REQUEST/$_GET or the raw urlencoded body. -----------

    /** $_POST takes precedence when present. */
    public function testSuppliedCsrfPrefersPost(): void
    {
        $_POST    = ['csrf_token' => 'from-post'];
        $_REQUEST = ['csrf_token' => 'from-request'];
        $this->assertSame('from-post', ur_supplied_csrf_token());
    }

    /** With $_POST/$_REQUEST/$_GET empty, the token is recovered from the raw body. */
    public function testSuppliedCsrfRecoversFromRawBody(): void
    {
        $_POST = $_GET = $_REQUEST = [];
        $raw = 'action=saveCredentials&csrf_token=raw-token&connections_present=1';
        $this->assertSame('raw-token', ur_supplied_csrf_token($raw));
    }

    /** Nothing anywhere -> empty (never throws). */
    public function testSuppliedCsrfEmptyEverywhere(): void
    {
        $_POST = $_GET = $_REQUEST = [];
        $this->assertSame('', ur_supplied_csrf_token(''));
        $this->assertSame('', ur_supplied_csrf_token('action=saveCredentials')); // no csrf field
    }

    /**
     * THE live bug end-to-end: the front controller stripped csrf_token from
     * $_POST, but the correct token is still in the raw body and matches a
     * candidate (here var.ini) -> ur_check_csrf must accept it.
     */
    public function testCsrfAcceptedWhenStrippedFromPostButInRawBody(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "csrf_token=\"raw-only-token\"\n");
        try {
            // $_POST has NO csrf_token (front controller removed it); $_REQUEST/$_GET
            // also empty. Only the raw body carries it. No $var either (direct POST).
            unset($GLOBALS['var']);
            $_POST = ['action' => 'saveCredentials']; // action survived, csrf did not
            $_GET = $_REQUEST = [];
            $raw = 'action=saveCredentials&csrf_token=raw-only-token&connections_present=1';
            $this->assertSame('raw-only-token', ur_supplied_csrf_token($raw));
            // The full acceptance path: ur_check_csrf must accept the raw-body token.
            [, $code] = $this->runCapture(fn() => $this->assertTrue(ur_check_csrf($raw)));
            $this->assertSame(200, $code);
            // And reject when the raw body carries a WRONG token.
            [, $code2] = $this->runCapture(fn() => ur_check_csrf('csrf_token=not-the-token'));
            $this->assertSame(403, $code2);
        } finally {
            @unlink($path);
            $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        }
    }

    // --- Robust var.ini csrf read: recover the token even when parse_ini_file()
    //     bails on an UNRELATED malformed line elsewhere in the (large,
    //     machine-written) state file. This is the live-403 class: a readable
    //     var.ini whose canonical csrf_token line is fine, but whose overall
    //     parse fails, must still yield the token on a direct POST. ------------

    /**
     * Sanity: a stray section bracket makes parse_ini_file() return FALSE for the
     * whole file, yet ur_csrf_tokens_from_ini() recovers the clean token line.
     */
    public function testCsrfTokensFromIniRecoversWhenParseIniFails(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        // The trailing ']' is a syntax error that makes parse_ini_file() bail on
        // the ENTIRE file (verified across PHP 8.x); the csrf_token line is fine.
        file_put_contents($path, "csrf_token=\"recovered-token\"\nversion=\"7.3.1\"\n]\n");
        try {
            $this->assertFalse(
                @parse_ini_file($path, false, INI_SCANNER_RAW),
                'precondition: parse_ini_file must fail on this file'
            );
            $this->assertSame(['recovered-token'], ur_csrf_tokens_from_ini($path));
        } finally {
            @unlink($path);
        }
    }

    /**
     * THE live bug end-to-end: direct POST (no $var/$_SESSION), the ONLY token
     * source is a var.ini whose parse_ini_file() fails - the supplied (correct)
     * token must still be accepted via the robust line scan.
     */
    public function testCsrfAcceptedFromUnparseableVarIniOnDirectPost(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "csrf_token=\"live-token\"\n]\n");
        $prevSession = $_SESSION ?? null;
        try {
            unset($GLOBALS['var']);     // direct POST: front controller never set $var
            unset($_SESSION);           // and no session token either
            $_POST = ['csrf_token' => 'live-token'];
            [, $code] = $this->runCapture(fn() => $this->assertTrue(ur_check_csrf()));
            $this->assertSame(200, $code);
        } finally {
            @unlink($path);
            if ($prevSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $prevSession;
            }
            $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        }
    }

    /** Unquoted and whitespace-padded token forms are both recovered. */
    public function testCsrfTokensFromIniHandlesUnquotedAndPadding(): void
    {
        if (!defined('UR_VAR_INI_PATHS')) {
            $this->markTestSkipped('UR_VAR_INI_PATHS not overridable in this build');
        }
        $path = UR_VAR_INI_PATHS[0];
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, "csrf_token = ABC123DEF\n]\n");
        try {
            $this->assertSame(['ABC123DEF'], ur_csrf_tokens_from_ini($path));
        } finally {
            @unlink($path);
        }
    }

    /** A missing/unreadable file yields no tokens (never an error). */
    public function testCsrfTokensFromIniMissingFileReturnsEmpty(): void
    {
        $this->assertSame([], ur_csrf_tokens_from_ini('/no/such/var.ini'));
        $this->assertSame([], ur_csrf_tokens_from_ini(''));
    }
}

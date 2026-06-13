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
        $_POST = [];
        $_GET  = [];
        http_response_code(200);
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
        return [json_decode($out, true), http_response_code()];
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

    // --- CSRF on the new actions -------------------------------------------

    public function testCsrfEnforcedOnSaveCredentials(): void
    {
        $_POST = ['action' => 'saveCredentials', 'csrf_token' => 'wrong', 'keys_present' => '1'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        [$body, $code] = $this->runCapture(fn() => ur_handle_request());
        $this->assertSame(403, $code);
        $this->assertStringContainsString('CSRF', $body['error']);
    }

    public function testGetMethodRejectedForCredentialActions(): void
    {
        $_GET = ['action' => 'deleteKey'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        [, $code] = $this->runCapture(fn() => ur_handle_request());
        $this->assertSame(405, $code);
    }
}

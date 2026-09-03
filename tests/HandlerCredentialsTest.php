<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
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
        // Reset the secrets-dir override BEFORE resolving Credentials::path() so a
        // leak from a prior migration test can't point cleanup at the wrong file.
        Credentials::$secretsDirOverride = null;
        foreach ([Credentials::path(), Config::path()] as $p) {
            if (is_file($p)) {
                unlink($p);
            }
        }
    }

    protected function tearDown(): void
    {
        Credentials::$secretsDirOverride = null;
        // The daemon module-listing probe seam is a public static: leaving a
        // fake installed would silently fake every later test's probe.
        Rsync::$daemonProbeRunner = null;
    }

    /** A throwaway "array" secrets dir path (created by the code under test). */
    private function tempSecretsDir(): string
    {
        return sys_get_temp_dir() . '/ur-secretsdir-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    private function rmSecretsDir(string $dir): void
    {
        @unlink($dir . '/credentials.json');
        @rmdir($dir);
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

    public function testSaveCredentialsWarnsAboutTheRsyncDaemonPort(): void
    {
        // Port 873 is rsyncd, not SSH. The save still succeeds (running sshd on
        // 873 is legal, just unusual) but the response must say so - otherwise
        // the mistake only surfaces much later as an opaque "not running SSH".
        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => ['id' => '', 'name' => 'QNAP', 'host' => 'h.example', 'port' => '873', 'username' => 'rsync', 'authMethod' => 'PASSWORD', 'password' => 'secret']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertNotEmpty(array_filter($body['warnings'], fn($w) => strpos($w, '873') !== false));
    }

    public function testSaveCredentialsSshPortHasNoWarning(): void
    {
        $seed = Credentials::defaults();
        $seed['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'p'];
        $this->seedCreds($seed);
        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => ['id' => '', 'name' => 'web', 'host' => 'h', 'port' => '22', 'username' => 'u', 'authMethod' => 'KEY', 'keyId' => 'k-1']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([], $body['warnings']);
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

    // --- secretsDir migration ----------------------------------------------
    //
    // The handler sanitises the POSTed path to /mnt/<top>/<leaf> (Config::
    // sanitizeSecretsDir), so a real move to a writable directory can't be driven
    // end-to-end from a unit test (no writable /mnt here) - that happy path is
    // validated on the live tower. Here we cover (a) the handler glue + validation
    // branches and (b) ur_migrate_credentials() mechanics directly, where the
    // function takes raw dirs and does NOT sanitise.

    public function testInvalidSecretsDirRejectedWithWarningAndNoMove(): void
    {
        $this->seedCreds(Credentials::defaults());
        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'global'     => ['secretsDir' => '/etc/evil'],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertNotEmpty($body['warnings']);
        $this->assertStringContainsString('under /mnt', $body['warnings'][0]);
        // Rejected -> stays on /boot.
        $this->assertSame('', Config::load()['global']['secretsDir']);
    }

    public function testHandlerEnablesSecretsDirWhenNoCredentialsYet(): void
    {
        // Fresh install: no credentials.json anywhere. Enabling a valid /mnt path
        // commits the pointer with nothing to move - exercises the full handler
        // glue (sanitise -> migrate -> commit pointer -> update in-request override)
        // without needing a writable /mnt.
        $this->assertFileDoesNotExist(Credentials::path());

        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'global'     => ['secretsDir' => '/mnt/user/system/unraid.rsync'],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertSame([], $body['warnings']);
        $this->assertSame('/mnt/user/system/unraid.rsync', Config::load()['global']['secretsDir']);
        // The handler pushed the new path into the in-request override.
        $this->assertSame('/mnt/user/system/unraid.rsync', Credentials::$secretsDirOverride);
    }

    public function testUnchangedSecretsDirDoesNotTouchFile(): void
    {
        // secretsDir already empty; re-saving an empty value is a no-op move.
        $seed = Credentials::defaults();
        $seed['connections'][] = ['id' => 'c-1', 'name' => 'stay', 'host' => 'h', 'username' => 'u'];
        $this->seedCreds($seed);
        $before = filemtime(Credentials::path());

        $_POST = [
            'action'     => 'saveConfig',
            'csrf_token' => 'test-token',
            'global'     => ['secretsDir' => ''],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_config());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame('', Config::load()['global']['secretsDir']);
        clearstatcache();
        $this->assertSame($before, filemtime(Credentials::path())); // untouched
    }

    public function testMigrationHelperNoSourceFileJustMovesPointer(): void
    {
        // No credentials.json anywhere yet: enabling secretsDir is allowed and the
        // pointer moves with nothing to copy.
        $dir = $this->tempSecretsDir();
        $res = ur_migrate_credentials('', $dir);
        $this->assertTrue($res['ok']);
        $this->assertSame('', $res['warning']);
        $this->assertFileDoesNotExist($dir . '/credentials.json');
    }

    public function testMigrationHelperMovesFileAndTightensPerms(): void
    {
        $src = $this->tempSecretsDir();
        $dst = $this->tempSecretsDir();
        mkdir($src, 0777, true);
        $payload = "{\"schemaVersion\":1,\"keys\":[],\"connections\":[]}\n";
        file_put_contents($src . '/credentials.json', $payload);

        $res = ur_migrate_credentials($src, $dst);

        $this->assertTrue($res['ok'], $res['warning']);
        $this->assertSame('', $res['warning']);
        // Moved: source gone, destination has the exact bytes, perms tightened.
        $this->assertFileDoesNotExist($src . '/credentials.json');
        $this->assertFileExists($dst . '/credentials.json');
        $this->assertSame($payload, file_get_contents($dst . '/credentials.json'));
        clearstatcache();
        $this->assertSame(0600, fileperms($dst . '/credentials.json') & 0777);

        $this->rmSecretsDir($src);
        $this->rmSecretsDir($dst);
    }

    public function testMigrationHelperFromBootDefaultUsesConfigBase(): void
    {
        // $from === '' means the /boot config base (UR_CONFIG_BASE). Seed there.
        Credentials::$secretsDirOverride = null;
        $boot = Credentials::path();
        file_put_contents($boot, "{\"schemaVersion\":1,\"keys\":[],\"connections\":[]}\n");

        $dst = $this->tempSecretsDir();
        $res = ur_migrate_credentials('', $dst);

        $this->assertTrue($res['ok'], $res['warning']);
        $this->assertFileDoesNotExist($boot);
        $this->assertFileExists($dst . '/credentials.json');
        $this->rmSecretsDir($dst);
    }

    public function testMigrationHelperNeverClobbersExistingDestination(): void
    {
        $src = $this->tempSecretsDir();
        $dst = $this->tempSecretsDir();
        mkdir($src, 0777, true);
        mkdir($dst, 0777, true);
        file_put_contents($src . '/credentials.json', "SOURCE\n");
        file_put_contents($dst . '/credentials.json', "EXISTING\n");

        $res = ur_migrate_credentials($src, $dst);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('already contains', $res['warning']);
        // Both files untouched.
        $this->assertSame("SOURCE\n", file_get_contents($src . '/credentials.json'));
        $this->assertSame("EXISTING\n", file_get_contents($dst . '/credentials.json'));

        $this->rmSecretsDir($src);
        $this->rmSecretsDir($dst);
    }

    public function testMigrationHelperFailsGracefullyWhenDestDirUncreatable(): void
    {
        // Destination parent is a regular FILE, so mkdir can never succeed - this
        // deterministically exercises the "array not started / dir uncreatable"
        // branch on any OS (no reliance on /mnt being unwritable).
        $src = $this->tempSecretsDir();
        mkdir($src, 0777, true);
        file_put_contents($src . '/credentials.json', "SOURCE\n");

        $blocker = sys_get_temp_dir() . '/ur-blocker-' . getmypid() . '-' . bin2hex(random_bytes(4));
        file_put_contents($blocker, 'x'); // a file where a dir would need to be
        $dst = $blocker . '/sub';

        $res = ur_migrate_credentials($src, $dst);

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('could not create', $res['warning']);
        // Source untouched - credentials never lost on a failed move.
        $this->assertSame("SOURCE\n", file_get_contents($src . '/credentials.json'));

        @unlink($blocker);
        $this->rmSecretsDir($src);
    }

    // =====================================================================
    // rsync DAEMON transport (issue #139)
    //
    // The handler-side contract: transport survives the save round-trip, the
    // module secret follows the same preserve-on-blank ladder as an SSH
    // password without ever coming back out, the SSH-only fields are cleared,
    // a transport change warns about the jobs it just broke, and
    // testConnection dispatches to the module-listing probe instead of ssh.
    // The probe is faked through Rsync::$daemonProbeRunner - no rsync is ever
    // spawned here.
    // =====================================================================

    /** obfuscate() is deterministic (xorPad + base64), so expectations can name it. */
    private const DAEMON_SECRET = 'm0duleS3cret';

    /** The per-save S-E1 warning, already prefixed with the "nas" connection label. */
    private const DAEMON_UNENCRYPTED_WARNING = 'nas: The rsync daemon protocol is not encrypted. '
        . 'Only a challenge/response (MD4 with old peers) protects the module secret, and file names '
        . 'and file contents travel in clear. Use SSH transport on any untrusted network.';

    /** Seed one saved DAEMON connection (id c-nas) carrying an obfuscated secret. */
    private function seedDaemonConnection(string $secret = self::DAEMON_SECRET): void
    {
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection([
            'id'        => 'c-nas',
            'name'      => 'nas',
            'transport' => 'DAEMON',
            'host'      => 'nas.local',
            'username'  => 'moduser',
            'password'  => ($secret === '') ? '' : Credentials::obfuscate($secret),
        ]);
        $this->seedCreds($seed);
    }

    /**
     * A saveCredentials POST carrying one DAEMON connection card. The card posts
     * the SSH-only controls too, on purpose: display:none does NOT suppress
     * submission, so this is exactly what the browser sends.
     *
     * @param array<string,string> $overrides
     * @return array<string,mixed>
     */
    private function daemonSavePost(array $overrides = []): array
    {
        return [
            'action'              => 'saveCredentials',
            'csrf_token'          => 'test-token',
            'connections_present' => '1',
            'connections'         => [0 => array_merge([
                'id'             => '',
                'name'           => 'nas',
                'host'           => 'nas.local',
                'username'       => 'moduser',
                'transport'      => 'DAEMON',
                'authMethod'     => 'KEYFILE',
                'strictHostKey'  => 'accept-new',
                'connectTimeout' => '10',
                'password'       => self::DAEMON_SECRET,
            ], $overrides)],
        ];
    }

    /** Like runCapture(), but hands back the RAW serialized body for leak sweeps. */
    private function runCaptureRaw(callable $fn): string
    {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }

    /**
     * Install a fake module-listing probe. Returns a by-reference recorder whose
     * ['argv'] is the argv the probe was called with (null = never called) and
     * whose ['calls'] counts invocations.
     *
     * @param array{0:int,1:string} $result [exitCode, combined output]
     * @return array<string,mixed>
     */
    private function &fakeDaemonProbe(array $result): array
    {
        $seen = ['argv' => null, 'calls' => 0];
        Rsync::$daemonProbeRunner = static function (array $argv) use (&$seen, $result): array {
            $seen['argv'] = $argv;
            $seen['calls']++;
            return $result;
        };
        return $seen;
    }

    // --- saving a daemon connection ----------------------------------------

    public function testSaveDaemonConnectionStoresTheWholeRecordOnTheRsyncdPort(): void
    {
        // No port is submitted: mergeConnection must derive 873 from the RESOLVED
        // transport. The whole record is asserted (key order included) because
        // that order is the pinned mergeConnection contract.
        $_POST = $this->daemonSavePost();
        unset($_POST['connections'][0]['port']);

        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertSame([self::DAEMON_UNENCRYPTED_WARNING], $body['warnings']);

        $this->assertSame([
            'id'             => 'c-nas',
            'name'           => 'nas',
            'host'           => 'nas.local',
            'username'       => 'moduser',
            'keyId'          => '',
            'keyFilePath'    => '',
            'password'       => Credentials::obfuscate(self::DAEMON_SECRET),
            'remoteHostKey'  => '',
            'transport'      => 'DAEMON',
            'port'           => 873,
            'authMethod'     => 'KEYFILE',
            'strictHostKey'  => 'accept-new',
            'connectTimeout' => 10,
        ], Credentials::load()['connections'][0]);
    }

    public function testSaveDaemonConnectionBlankSecretPreservesTheStoredSecret(): void
    {
        // The same preserve-on-blank ladder an SSH password gets: the card never
        // renders the stored secret, so a blank field means "unchanged", never
        // "clear it". Editing the host must not silently drop the secret.
        $this->seedDaemonConnection();

        $_POST = $this->daemonSavePost([
            'id'       => 'c-nas',
            'host'     => 'nas2.local',
            'password' => '',
        ]);
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $conn = Credentials::load()['connections'][0];
        $this->assertSame('nas2.local', $conn['host']);
        $this->assertSame(self::DAEMON_SECRET, Credentials::deobfuscate($conn['password']));
    }

    public function testSaveDaemonConnectionChangedSecretReplacesTheStoredSecret(): void
    {
        $this->seedDaemonConnection();

        $_POST = $this->daemonSavePost(['id' => 'c-nas', 'password' => 'rotated-secret']);
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $conn = Credentials::load()['connections'][0];
        $this->assertSame(Credentials::obfuscate('rotated-secret'), $conn['password']);
        $this->assertSame('rotated-secret', Credentials::deobfuscate($conn['password']));
    }

    public function testSaveDaemonConnectionWithAnAnonymousModuleStoresNoSecret(): void
    {
        // An rsyncd module without `auth users` needs no secret at all, so an
        // empty one on a BRAND-NEW card (nothing stored to preserve) must save.
        $_POST = $this->daemonSavePost(['password' => '']);
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $this->assertSame('', Credentials::load()['connections'][0]['password']);
    }

    public function testSwitchingAConnectionToDaemonClearsKeyIdKeyFilePathAndRemoteHostKey(): void
    {
        // The card still POSTS the SSH-only controls (display:none does not stop
        // submission), so the handler must clear them itself - nothing else ever
        // clears remoteHostKey, and a stale pinned SSH host key on a daemon
        // record would sit there forever.
        $seed = Credentials::defaults();
        $seed['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'ssh-ed25519 AAAA'];
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-nas', 'name' => 'nas', 'host' => 'nas.local', 'username' => 'moduser',
            'authMethod' => 'KEY', 'keyId' => 'k-1', 'keyFilePath' => '/root/.ssh/id_ed25519',
            'remoteHostKey' => 'nas.local ssh-ed25519 AAAAPINNED',
        ]);
        $this->seedCreds($seed);

        $_POST = $this->daemonSavePost([
            'id'            => 'c-nas',
            'authMethod'    => 'KEY',
            'keyId'         => 'k-1',
            'keyFilePath'   => '/root/.ssh/id_ed25519',
            'remoteHostKey' => 'nas.local ssh-ed25519 AAAAPINNED',
        ]);
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $conn = Credentials::load()['connections'][0];
        $this->assertSame('DAEMON', $conn['transport']);
        $this->assertSame('', $conn['keyId']);
        $this->assertSame('', $conn['keyFilePath']);
        $this->assertSame('', $conn['remoteHostKey']);
        // The managed key itself is untouched - clearing the reference is not a delete.
        $this->assertNotNull(Credentials::findKey(Credentials::load(), 'k-1'));
    }

    /**
     * A saveCredentials POST that OMITS connections[i][transport] must leave the
     * stored transport ALONE. The reachable case is a Connections tab left open
     * across the plugin upgrade: that page has no Transport control, so its POST
     * carries no such field. Defaulting an absent field to 'SSH' silently
     * reclassified the record - resetting the port (recoverable) and, because the
     * password-clearing predicate keys off the transport, DESTROYING the module
     * secret (not recoverable).
     */
    public function testSaveWithNoSubmittedTransportKeepsTheStoredDaemonRecordIntact(): void
    {
        $this->seedDaemonConnection();

        // A pre-upgrade card posts a key file path and the port it was rendered
        // with. Both matter: without the key path the (wrong) SSH reclassification
        // would merely 422, hiding the real damage behind a validation error.
        $_POST = $this->daemonSavePost([
            'id'          => 'c-nas',
            'password'    => '',
            'keyFilePath' => '/root/.ssh/id_ed25519',
        ]);
        unset($_POST['connections'][0]['transport']);
        $_POST['connections'][0]['port'] = '873';

        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $conn = Credentials::load()['connections'][0];
        $this->assertSame('DAEMON', $conn['transport'], 'an absent field means unchanged, never SSH');
        $this->assertSame(873, $conn['port']);
        $this->assertSame(
            self::DAEMON_SECRET,
            Credentials::deobfuscate($conn['password']),
            'the module secret must survive a POST that never mentioned the transport'
        );
    }

    /**
     * The same rule with the port left out as well: an absent transport must not
     * drag the port back to the SSH default either.
     */
    public function testSaveWithNoSubmittedTransportOrPortKeepsTheRsyncdPort(): void
    {
        $this->seedDaemonConnection();

        $_POST = $this->daemonSavePost([
            'id'          => 'c-nas',
            'password'    => '',
            'keyFilePath' => '/root/.ssh/id_ed25519',
        ]);
        unset($_POST['connections'][0]['transport'], $_POST['connections'][0]['port']);

        [, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code);

        $conn = Credentials::load()['connections'][0];
        $this->assertSame('DAEMON', $conn['transport']);
        $this->assertSame(873, $conn['port']);
    }

    /** An absent transport on a BRAND-NEW card (nothing stored) still means SSH. */
    public function testSaveWithNoSubmittedTransportOnANewCardDefaultsToSsh(): void
    {
        $_POST = $this->daemonSavePost([
            'password'    => '',
            'keyFilePath' => '/root/.ssh/id_ed25519',   // it saves as an SSH KEYFILE card
        ]);
        unset($_POST['connections'][0]['transport']);

        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame('SSH', Credentials::load()['connections'][0]['transport']);
        $this->assertSame(22, Credentials::load()['connections'][0]['port']);
    }

    /**
     * The post-save warning loop is scoped to the connections THIS request
     * submitted. A keys-only save (renaming an SSH key, no connections in the
     * POST at all) must not lecture the user about every stored daemon
     * connection it never touched.
     */
    public function testKeysOnlySaveDoesNotWarnAboutUntouchedDaemonConnections(): void
    {
        $seed = Credentials::defaults();
        $seed['keys'][] = ['id' => 'k-1', 'name' => 'kk', 'publicKey' => 'ssh-ed25519 AAAA'];
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-nas', 'name' => 'nas', 'transport' => 'DAEMON',
            'host' => 'nas.local', 'username' => 'moduser',
        ]);
        // ...and an SSH connection on the rsyncd port, whose (pre-existing) note
        // is scoped by the very same rule.
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-ssh', 'name' => 'ssh873', 'host' => 'other.local',
            'username' => 'u', 'port' => 873, 'authMethod' => 'PASSWORD',
            'password' => Credentials::obfuscate('pw'),
        ]);
        $this->seedCreds($seed);

        $_POST = [
            'action'     => 'saveCredentials',
            'csrf_token' => 'test-token',
            'keys'       => [0 => ['id' => 'k-1', 'name' => 'renamed', 'publicKey' => 'ssh-ed25519 AAAA']],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([], $body['warnings'], 'a keys-only save touches no connection, so it warns about none');
        // The connections themselves are untouched, not dropped.
        $this->assertCount(2, Credentials::load()['connections']);
    }

    /** ...but a save that DOES submit the daemon card still warns about it. */
    public function testSubmittedDaemonConnectionStillWarns(): void
    {
        $this->seedDaemonConnection();

        $_POST = $this->daemonSavePost(['id' => 'c-nas', 'password' => '']);
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([self::DAEMON_UNENCRYPTED_WARNING], $body['warnings']);
    }

    /** @return array<string,array{0:string}> */
    public static function daemonSecretControlByteProvider(): array
    {
        return [
            'line feed'       => ["first\nsecond"],
            'carriage return' => ["first\rsecond"],
            'NUL'             => ["first\x00second"],
            'trailing LF'     => ["secret\n"],
        ];
    }

    /**
     * rsync's getpassf() strtok()s the password file at the first \n or \r
     * (authenticate.c:175-217), so an embedded break silently truncates the
     * secret and the user gets an auth failure with no clue why. Reject the save.
     */
    #[DataProvider('daemonSecretControlByteProvider')]
    public function testSaveDaemonConnectionRejectsASecretWithAControlByte(string $secret): void
    {
        $_POST = $this->daemonSavePost(['password' => $secret]);
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());

        $this->assertSame(422, $code, json_encode($body));
        $this->assertSame([
            'Connection "nas": The module secret must not contain line breaks: rsync reads only the '
            . 'first line of the password file, so everything after it would be silently discarded. '
            . 'Type a new secret into this Connection to replace the stored one - leaving the field '
            . 'blank keeps it.',
        ], $body['errors']);
        // Nothing persisted - a rejected save never writes credentials.json.
        $this->assertFalse(is_file(Credentials::path()));
    }

    public function testSshPasswordWithALineBreakIsStillAccepted(): void
    {
        // The line-break rule is DAEMON-only: SSH passwords go to the askpass
        // helper, not to rsync's getpassf(), so this must not have become a new
        // rejection for existing SSH connections.
        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => [
                'id' => '', 'name' => 'sshbox', 'host' => 'h', 'username' => 'u',
                'authMethod' => 'PASSWORD', 'password' => "first\nsecond",
            ]],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame(
            "first\nsecond",
            Credentials::deobfuscate(Credentials::load()['connections'][0]['password'])
        );
    }

    // --- secrets never reach the browser -----------------------------------

    /**
     * Sweep every credential/test action a daemon connection touches and assert
     * the RAW serialized response carries neither the plaintext secret, nor its
     * (reversible) obfuscated form, nor any tmpfs runtime path. Asserted on the
     * whole body, not a field, so a secret can never sneak out inside a nested
     * error/warning string.
     */
    public function testNoDaemonActionResponseEverLeaksTheSecretOrATmpfsPath(): void
    {
        $needles = [
            self::DAEMON_SECRET,
            Credentials::obfuscate(self::DAEMON_SECRET),
            rtrim(Ssh::$runtimeBase, '/'),
        ];

        $bodies = [];

        // 1. create, secret submitted in the clear.
        $_POST = $this->daemonSavePost();
        $bodies['saveCredentials/create'] = $this->runCaptureRaw(fn() => ur_action_save_credentials());

        // 2. edit with a blank secret (the preserve path re-reads the stored value).
        $_POST = $this->daemonSavePost(['id' => 'c-nas', 'password' => '']);
        $bodies['saveCredentials/preserve'] = $this->runCaptureRaw(fn() => ur_action_save_credentials());

        // 3. a rejected save that DID carry the secret in the submission.
        $_POST = $this->daemonSavePost(['id' => 'c-nas', 'host' => 'evil/host']);
        $bodies['saveCredentials/422'] = $this->runCaptureRaw(fn() => ur_action_save_credentials());

        // 4. a successful probe, 5. a failing probe.
        $seen  = &$this->fakeDaemonProbe([0, "rsync_bkp\tBackups\n"]);
        $_POST = ['action' => 'testConnection', 'csrf_token' => 'test-token', 'id' => 'c-nas'];
        $bodies['testConnection/ok'] = $this->runCaptureRaw(fn() => ur_action_test_connection());
        Rsync::$daemonProbeRunner = static fn(array $argv): array => [10, 'rsync: failed to connect'];
        $bodies['testConnection/fail'] = $this->runCaptureRaw(fn() => ur_action_test_connection());

        // 6. delete (last - it removes the connection).
        $_POST = ['action' => 'deleteConnection', 'csrf_token' => 'test-token', 'id' => 'c-nas'];
        $bodies['deleteConnection'] = $this->runCaptureRaw(fn() => ur_action_delete_connection());

        $this->assertSame(1, $seen['calls'], 'the probe seam must have been exercised');
        foreach ($bodies as $action => $raw) {
            $this->assertNotSame('', $raw, "$action produced no body");
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $raw, "$action leaked '$needle'");
            }
        }
    }

    // --- D17: changing a referenced connection's transport ------------------

    /** Two enabled jobs on c-nas, one on another connection. */
    private function seedJobsReferencingNas(): void
    {
        $config = Config::defaults();
        $config['jobs'][] = Job::normalize(['name' => 'music', 'connectionId' => 'c-nas', 'enabled' => true,
            'transport' => 'SSH', 'pairs' => [['local' => '/mnt/user/a/', 'remote' => '/srv/a/']]]);
        $config['jobs'][] = Job::normalize(['name' => 'photos', 'connectionId' => 'c-nas', 'enabled' => true,
            'transport' => 'SSH', 'pairs' => [['local' => '/mnt/user/b/', 'remote' => '/srv/b/']]]);
        $config['jobs'][] = Job::normalize(['name' => 'other', 'connectionId' => 'c-other', 'enabled' => true,
            'transport' => 'SSH', 'pairs' => [['local' => '/mnt/user/c/', 'remote' => '/srv/c/']]]);
        Config::save($config);
    }

    public function testChangingAReferencedConnectionsTransportWarnsAndNamesTheJobs(): void
    {
        // saveCredentials never re-validates jobs (unlike deleteConnection, which
        // disables dependents), so the only thing standing between the user and
        // two silently-broken jobs is this warning. It must NAME them, and the
        // save must still succeed - the user may be mid-migration.
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-nas', 'name' => 'nas', 'host' => 'nas.local', 'username' => 'moduser',
            'authMethod' => 'KEYFILE', 'keyFilePath' => '/root/.ssh/id_ed25519',
        ]);
        $this->seedCreds($seed);
        $this->seedJobsReferencingNas();

        $_POST = $this->daemonSavePost(['id' => 'c-nas', 'password' => '']);
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertTrue($body['ok']);
        $this->assertSame([
            self::DAEMON_UNENCRYPTED_WARNING,
            'nas: Transport changed to DAEMON. These jobs still reference this Connection and will '
            . 'fail until their own Transport matches: music, photos.',
        ], $body['warnings']);
        // A warning, never a 422: the new transport really is on disk.
        $this->assertSame('DAEMON', Credentials::load()['connections'][0]['transport']);
        // And the jobs are left exactly as they were - this action never touches config.
        foreach (Config::load()['jobs'] as $j) {
            $this->assertTrue($j['enabled'], $j['name'] . ' must not have been disabled');
        }
    }

    public function testChangingTransportBackToSshWarnsWithTheNewTransportNamed(): void
    {
        // The warning is symmetric: DAEMON -> SSH breaks the daemon jobs the same
        // way, and the sprintf must name the NEW transport, not the old one.
        $this->seedDaemonConnection();
        $this->seedJobsReferencingNas();

        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => [
                'id' => 'c-nas', 'name' => 'nas', 'host' => 'nas.local', 'username' => 'moduser',
                'transport' => 'SSH', 'authMethod' => 'KEYFILE',
                'keyFilePath' => '/root/.ssh/id_ed25519', 'password' => '',
            ]],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([
            // The seeded connection carried a module secret and this POST left the
            // field blank, so the flip dropped it - and says so, rather than
            // silently handing an rsyncd secret to sshd (see the test below).
            'nas: The transport changed, so the previously stored password / module secret was '
            . 'cleared: a secret typed for one protocol is never reused for the other. Type a new '
            . 'one if this Connection needs it.',
            'nas: Transport changed to SSH. These jobs still reference this Connection and will '
            . 'fail until their own Transport matches: music, photos.',
        ], $body['warnings']);
    }

    public function testFlippingADaemonCardToSshNeverCarriesTheModuleSecretOverAsAnSshPassword(): void
    {
        // The auth <select> lives in a display:none <dd>, which does NOT suppress
        // submission - so a card that was ever PASSWORD keeps posting PASSWORD.
        // Before the fix, DAEMON -> SSH with authMethod=PASSWORD took the
        // preserve-on-blank arm and the rsyncd MODULE SECRET became the SSH
        // account password, materialised into an SSH_ASKPASS passfile and offered
        // to sshd on the next run.
        $this->seedDaemonConnection();
        $creds = Credentials::load();

        $conn = ur_normalize_connection_for_save([
            'id' => 'c-nas', 'name' => 'nas', 'host' => 'nas.local', 'username' => 'moduser',
            'transport' => 'SSH', 'authMethod' => 'PASSWORD', 'port' => '22',
            'password' => '',
        ], $creds);

        $this->assertSame('SSH', $conn['transport']);
        $this->assertSame('PASSWORD', $conn['authMethod']);
        $this->assertSame('', (string) $conn['password'], 'the module secret must not survive the flip');
        $this->assertNotSame(
            self::DAEMON_SECRET,
            Credentials::deobfuscate((string) $conn['password']),
            'the stored secret must not be recoverable as the SSH password'
        );

        // And end to end: with nothing carried over there is no password to
        // validate, so the save is refused rather than silently succeeding with a
        // borrowed secret.
        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => [
                'id' => 'c-nas', 'name' => 'nas', 'host' => 'nas.local', 'username' => 'moduser',
                'transport' => 'SSH', 'authMethod' => 'PASSWORD', 'port' => '22',
                'password' => '',
            ]],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(422, $code, json_encode($body));
        $this->assertSame(
            ['Connection "nas": Password-based connections require a password.'],
            $body['errors']
        );
        $this->assertSame(
            self::DAEMON_SECRET,
            Credentials::deobfuscate((string) Credentials::findConnection(Credentials::load(), 'c-nas')['password']),
            'a refused save must leave the stored record untouched'
        );
    }

    public function testFlippingAnSshPasswordCardToDaemonNeverCarriesTheAccountPasswordOverAsTheModuleSecret(): void
    {
        // The symmetric direction: an SSH account password must not be handed to
        // an unencrypted rsyncd module as its secret.
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-nas', 'name' => 'nas', 'host' => 'nas.local', 'username' => 'moduser',
            'authMethod' => 'PASSWORD', 'password' => Credentials::obfuscate('ssh-account-pw'),
        ]);
        $this->seedCreds($seed);

        $_POST = $this->daemonSavePost(['id' => 'c-nas', 'password' => '']);
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $conn = Credentials::findConnection(Credentials::load(), 'c-nas');
        $this->assertSame('DAEMON', $conn['transport']);
        $this->assertSame('', (string) $conn['password'], 'the SSH password must not become a module secret');
        $this->assertContains(
            'nas: The transport changed, so the previously stored password / module secret was '
            . 'cleared: a secret typed for one protocol is never reused for the other. Type a new '
            . 'one if this Connection needs it.',
            $body['warnings']
        );
    }

    public function testATypedSecretStillWinsOnATransportFlip(): void
    {
        // Clearing on a flip must only drop the PRESERVED value; a secret the user
        // typed in the same submission is for the new transport and must stick.
        $this->seedDaemonConnection();

        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => [
                'id' => 'c-nas', 'name' => 'nas', 'host' => 'nas.local', 'username' => 'moduser',
                'transport' => 'SSH', 'authMethod' => 'PASSWORD', 'port' => '22',
                'password' => 'a-brand-new-ssh-password',
            ]],
        ];
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code, json_encode($body));

        $conn = Credentials::findConnection(Credentials::load(), 'c-nas');
        $this->assertSame(
            'a-brand-new-ssh-password',
            Credentials::deobfuscate((string) $conn['password'])
        );
        // Nothing was dropped, so the "was cleared" warning must stay silent.
        $this->assertSame([], array_values(array_filter(
            $body['warnings'],
            static fn(string $w): bool => strpos($w, 'was cleared') !== false
        )));
    }

    public function testADaemonSecretCanBeRemovedByFlippingAwayAndBack(): void
    {
        // There is deliberately no "clear secret" control (a second password input
        // would make the SSH password unchangeable - see the frozen spec), so this
        // two-save detour is the documented escape route from a stored secret to
        // an anonymous module. Pin it: it is the only one.
        $this->seedDaemonConnection();

        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [0 => [
                'id' => 'c-nas', 'name' => 'nas', 'host' => 'nas.local', 'username' => 'moduser',
                'transport' => 'SSH', 'authMethod' => 'KEYFILE', 'port' => '22',
                'keyFilePath' => '/root/.ssh/id_ed25519', 'password' => '',
            ]],
        ];
        [, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code);

        $_POST = $this->daemonSavePost(['id' => 'c-nas', 'password' => '']);
        [, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code);

        $conn = Credentials::findConnection(Credentials::load(), 'c-nas');
        $this->assertSame('DAEMON', $conn['transport']);
        $this->assertSame(873, (int) $conn['port']);
        $this->assertSame('', (string) $conn['password'], 'the module is anonymous again');
    }

    public function testAnIdCarryingPostThatOmitsPortKeepsACustomDaemonPort(): void
    {
        // '' means "use the transport default" to mergeConnection, so an absent
        // port used to reset a deliberate 8730 to 873 - unlike the transport
        // field, where absent already meant "unchanged".
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-nas', 'name' => 'nas', 'host' => 'nas.local', 'username' => 'moduser',
            'transport' => 'DAEMON', 'port' => 8730,
        ]);
        $this->seedCreds($seed);

        $_POST = $this->daemonSavePost(['id' => 'c-nas', 'password' => '']);
        unset($_POST['connections'][0]['port']);
        [, $code] = $this->runCapture(fn() => ur_action_save_credentials());
        $this->assertSame(200, $code);

        $conn = Credentials::findConnection(Credentials::load(), 'c-nas');
        $this->assertSame(8730, (int) $conn['port']);
    }

    public function testTransportChangeWithNoDependentJobsWarnsOnlyAboutEncryption(): void
    {
        $seed = Credentials::defaults();
        $seed['connections'][] = Credentials::mergeConnection([
            'id' => 'c-nas', 'name' => 'nas', 'host' => 'nas.local', 'username' => 'moduser',
            'authMethod' => 'KEYFILE', 'keyFilePath' => '/root/.ssh/id_ed25519',
        ]);
        $this->seedCreds($seed);
        Config::save(Config::defaults()); // no jobs at all

        $_POST = $this->daemonSavePost(['id' => 'c-nas', 'password' => '']);
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([self::DAEMON_UNENCRYPTED_WARNING], $body['warnings']);
    }

    public function testResavingADaemonConnectionUnchangedDoesNotWarnAboutJobs(): void
    {
        // The transport-change warning must fire on a CHANGE, not on every save -
        // otherwise the real signal drowns in noise on every unrelated edit.
        $this->seedDaemonConnection();
        $this->seedJobsReferencingNas();

        $_POST = $this->daemonSavePost(['id' => 'c-nas', 'password' => '']);
        [$body, $code] = $this->runCapture(fn() => ur_action_save_credentials());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([self::DAEMON_UNENCRYPTED_WARNING], $body['warnings']);
    }

    // --- testConnection: the daemon module-listing probe --------------------

    public function testTestConnectionDaemonListsModulesAndSaysItDoesNotVerifyTheSecret(): void
    {
        // The listing is answered by send_listing() BEFORE rsync_module() ever
        // reaches auth_server() (clientserver.c:1420-1424), so a green result
        // here proves reachability and nothing else. The message must say so -
        // an "OK" that implies the secret was checked is worse than no test.
        $this->seedDaemonConnection();
        $seen = &$this->fakeDaemonProbe(
            [0, "rsync_bkp      \tBackups\nphotos         \tPhotos\n@RSYNCD: EXIT\n"]
        );

        $_POST = ['action' => 'testConnection', 'csrf_token' => 'test-token', 'id' => 'c-nas'];
        [$body, $code] = $this->runCapture(fn() => ur_action_test_connection());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([
            'ok'      => true,
            'reason'  => 'ok',
            'message' => 'Connected to the rsync daemon and listed 2 module(s): rsync_bkp, photos.'
                . ' NOTE: a module listing is answered BEFORE authentication, so this does NOT verify'
                . ' the username or the module secret. Run a dry-run to test those.',
            'modules' => ['rsync_bkp', 'photos'],
        ], $body);

        // The handler hands the probe the MERGED connection, and no secret: a
        // --password-file here would never be read (the listing is pre-auth).
        $this->assertSame([
            Rsync::rsyncPath(),
            '--contimeout=' . Rsync::DAEMON_PROBE_TIMEOUT,
            '--timeout=' . Rsync::DAEMON_PROBE_TIMEOUT,
            '--port=873',
            '--',
            'moduser@nas.local::',
        ], $seen['argv']);
    }

    public function testTestConnectionDaemonWithNoPublicModulesStillSaysItDoesNotVerifyTheSecret(): void
    {
        $this->seedDaemonConnection();
        $this->fakeDaemonProbe([0, "@RSYNCD: 31.0\n@RSYNCD: EXIT\n"]);

        $_POST = ['action' => 'testConnection', 'csrf_token' => 'test-token', 'id' => 'c-nas'];
        [$body, $code] = $this->runCapture(fn() => ur_action_test_connection());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([
            'ok'      => true,
            'reason'  => 'ok',
            'message' => 'Connected to the rsync daemon, but it listed no public modules'
                . ' (a module can be hidden with "list = no").'
                . ' NOTE: a module listing is answered BEFORE authentication, so this does NOT verify'
                . ' the username or the module secret. Run a dry-run to test those.',
            'modules' => [],
        ], $body);
    }

    public function testTestConnectionDaemonProbeFailureIsStillHttp200WithAReason(): void
    {
        // 200 regardless of the probe outcome: the REQUEST succeeded, the body
        // carries ok:false plus the distinct reason the client renders.
        $this->seedDaemonConnection();
        $this->fakeDaemonProbe([10, "rsync: failed to connect to nas.local: Connection refused (61)\n"]);

        $_POST = ['action' => 'testConnection', 'csrf_token' => 'test-token', 'id' => 'c-nas'];
        [$body, $code] = $this->runCapture(fn() => ur_action_test_connection());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([
            'ok'      => false,
            'reason'  => 'unreachable',
            'message' => 'Could not reach the rsync daemon. Check the host, the port and the network.',
            'modules' => [],
        ], $body);
    }

    public function testTestConnectionProbesTheTransportDefaultPortForALegacyRecordWithoutOne(): void
    {
        // credentials.json is hand-editable on /boot, so a DAEMON record can
        // arrive with no port key at all. The handler merges before probing, so
        // the argv must carry 873 - NOT 22, which would speak rsyncd at sshd.
        $seed = Credentials::defaults();
        $seed['connections'][] = [
            'id' => 'c-nas', 'name' => 'nas', 'transport' => 'DAEMON',
            'host' => 'nas.local', 'username' => 'moduser',
        ];
        $this->seedCreds($seed);
        $seen = &$this->fakeDaemonProbe([0, "rsync_bkp\tBackups\n"]);

        $_POST = ['action' => 'testConnection', 'csrf_token' => 'test-token', 'id' => 'c-nas'];
        [, $code] = $this->runCapture(fn() => ur_action_test_connection());

        $this->assertSame(200, $code);
        $this->assertContains('--port=873', $seen['argv']);
        $this->assertNotContains('--port=22', $seen['argv']);
    }

    public function testTestConnectionSshBranchAlwaysCarriesAnEmptyModulesList(): void
    {
        // One response shape for both transports so the client has one renderer.
        // A host-less SSH connection short-circuits inside Ssh::testConnection
        // before any ssh is spawned, which is what makes this testable here.
        $seed = Credentials::defaults();
        $seed['connections'][] = ['id' => 'c-ssh', 'name' => 'box', 'host' => '', 'username' => 'u'];
        $this->seedCreds($seed);

        $_POST = ['action' => 'testConnection', 'csrf_token' => 'test-token', 'id' => 'c-ssh'];
        [$body, $code] = $this->runCapture(fn() => ur_action_test_connection());

        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame([
            'ok'      => false,
            'reason'  => 'config',
            'message' => 'Host and username are required to test a connection.',
            'modules' => [],
        ], $body);
    }

    /**
     * The daemon branch must raise this request's PHP execution limit above the
     * probe's own hard deadline, exactly as ur_action_discover_host_key does -
     * otherwise max_execution_time can kill the worker mid-probe and the client
     * gets an HTML fatal instead of JSON. UR_HANDLER_TESTING (set in
     * setUpBeforeClass, and impossible to un-define) deliberately suppresses the
     * call, so this asserts the WIRING - the bump exists, is keyed to the probe
     * constant, and precedes the probe - rather than the runtime effect.
     */
    public function testTestConnectionDaemonBumpsTheExecutionTimeLimitBeforeProbing(): void
    {
        $rf   = new ReflectionFunction('ur_action_test_connection');
        $src  = implode('', array_slice(
            (array) file((string) $rf->getFileName()),
            $rf->getStartLine() - 1,
            $rf->getEndLine() - $rf->getStartLine() + 1
        ));

        $bump  = strpos($src, '@set_time_limit(Rsync::DAEMON_PROBE_TIMEOUT + 10);');
        $probe = strpos($src, 'Rsync::listDaemonModules(');
        $this->assertNotFalse($bump, 'the daemon branch must bump set_time_limit off the probe constant');
        $this->assertNotFalse($probe, 'the daemon branch must call Rsync::listDaemonModules');
        $this->assertLessThan($probe, $bump, 'the time-limit bump must precede the probe');
        $this->assertStringContainsString("!defined('UR_HANDLER_TESTING')", $src);
    }

    // --- POST + urlencoded ---------------------------------------------------

    public function testTestConnectionRequiresPost(): void
    {
        $_GET = ['action' => 'testConnection', 'id' => 'c-nas'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        [$body, $code] = $this->runCapture(fn() => ur_handle_request());
        $this->assertSame(405, $code);
        $this->assertSame('testConnection requires POST.', $body['error']);
    }

    public function testDaemonConnectionSavesFromAUrlencodedBody(): void
    {
        // Every client POST goes out as URLSearchParams, never FormData: a
        // multipart body stalls php-fpm on the live box. This drives the action
        // from a real application/x-www-form-urlencoded body to prove the daemon
        // card's nested names need nothing else.
        $raw = http_build_query($this->daemonSavePost());
        $this->assertStringContainsString('connections%5B0%5D%5Btransport%5D=DAEMON', $raw);

        $_POST = [];
        parse_str($raw, $_POST);
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        [$body, $code] = $this->runCapture(fn() => ur_handle_request());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame('DAEMON', Credentials::load()['connections'][0]['transport']);
    }

    public function testTestConnectionDaemonRunsFromAUrlencodedBody(): void
    {
        $this->seedDaemonConnection();
        $seen = &$this->fakeDaemonProbe([0, "rsync_bkp\tBackups\n"]);

        $_POST = [];
        parse_str(http_build_query([
            'action' => 'testConnection', 'csrf_token' => 'test-token', 'id' => 'c-nas',
        ]), $_POST);
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        [$body, $code] = $this->runCapture(fn() => ur_handle_request());
        $this->assertSame(200, $code, json_encode($body));
        $this->assertSame(1, $seen['calls']);
        $this->assertSame(['rsync_bkp'], $body['modules']);
    }

    // --- CSRF on every changed POST action ----------------------------------

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function daemonPostActionProvider(): array
    {
        return [
            'saveCredentials'  => [['action' => 'saveCredentials', 'connections_present' => '1']],
            'testConnection'   => [['action' => 'testConnection', 'id' => 'c-nas']],
            'deleteConnection' => [['action' => 'deleteConnection', 'id' => 'c-nas']],
        ];
    }

    /**
     * @param array<string,mixed> $post
     */
    #[DataProvider('daemonPostActionProvider')]
    public function testCsrfFailureRejectsEveryDaemonPostActionWithoutSideEffects(array $post): void
    {
        $this->seedDaemonConnection();
        $before = (string) file_get_contents(Credentials::path());

        $_POST = $post + ['csrf_token' => 'wrong'];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        [$body, $code] = $this->runCapture(fn() => ur_handle_request());
        $this->assertSame(403, $code, json_encode($body));
        $this->assertStringContainsString('CSRF', $body['error']);
        // Byte-identical on disk: the connection, its transport and its secret
        // all survive a rejected request.
        $this->assertSame($before, file_get_contents(Credentials::path()));
    }

    public function testCsrfFailureOnTestConnectionNeverRunsTheDaemonProbe(): void
    {
        // The probe spawns a process and talks to the network, so it must sit
        // strictly behind the CSRF gate, not merely return a rejected body.
        $this->seedDaemonConnection();
        $seen = &$this->fakeDaemonProbe([0, "rsync_bkp\tBackups\n"]);

        $_POST = ['action' => 'testConnection', 'csrf_token' => 'wrong', 'id' => 'c-nas'];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        [, $code] = $this->runCapture(fn() => ur_handle_request());
        $this->assertSame(403, $code);
        $this->assertSame(0, $seen['calls'], 'the daemon probe ran despite a CSRF failure');
        $this->assertNull($seen['argv']);
    }

    public function testCsrfFailureOnSaveCredentialsNeverCreatesTheDaemonConnection(): void
    {
        $_POST = $this->daemonSavePost();
        $_POST['csrf_token'] = 'wrong';
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        [$body, $code] = $this->runCapture(fn() => ur_handle_request());
        $this->assertSame(403, $code);
        $this->assertStringNotContainsString(self::DAEMON_SECRET, (string) json_encode($body));
        $this->assertFalse(is_file(Credentials::path()));
    }
}

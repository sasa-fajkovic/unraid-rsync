<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HandlerEmailUsernameTest extends TestCase
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
        TestSeams::reset();
        $_POST = $_GET = $_REQUEST = [];
        $GLOBALS['ur_last_response_code'] = 200;
        $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        foreach ([Credentials::path(), Credentials::path() . '.last-good', Config::path()] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    protected function tearDown(): void
    {
        TestSeams::reset();
    }

    public static function usernames(): array
    {
        return [
            'email' => ['backup@example.com', 200],
            'plus alias' => ['backup+nas@example.com', 200],
            'path separator' => ['backup/evil@example.com', 422],
            'host separator' => ['backup:evil@example.com', 422],
            'shell syntax' => ['backup$(id)@example.com', 422],
        ];
    }

    #[DataProvider('usernames')]
    public function testSaveConnectionAcceptsEmailButRejectsUnsafeUsername(string $user, int $expectedCode): void
    {
        $_POST = [
            'action' => 'saveCredentials', 'csrf_token' => 'test-token',
            'connections_present' => '1',
            'connections' => [[
                'id' => '', 'name' => 'Cloud Europe', 'host' => 'ersync.pcloud.com', 'port' => '22',
                'username' => $user, 'authMethod' => 'PASSWORD', 'password' => 'test-secret',
                'strictHostKey' => 'accept-new', 'connectTimeout' => '10',
            ]],
        ];
        ob_start();
        try {
            ur_action_save_credentials();
            $response = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $body = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($expectedCode, $GLOBALS['ur_last_response_code'], $response);
        $this->assertSame($expectedCode === 200, $body['ok']);
        $this->assertStringNotContainsString('test-secret', $response);
        $connections = Credentials::load()['connections'];
        if ($expectedCode === 200) {
            $this->assertCount(1, $connections);
            $this->assertSame($user, $connections[0]['username']);
            $this->assertSame('ersync.pcloud.com', $connections[0]['host']);
            $this->assertSame(22, $connections[0]['port']);
            $this->assertSame('test-secret', Credentials::deobfuscate($connections[0]['password']));
        } else {
            $this->assertSame([], $connections);
            $this->assertStringContainsString(Credentials::SSH_USER_RULE, $body['error']);
        }
    }
}

<?php

use PHPUnit\Framework\TestCase;

/**
 * A KeyTools double whose discoverHostKey() returns a PROGRAMMED result, so the
 * handler's discoverHostKey action can be exercised - including the wall-clock
 * timeout -> 504 mapping - without a live ssh-keyscan binary. The handler resolves
 * this class via the UR_KEYTOOLS_CLASS constant (see ur_keytools_class()).
 */
final class StubDiscoverKeyTools extends KeyTools
{
    /** @var array{ok:bool,error?:string,timedOut?:bool,hostKey?:string} */
    public static $next = ['ok' => true, 'hostKey' => "h ssh-ed25519 AAAA\n"];
    /** @var array<int,array{0:string,1:int,2:int}> recorded calls */
    public static $calls = [];

    public static function reset(): void
    {
        self::$next  = ['ok' => true, 'hostKey' => "h ssh-ed25519 AAAA\n"];
        self::$calls = [];
    }

    public static function discoverHostKey(string $host, int $port = 22, int $timeout = 10): array
    {
        self::$calls[] = [$host, $port, $timeout];
        return self::$next;
    }
}

/**
 * Tests for the Phase 3 discoverHostKey action in handler.php: the JSON envelope
 * on success, on a validation failure (422), and - the focus of this change - on
 * a wall-clock timeout (504 with a clear message). The discovery itself is stubbed
 * via UR_KEYTOOLS_CLASS so no ssh-keyscan is needed and the test never hangs.
 */
final class HandlerDiscoverHostKeyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('UR_HANDLER_TESTING')) {
            define('UR_HANDLER_TESTING', true);
        }
        // Route the handler's host-key discovery to the stub. Read at call time
        // by ur_keytools_class(), so defining it here (before any action call) is
        // sufficient even though handler.php may already be loaded.
        if (!defined('UR_KEYTOOLS_CLASS')) {
            define('UR_KEYTOOLS_CLASS', StubDiscoverKeyTools::class);
        }
        require_once __DIR__ . '/../source/include/handler.php';
    }

    protected function setUp(): void
    {
        $_POST = [];
        $_GET  = [];
        $GLOBALS['ur_last_response_code'] = 200;
        $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        StubDiscoverKeyTools::reset();
    }

    /** @return array{0:mixed,1:int} [decoded JSON body, intended HTTP status] */
    private function runCapture(callable $fn): array
    {
        ob_start();
        $fn();
        $out = ob_get_clean();
        return [json_decode($out, true), (int) ($GLOBALS['ur_last_response_code'] ?? 200)];
    }

    public function testDiscoverHostKeyRoutesToConfiguredClass(): void
    {
        $this->assertSame(StubDiscoverKeyTools::class, ur_keytools_class());
    }

    public function testDiscoverSuccessReturnsHostKey(): void
    {
        StubDiscoverKeyTools::$next = ['ok' => true, 'hostKey' => "h.example ssh-ed25519 AAAAkey\n"];
        $_POST = ['host' => 'h.example', 'port' => '22'];
        [$body, $code] = $this->runCapture('ur_action_discover_host_key');

        $this->assertSame(200, $code);
        $this->assertIsArray($body);
        $this->assertTrue($body['ok']);
        $this->assertStringContainsString('ssh-ed25519 AAAAkey', $body['hostKey']);
    }

    public function testDiscoverPassesRequestedTimeoutThrough(): void
    {
        $_POST = ['host' => 'h.example', 'port' => '2222', 'timeout' => '30'];
        $this->runCapture('ur_action_discover_host_key');
        $this->assertSame(['h.example', 2222, 30], StubDiscoverKeyTools::$calls[0]);
    }

    public function testDiscoverTimeoutReturns504WithCleanJson(): void
    {
        StubDiscoverKeyTools::$next = [
            'ok'       => false,
            'timedOut' => true,
            'error'    => 'Host key discovery timed out after 30s — check the host/port is reachable.',
        ];
        $_POST = ['host' => 'h.example', 'port' => '22'];
        [$body, $code] = $this->runCapture('ur_action_discover_host_key');

        $this->assertSame(504, $code, 'a wall-clock timeout maps to 504');
        $this->assertIsArray($body, 'the body must be valid JSON');
        $this->assertArrayHasKey('error', $body);
        $this->assertStringContainsString('timed out', $body['error']);
        $this->assertStringContainsString('30s', $body['error']);
    }

    public function testDiscoverValidationFailureReturns422(): void
    {
        StubDiscoverKeyTools::$next = [
            'ok'    => false,
            'error' => 'No host key returned. The host may be unreachable or not running SSH.',
        ];
        $_POST = ['host' => 'h.example', 'port' => '22'];
        [$body, $code] = $this->runCapture('ur_action_discover_host_key');

        $this->assertSame(422, $code, 'a non-timeout failure stays a 422');
        $this->assertIsArray($body);
        $this->assertStringContainsString('No host key', $body['error']);
    }
}

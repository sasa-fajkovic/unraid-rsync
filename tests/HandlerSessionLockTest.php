<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;

/**
 * Regression tests for the production wedge: a slow/stuck host-key discovery
 * blocking every other POST.
 *
 * Two independent defences are covered here:
 *   1. ur_release_session_lock() releases the webGui session lock that the
 *      authenticated front controller (auto_prepend_file) holds for the whole
 *      request. Without this, one long-running POST serialises every other
 *      same-session POST inside session_start().
 *   2. The dispatcher releases that lock right after CSRF verification, BEFORE
 *      running any (potentially slow) action - so the discover's ~30s ssh-keyscan
 *      can never hold the lock for other requests.
 *
 * The discovery subprocess teardown (proc_terminate -> SIGKILL, never blocking
 * proc_close on an unkillable child) is exercised in KeyToolsTest.
 */
final class HandlerSessionLockTest extends TestCase
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
        $GLOBALS['ur_last_response_code'] = 200;
        $GLOBALS['var'] = ['csrf_token' => 'test-token'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        // Ensure no session is left active from a prior test.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        unset($_SERVER['REQUEST_METHOD']);
    }

    /** @return array{0:mixed,1:int} [decoded JSON body, intended HTTP status] */
    private function runCapture(callable $fn): array
    {
        ob_start();
        $fn();
        $out = ob_get_clean();
        return [json_decode($out, true), (int) ($GLOBALS['ur_last_response_code'] ?? 200)];
    }

    // --- ur_release_session_lock --------------------------------------------

    public function testReleaseSessionLockIsNoOpWithoutActiveSession(): void
    {
        // No session active: the call must be a harmless no-op (and emit no
        // warning under failOnWarning).
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        ur_release_session_lock();
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    /**
     * Starting a real session needs a clean SAPI state (no headers sent yet),
     * which the PHPUnit CLI printer has already broken in the shared process -
     * so this runs in an isolated subprocess.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReleaseSessionLockClosesAnActiveSession(): void
    {
        if (!function_exists('session_start')) {
            $this->markTestSkipped('sessions unavailable');
        }
        // Use a file-backed session in a temp dir (mirrors the webGui's default
        // files handler that holds the per-request lock we need to release).
        $dir = sys_get_temp_dir() . '/ur-sesstest-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        ini_set('session.save_path', $dir);
        @session_start();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'precondition: session active');

        ur_release_session_lock();

        $this->assertNotSame(
            PHP_SESSION_ACTIVE,
            session_status(),
            'an active session must be closed (releasing its lock)'
        );

        // cleanup
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    // --- dispatcher releases the lock before running the action --------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDispatchReleasesSessionLockBeforeAction(): void
    {
        if (!function_exists('session_start')) {
            $this->markTestSkipped('sessions unavailable');
        }
        $dir = sys_get_temp_dir() . '/ur-sesstest-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        ini_set('session.save_path', $dir);
        @session_start();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());

        // A CSRF-valid POST to a CSRF-protected action. We use deleteKey because
        // it needs no live tooling and returns a clean JSON envelope; the point
        // is that by the time it runs, the dispatcher has already closed the
        // session, so the action cannot hold the lock.
        $_POST = [
            'action'     => 'deleteKey',
            'csrf_token' => 'test-token',
            'id'         => 'k-does-not-exist',
        ];

        // Capture so the action's body doesn't leak; we assert on session state.
        ob_start();
        ur_dispatch();
        ob_get_clean();

        $this->assertNotSame(
            PHP_SESSION_ACTIVE,
            session_status(),
            'the dispatcher must release the session lock after CSRF, before the action returns'
        );

        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDispatchDoesNotReleaseLockOnCsrfFailure(): void
    {
        if (!function_exists('session_start')) {
            $this->markTestSkipped('sessions unavailable');
        }
        $dir = sys_get_temp_dir() . '/ur-sesstest-' . getmypid() . '-' . bin2hex(random_bytes(4));
        @mkdir($dir, 0700, true);
        ini_set('session.save_path', $dir);
        @session_start();
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());

        // A POST with a BAD csrf token must 403 - and the lock-release happens
        // only on the success path (after ur_check_csrf passes). On a 403 the
        // request ends immediately anyway, so the session is still active here.
        $_POST = [
            'action'     => 'deleteKey',
            'csrf_token' => 'WRONG',
            'id'         => 'k-x',
        ];

        ob_start();
        ur_dispatch();
        ob_get_clean();

        $this->assertSame(
            PHP_SESSION_ACTIVE,
            session_status(),
            'a CSRF-rejected POST must not reach the release path'
        );

        @session_write_close();
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}

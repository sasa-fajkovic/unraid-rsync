<?php
/**
 * PHPUnit bootstrap for the Unraid Rsync plugin.
 *
 * The include/ classes are designed to be I/O-free except for Config's
 * read/write, whose base directory is overridable via the UR_CONFIG_BASE
 * constant. We point it at a unique per-process temp directory BEFORE requiring
 * Config.php, so the tests never touch /boot and never collide.
 *
 * We also provide a couple of minimal stubs for webGui functions that the
 * testable code might reference (none are strictly required by the Phase 2
 * include/ logic, but having them here keeps the seam explicit and lets future
 * tests exercise handler-adjacent code without a live server).
 */

error_reporting(E_ALL);

// Per-process temp config base; cleaned at shutdown.
$urTestBase = sys_get_temp_dir() . '/unraid-rsync-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
@mkdir($urTestBase, 0777, true);
define('UR_CONFIG_BASE', $urTestBase);

register_shutdown_function(static function () use ($urTestBase) {
    // Recursively remove the temp config base.
    if (is_dir($urTestBase)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($urTestBase, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($urTestBase);
    }
});

// --- minimal webGui stubs --------------------------------------------------
// _() translation passthrough (the real webGui defines this).
if (!function_exists('_')) {
    function _(string $s): string
    {
        return $s;
    }
}

// A separate tmpfs RUNTIME base (state + logs) so RunState/Logger never write to
// /tmp/unraid.rsync during tests. Defined before requiring those classes.
$urRuntimeBase = sys_get_temp_dir() . '/unraid-rsync-rt-' . getmypid() . '-' . bin2hex(random_bytes(4));
@mkdir($urRuntimeBase, 0777, true);
define('UR_RUNTIME_BASE', $urRuntimeBase);

register_shutdown_function(static function () use ($urRuntimeBase) {
    if (is_dir($urRuntimeBase)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($urRuntimeBase, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($urRuntimeBase);
    }
});

// --- the code under test ---------------------------------------------------
require_once __DIR__ . '/../source/include/Config.php';
require_once __DIR__ . '/../source/include/Job.php';
require_once __DIR__ . '/../source/include/Credentials.php';
require_once __DIR__ . '/../source/include/KeyTools.php';
require_once __DIR__ . '/../source/include/Ssh.php';
require_once __DIR__ . '/../source/include/Rsync.php';
require_once __DIR__ . '/../source/include/RunState.php';
require_once __DIR__ . '/../source/include/Logger.php';
require_once __DIR__ . '/../source/include/Runner.php';

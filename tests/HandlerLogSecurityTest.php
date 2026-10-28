<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HandlerLogSecurityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('UR_HANDLER_TESTING')) {
            define('UR_HANDLER_TESTING', true);
        }
        require_once __DIR__ . '/../source/include/handler.php';
    }

    public function testUnsafeLogDirectoryFallsBackToRamWithWarning(): void
    {
        $root = sys_get_temp_dir() . '/ur-log-settings-' . bin2hex(random_bytes(6));
        mkdir($root, 0777);
        chmod($root, 0777);
        $previous = Config::$mntRoot;
        Config::$mntRoot = $root;
        try {
            $config = Config::defaults();
            $errors = [];
            $warnings = [];
            ur_apply_global_settings($config, ['logDir' => $root . '/pool/logs'], $errors, $warnings);
            $this->assertSame('', $config['global']['logDir']);
            $this->assertSame([], $errors);
            $this->assertStringContainsString('Logs will stay in RAM', implode(' ', $warnings));
            $this->assertDirectoryDoesNotExist($root . '/pool');
            $this->assertSame(0777, fileperms($root) & 0777);
        } finally {
            Config::$mntRoot = $previous;
            rmdir($root);
        }
    }

    public function testTrustedLogDirectoryIsSavedWithoutCreatingIt(): void
    {
        $root = sys_get_temp_dir() . '/ur-log-settings-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $previous = Config::$mntRoot;
        Config::$mntRoot = $root;
        try {
            $config = Config::defaults();
            $errors = [];
            $warnings = [];
            $path = Config::sanitizeLogDir($root . '/pool/logs');
            ur_apply_global_settings($config, ['logDir' => $path], $errors, $warnings);
            $this->assertSame($path, $config['global']['logDir']);
            $this->assertSame([], $errors);
            $this->assertSame([], $warnings);
            $this->assertDirectoryDoesNotExist($root . '/pool');
        } finally {
            Config::$mntRoot = $previous;
            rmdir($root);
        }
    }
    public function testMissingRunReadDoesNotReturnAnUnverifiedPathOrCreateDirectories(): void
    {
        $root = sys_get_temp_dir() . '/ur-log-read-' . bin2hex(random_bytes(6));
        $previousBase = Logger::$baseOverride;
        $previousLogs = Logger::$logsDirOverride;
        $previousGet = $_GET;
        Logger::$baseOverride = $root;
        Logger::$logsDirOverride = null;
        $_GET = ['id' => 'audit', 'run' => 'run-20261028T000000Z.log'];
        try {
            $this->assertSame(['jobId' => 'audit', 'path' => ''], ur_resolve_run_log());
            $this->assertDirectoryDoesNotExist($root);
        } finally {
            Logger::$baseOverride = $previousBase;
            Logger::$logsDirOverride = $previousLogs;
            $_GET = $previousGet;
        }
    }
}

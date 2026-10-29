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

    public function testSharedNewLogDirectoryIsSavedWithAdvice(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/ur-log-settings-' . bin2hex(random_bytes(6));
        mkdir($root . '/pool/logs', 0700, true);
        chmod($root . '/pool/logs', 0777);
        $previous = Config::$mntRoot;
        Config::$mntRoot = $root;
        try {
            $config = Config::defaults();
            $config['global']['logDir'] = $root . '/pool/existing';
            $errors = [];
            $warnings = [];
            ur_apply_global_settings($config, ['logDir' => $root . '/pool/logs'], $errors, $warnings);
            $this->assertSame($root . '/pool/logs', $config['global']['logDir']);
            $this->assertSame([], $errors);
            $this->assertStringContainsString('other users', implode(' ', $warnings));
            $this->assertSame(0777, fileperms($root . '/pool/logs') & 0777);
        } finally {
            Config::$mntRoot = $previous;
            rmdir($root . '/pool/logs');
            rmdir($root . '/pool');
            rmdir($root);
        }
    }

    public function testNonDirectoryLogPathKeepsExistingSettingWithWarning(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/ur-log-settings-' . bin2hex(random_bytes(6));
        mkdir($root . '/pool', 0700, true);
        file_put_contents($root . '/pool/file', 'x');
        $previous = Config::$mntRoot;
        Config::$mntRoot = $root;
        try {
            $config = Config::defaults();
            $config['global']['logDir'] = $root . '/pool/existing';
            $errors = [];
            $warnings = [];
            ur_apply_global_settings($config, ['logDir' => $root . '/pool/file/logs'], $errors, $warnings);
            $this->assertSame($root . '/pool/existing', $config['global']['logDir']);
            $this->assertStringContainsString('not changed', implode(' ', $warnings));
            $this->assertStringContainsString($root . '/pool/file', implode(' ', $warnings));
        } finally {
            Config::$mntRoot = $previous;
            unlink($root . '/pool/file');
            rmdir($root . '/pool');
            rmdir($root);
        }
    }

    public function testTrustedLogDirectoryIsSavedWithoutCreatingIt(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/ur-log-settings-' . bin2hex(random_bytes(6));
        mkdir($root, 0777);
        chmod($root, 0777);
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
    public function testExistingPrivateLogsUnderWritableShareRemainSelected(): void
    {
        $root = realpath(sys_get_temp_dir()) . '/ur-log-settings-' . bin2hex(random_bytes(6));
        mkdir($root . '/share/logs', 0700, true);
        chmod($root . '/share', 0777);
        $previous = Config::$mntRoot;
        Config::$mntRoot = $root;
        try {
            $path = Config::sanitizeLogDir($root . '/share/logs');
            $config = Config::defaults();
            $config['global']['logDir'] = $path;
            $errors = [];
            $warnings = [];
            ur_apply_global_settings($config, ['logDir' => $path], $errors, $warnings);
            $this->assertSame($path, $config['global']['logDir']);
            $this->assertSame([], $errors);
            $this->assertSame([], $warnings);
            $this->assertSame(0777, fileperms($root . '/share') & 0777);
            $this->assertSame(0700, fileperms($root . '/share/logs') & 0777);
        } finally {
            Config::$mntRoot = $previous;
            rmdir($root . '/share/logs');
            rmdir($root . '/share');
            rmdir($root);
        }
    }

    public function testSymlinkedPathIsStoredAsItsTarget(): void
    {
        // 6.12+ exclusive shares: /mnt/user/<share> is a symlink to the pool.
        $root = realpath(sys_get_temp_dir()) . '/ur-log-settings-' . bin2hex(random_bytes(6));
        mkdir($root . '/share', 0700, true);
        mkdir($root . '/pool/target', 0700, true);
        symlink($root . '/pool/target', $root . '/share/linked');
        $previous = Config::$mntRoot;
        Config::$mntRoot = $root;
        try {
            $linked = $root . '/share/linked';
            $this->assertSame($root . '/pool/target', Config::sanitizeLogDir($linked));
            $config = Config::defaults();
            $config['global']['logDir'] = $root . '/share/existing';
            $errors = [];
            $warnings = [];
            ur_apply_global_settings($config, ['logDir' => $linked], $errors, $warnings);
            $this->assertSame($root . '/pool/target', $config['global']['logDir']);
            $this->assertSame([], $errors);
            $this->assertSame([], $warnings);
        } finally {
            Config::$mntRoot = $previous;
            unlink($root . '/share/linked');
            rmdir($root . '/share');
            rmdir($root . '/pool/target');
            rmdir($root . '/pool');
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

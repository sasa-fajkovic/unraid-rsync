<?php

declare(strict_types=1);

/**
 * Util.php - tiny stateless helpers shared across backend classes.
 */
final class Util
{
    /** The euid assertPrivateDir() expects to own a dir; null = posix_geteuid(). Test seam. */
    public static ?int $euid = null;

    /** @var array<string,true> lock paths this process holds (see withLock) */
    private static array $heldLocks = [];

    /**
     * stat() device id seam: fn(string $path): int|false, false when the path
     * does not exist. null => stat().
     *
     * @var (callable(string):(int|false))|null
     */
    public static $devIdOf = null;

    /**
     * Sanitise an id for use as a filename segment: strip anything that isn't
     * a safe filename char, and collapse an empty or pure-dots result (a
     * traversal segment) to the literal 'unknown'.
     */
    public static function safeFileId(string $id): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $id);
        if ($clean === '' || $clean === null || preg_match('/^\.+$/', $clean)) {
            return 'unknown';
        }
        return $clean;
    }

    /** First non-empty trimmed line of a (possibly multi-line) blob. */
    public static function firstLine(string $text): string
    {
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }
        return '';
    }

    /**
     * True when $path, or the first existing ancestor of it, lives on the same
     * device as "/" or "/mnt". Both are RAM on Unraid (/mnt may be its own
     * tmpfs), so a local path there means the drive meant to hold it is not
     * mounted: an empty mountpoint, or a path --mkpath would create in memory.
     */
    public static function isOnRootFs(string $path): bool
    {
        $dev  = self::$devIdOf ?? static function (string $p): int|false {
            $st = @stat($p);
            return $st === false ? false : (int) $st['dev'];
        };
        $ram = array_filter([$dev('/'), $dev('/mnt')], static fn($d) => $d !== false);
        $p   = '/' . trim($path, '/');
        while (($d = $dev($p)) === false && $p !== '/') {
            $p = dirname($p);
        }
        return $d !== false && in_array($d, $ram, true);
    }

    /**
     * Replace $path with $data through a same-dir temp file and rename. The temp
     * file is fsynced first: /boot is FAT32 with delayed writes, and a rename that
     * reaches the disk before the data leaves a present-but-empty file behind.
     *
     * @throws RuntimeException on any filesystem failure (the target is untouched).
     */
    public static function writeAtomic(string $path, string $data, int $mode): void
    {
        $dir = dirname($path);
        $tmp = @tempnam($dir, '.' . basename($path) . '.');
        if ($tmp === false) {
            throw new RuntimeException("Unable to create temp file in: $dir");
        }
        @chmod($tmp, $mode);
        $fh = @fopen($tmp, 'wb');
        $ok = $fh !== false && @fwrite($fh, $data) === strlen($data) && fflush($fh);
        if ($fh !== false) {
            // FAT32 may refuse fsync; the write still stands.
            @fsync($fh);
            fclose($fh);
        }
        if (!$ok) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write temp file: $tmp");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to atomically replace: $path");
        }
    }

    /**
     * Run $fn holding an exclusive flock on $lockPath (created if missing).
     * Re-entrant within a process: a second flock on a fresh fd of the same file
     * would wait on this process's own lock forever.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws RuntimeException when the lock file cannot be opened or locked.
     */
    public static function withLock(string $lockPath, callable $fn): mixed
    {
        if (isset(self::$heldLocks[$lockPath])) {
            return $fn();
        }
        $dir = dirname($lockPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create lock directory: $dir");
        }
        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            throw new RuntimeException("Unable to open lock file: $lockPath");
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            throw new RuntimeException("Unable to lock: $lockPath");
        }
        self::$heldLocks[$lockPath] = true;
        try {
            return $fn();
        } finally {
            unset(self::$heldLocks[$lockPath]);
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Refuse a runtime dir another local user could tamper with: a symlink, a
     * non-directory, one not owned by this process's euid, or one writable by
     * group/others. Under world-writable /tmp a local user can pre-create the
     * dir, and a root chmod 0700 leaves them its owner, free to swap its entries.
     *
     * @throws RuntimeException naming the dir and the reason.
     */
    public static function assertPrivateDir(string $dir): void
    {
        clearstatcache(true, $dir);
        if (is_link($dir) || !is_dir($dir)) {
            throw new RuntimeException("Refusing a runtime path that is not a real directory: $dir");
        }
        $euid  = self::$euid ?? (function_exists('posix_geteuid') ? posix_geteuid() : null);
        $owner = @fileowner($dir);
        if ($euid !== null && $owner !== $euid) {
            throw new RuntimeException("Refusing a runtime dir not owned by uid $euid: $dir");
        }
        $perms = @fileperms($dir);
        if ($perms === false || ($perms & 0022) !== 0) {
            throw new RuntimeException("Refusing a group- or world-writable runtime dir: $dir");
        }
    }
}

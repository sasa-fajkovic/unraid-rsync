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
     * True when $s is a bare IPv6 address, optionally with a %scope zone id
     * (fe80::1%eth0) - which FILTER_VALIDATE_IP alone refuses. PURE.
     */
    public static function isIpv6Address(string $s): bool
    {
        $addr = preg_match('/^([^%]+)%[A-Za-z0-9_.-]+$/D', $s, $m) ? $m[1] : $s;
        return filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
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

    /** writeAtomic() failure stages, as the exception code. */
    public const WRITE_TEMP   = 1;
    public const WRITE_DATA   = 2;
    public const WRITE_RENAME = 3;

    /**
     * Replace $path with $data through a same-dir temp file and rename. The temp
     * file is fsynced first: /boot is FAT32 with delayed writes, and a rename that
     * reaches the disk before the data leaves a present-but-empty file behind.
     * tmpfs callers pass $fsync = false. rename() replaces the name without
     * following a symlink planted at $path.
     *
     * @param string $what names the file in the error ("state" -> "temp state file")
     * @throws RuntimeException on any filesystem failure (the target is untouched),
     *         its code one of WRITE_TEMP / WRITE_DATA / WRITE_RENAME.
     */
    public static function writeAtomic(string $path, string $data, int $mode, bool $fsync = true, string $what = ''): void
    {
        $file = $what === '' ? 'file' : "$what file";
        $dir  = dirname($path);
        $tmp  = @tempnam($dir, '.' . basename($path) . '.');
        // tempnam() falls back to the system temp dir when $dir is not writable,
        // and a rename from there is a non-atomic cross-device copy.
        if ($tmp !== false && dirname((string) realpath($tmp)) !== realpath($dir)) {
            @unlink($tmp);
            $tmp = false;
        }
        if ($tmp === false) {
            throw new RuntimeException("Unable to create temp $file in: $dir", self::WRITE_TEMP);
        }
        @chmod($tmp, $mode);
        $fh = @fopen($tmp, 'wb');
        $ok = $fh !== false && @fwrite($fh, $data) === strlen($data) && fflush($fh);
        if ($fh !== false) {
            if ($fsync) {
                // FAT32 may refuse fsync; the write still stands.
                @fsync($fh);
            }
            fclose($fh);
        }
        if (!$ok) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write temp $file: $tmp", self::WRITE_DATA);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to atomically replace' . ($what === '' ? '' : " $file") . ": $path", self::WRITE_RENAME);
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
     * mkdir -p that will not create through a symlink: an existing ancestor
     * below the path's top-level directory (/mnt for a logDir or secretsDir)
     * that is a symlink is refused rather than followed. Only the levels it
     * creates are chmodded, never one that already existed (a share root
     * chmodded 0700 locks its users out).
     *
     * @throws RuntimeException naming the symlink, or the level it could not create.
     */
    public static function mkdirs(string $dir, int $mode): void
    {
        $dir     = '/' . trim($dir, '/');
        $missing = [];
        for ($p = $dir; !file_exists($p) && !is_link($p); $p = dirname($p)) {
            $missing[] = $p;
        }
        if ($missing === []) {
            return;
        }
        for ($a = $p; substr_count($a, '/') > 1; $a = dirname($a)) {
            if (is_link($a)) {
                throw new RuntimeException("Refusing to create $dir through the symlink $a");
            }
        }
        foreach (array_reverse($missing) as $level) {
            if (@mkdir($level, $mode)) {
                @chmod($level, $mode);
            } elseif (!is_dir($level)) {
                throw new RuntimeException("Unable to create directory: $level");
            }
        }
    }

    /** The tmpfs runtime base: the UR_RUNTIME_BASE constant, else /tmp/unraid.rsync. */
    public static function runtimeBase(): string
    {
        return rtrim(defined('UR_RUNTIME_BASE') ? (string) UR_RUNTIME_BASE : '/tmp/unraid.rsync', '/');
    }

    /**
     * ensureDirLevel(), then refuse the dir unless assertPrivateDir() accepts it.
     *
     * @throws RuntimeException naming the dir and the reason.
     */
    public static function ensurePrivateDir(string $dir, string $label = 'runtime'): void
    {
        self::ensureDirLevel($dir, $label);
        self::assertPrivateDir($dir);
    }

    /**
     * Create ONE dir level if missing (0700, non-recursive), chmodding it only
     * when made here: an existing logDir may be a share others use. A recursive
     * mkdir would follow a symlink planted under world-writable /tmp; the second
     * symlink check closes the swap between create and use.
     *
     * @param string $label names the dir in the error ("state", "log", "runtime")
     * @throws RuntimeException naming the dir and the reason.
     */
    public static function ensureDirLevel(string $dir, string $label = 'runtime'): void
    {
        if (is_link($dir)) {
            throw new RuntimeException("Refusing to use a symlinked $label dir: $dir");
        }
        if (file_exists($dir) && !is_dir($dir)) {
            throw new RuntimeException(ucfirst($label) . " path exists but is not a directory: $dir");
        }
        $made = !is_dir($dir) && @mkdir($dir, 0700);
        if (!$made && !is_dir($dir)) {
            throw new RuntimeException("Unable to create $label dir: $dir");
        }
        clearstatcache(true, $dir);
        if (is_link($dir)) {
            throw new RuntimeException("Refusing to use a symlinked $label dir: $dir");
        }
        if ($made) {
            @chmod($dir, 0700);
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

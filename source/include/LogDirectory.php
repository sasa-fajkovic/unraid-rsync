<?php

declare(strict_types=1);

require_once __DIR__ . '/Util.php';

final class LogDirectory
{
    /** @var callable():void|null */
    public static $afterEnter = null;

    /**
     * Run $callback with the cwd pinned to $dir, entered one level at a time
     * from / and never through a symlink. Levels from $privateRoot down must be
     * private (Util::assertPrivateDir); null means $dir itself, '' none.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function withDirectory(string $dir, callable $callback, bool $create = false, ?string $privateRoot = null): mixed
    {
        $dir = self::normalize($dir);
        $privateRoot = $privateRoot === '' ? null : self::normalize($privateRoot ?? $dir);
        if ($privateRoot !== null && $privateRoot !== '/' && $dir !== '/' && $dir !== $privateRoot
            && !str_starts_with($dir, $privateRoot . '/') && !str_starts_with($privateRoot, $dir . '/')) {
            throw new RuntimeException('Private log root must contain the requested directory.');
        }
        $savedPath = getcwd();
        $saved = @fopen('.', 're');
        $savedStat = $saved === false ? false : @fstat($saved);
        if ($savedPath === false || $saved === false || $savedStat === false) {
            if (is_resource($saved)) {
                fclose($saved);
            }
            throw new RuntimeException('Unable to retain the current directory.');
        }
        try {
            if (!@chdir('/')) {
                throw new RuntimeException('Unable to enter the filesystem root.');
            }
            if ($privateRoot === '/') {
                self::assertPrivate('/');
            }
            $walked = '';
            foreach (array_filter(explode('/', $dir), static fn(string $part): bool => $part !== '') as $part) {
                $walked .= '/' . $part;
                clearstatcache(true, $part);
                $before = @lstat($part);
                if ($before === false && $create) {
                    $mask = umask(0077);
                    try {
                        @mkdir($part, 0700);
                    } finally {
                        umask($mask);
                    }
                    clearstatcache(true, $part);
                    $before = @lstat($part);
                }
                if ($before === false) {
                    throw new RuntimeException("Log directory does not exist: $walked");
                }
                $type = $before['mode'] & 0170000;
                if ($type === 0120000 && ($walked === '/tmp' || $walked === '/var') && $before['uid'] === 0) {
                    $before = @stat($part);
                    $type = $before === false ? 0 : $before['mode'] & 0170000;
                }
                if ($before === false || $type !== 0040000 || !@chdir($part)) {
                    throw new RuntimeException("Refusing a non-directory or symlink in log path: $walked");
                }
                clearstatcache(true, '.');
                $entered = @stat('.');
                if ($entered === false || !self::sameDirectory($before, $entered)) {
                    throw new RuntimeException("Log directory changed while opening: $walked");
                }
                if ($privateRoot !== null
                    && ($privateRoot === '/' || $walked === $privateRoot || str_starts_with($walked, $privateRoot . '/'))) {
                    self::assertPrivate($walked);
                }
            }
            if (self::$afterEnter !== null) {
                (self::$afterEnter)();
            }
            return $callback();
        } finally {
            try {
                self::restore($savedPath, $savedStat);
            } finally {
                fclose($saved);
            }
        }
    }

    /** Util::assertPrivateDir on the cwd, naming $walked rather than "." in the error. */
    private static function assertPrivate(string $walked): void
    {
        try {
            Util::assertPrivateDir('.');
        } catch (RuntimeException $e) {
            throw new RuntimeException((string) preg_replace('/: \.$/', ': ' . $walked, $e->getMessage()), 0, $e);
        }
    }

    private static function normalize(string $dir): string
    {
        if ($dir === '' || $dir[0] !== '/' || preg_match('/[\x00-\x1f\x7f]/', $dir)) {
            throw new RuntimeException('Log directory must be an absolute path without control characters.');
        }
        $dir = rtrim($dir, '/') ?: '/';
        foreach (explode('/', substr($dir, 1)) as $part) {
            if ($part === '.' || $part === '..' || ($part === '' && $dir !== '/')) {
                throw new RuntimeException('Log directory must be a normalized absolute path.');
            }
        }
        return $dir;
    }

    /**
     * @param array<string|int,int> $a
     * @param array<string|int,int> $b
     */
    private static function sameDirectory(array $a, array $b): bool
    {
        return ($a['mode'] & 0170000) === 0040000 && ($b['mode'] & 0170000) === 0040000
            && $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
    }

    /** @param array<string|int,int> $savedStat */
    private static function restore(string $savedPath, array $savedStat): void
    {
        if (is_dir('/proc/self/fd')) {
            foreach (glob('/proc/self/fd/*') ?: [] as $entry) {
                clearstatcache(true, $entry);
                $st = @stat($entry);
                if ($st !== false && self::sameDirectory($savedStat, $st) && @chdir($entry)) {
                    clearstatcache(true, '.');
                    $restored = @stat('.');
                    if ($restored !== false && self::sameDirectory($savedStat, $restored)) {
                        return;
                    }
                }
            }
        } else {
            clearstatcache(true, $savedPath);
            $st = @stat($savedPath);
            if ($st !== false && self::sameDirectory($savedStat, $st) && @chdir($savedPath)) {
                clearstatcache(true, '.');
                $restored = @stat('.');
                if ($restored !== false && self::sameDirectory($savedStat, $restored)) {
                    return;
                }
            }
        }
        throw new RuntimeException('Unable to restore the original directory safely.');
    }
}

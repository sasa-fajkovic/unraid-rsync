<?php

declare(strict_types=1);

/**
 * Util.php - tiny stateless helpers shared across backend classes.
 */
final class Util
{
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
}

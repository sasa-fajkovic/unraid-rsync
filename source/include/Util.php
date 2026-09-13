<?php

declare(strict_types=1);

/**
 * Util.php - tiny stateless helpers shared across backend classes.
 */
final class Util
{
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
}

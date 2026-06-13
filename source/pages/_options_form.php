<?php
/**
 * _options_form.php - shared renderer for the whitelisted rsync-options control
 * set. Used both by the Global Settings tab (as global.defaultRsyncOptions,
 * which seeds new jobs) and per-job on the Jobs tab.
 *
 * It renders ONLY the curated whitelist: boolean flags as checkboxes and
 * value inputs next to the flags that take an argument. There is deliberately
 * no free-form flag string anywhere. Phase 2 only renders + persists these;
 * mapping a key to its rsync argv token is Phase 4.
 *
 * Every rendered value is escaped with htmlspecialchars (via the ur_h helper)
 * - no raw interpolation of user data into HTML.
 *
 * The field-name PREFIX lets the same markup live under different POST paths,
 * e.g.:
 *   global[defaultRsyncOptions]            (Global Settings tab)
 *   jobs[3][rsyncOptions]                  (a per-job options block)
 * so the nested names round-trip straight back into $_POST for the handler.
 */

if (!function_exists('ur_h')) {
    /** htmlspecialchars shorthand - escape a value for safe HTML output. */
    function ur_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ur_t')) {
    /**
     * Translation wrapper. On a live webGui the global _() is provided; under a
     * bare PHP lint/preview it may not be, so fall back to identity. (The .page
     * bodies still call the webGui translation function directly as _('...');
     * this wrapper is only for the shared partial, which may be previewed in
     * isolation without the webGui environment.)
     */
    function ur_t(string $s): string
    {
        return function_exists('_') ? _($s) : $s;
    }
}

if (!function_exists('ur_required_mark')) {
    /**
     * The visual "required" cue appended to a mandatory field's label: a red
     * asterisk with a screen-reader hint. Kept in one place so the marker is
     * consistent across the Jobs and Credentials forms, and so it pairs visually
     * with the HTML5 `required` attribute on the corresponding input. Output is
     * static markup (no user data), so it needs no escaping.
     */
    function ur_required_mark(): string
    {
        return ' <abbr class="ur-required" title="' . ur_h(ur_t('Required')) . '">*</abbr>';
    }
}

if (!function_exists('ur_render_rsync_options')) {
    /**
     * @param array<string,mixed> $opts   current option values (already merged
     *                                     to the full whitelist shape)
     * @param string              $prefix HTML name prefix, e.g.
     *                                     "global[defaultRsyncOptions]" or
     *                                     "jobs[0][rsyncOptions]"
     * @param string              $idBase a unique DOM-id base so multiple option
     *                                     blocks on one page don't collide
     */
    function ur_render_rsync_options(array $opts, string $prefix, string $idBase): void
    {
        // key => [label, flag] for the boolean checkboxes, in display order.
        $bools = [
            'archive'        => ['Archive', '-a'],
            'compress'       => ['Compress', '-z'],
            'humanReadable'  => ['Human-readable', '-h'],
            'times'          => ['Preserve times', '-t'],
            'perms'          => ['Preserve permissions', '-p'],
            'xattrs'         => ['Preserve extended attributes', '-X'],
            'acls'           => ['Preserve ACLs', '-A'],
            'symlinks'       => ['Copy symlinks as symlinks', '-l'],
            'hardlinks'      => ['Preserve hard links', '-H'],
            'sparse'         => ['Handle sparse files efficiently', '-S'],
            'numericIds'     => ['Use numeric user/group IDs', '--numeric-ids'],
            'partial'        => ['Keep partially transferred files', '--partial'],
            'inplace'        => ['Update files in place', '--inplace'],
            'checksum'       => ['Compare by checksum', '-c'],
            'update'         => ['Skip files newer on receiver', '-u'],
            'wholeFile'      => ['Copy whole files (no delta)', '-W'],
            'sizeOnly'       => ['Skip files matching size', '--size-only'],
            'ignoreExisting' => ['Skip files that already exist', '--ignore-existing'],
        ];
        // Destructive booleans rendered separately with a warning.
        $destructive = [
            'delete'         => ['Delete extraneous files on destination', '--delete'],
            'deleteExcluded' => ['Also delete excluded files on destination', '--delete-excluded'],
        ];
        // key => [label, flag] for scalar value inputs.
        $scalars = [
            'maxDelete'     => ['Max delete', '--max-delete='],
            'bwlimit'       => ['Bandwidth limit (KB/s)', '--bwlimit='],
            'timeout'       => ['I/O timeout (s)', '--timeout='],
            'contimeout'    => ['Connect timeout (s)', '--contimeout='],
            'maxSize'       => ['Max file size', '--max-size='],
            'minSize'       => ['Min file size', '--min-size='],
            'chmod'         => ['Chmod', '--chmod='],
            'tempDir'       => ['Temp dir', '--temp-dir='],
            'backupDir'     => ['Backup dir (enables --backup)', '--backup-dir='],
            'compressLevel' => ['Compress level', '--compress-level='],
            'modifyWindow'  => ['Modify window (s)', '--modify-window='],
        ];

        echo '<div class="ur-rsync-options">';

        // --- boolean flags -------------------------------------------------
        echo '<dl>';
        echo '<dt>' . ur_h(ur_t('rsync flags')) . ':</dt>';
        echo '<dd>';
        foreach ($bools as $key => [$label, $flag]) {
            $name    = $prefix . '[' . $key . ']';
            $id      = $idBase . '_' . $key;
            $checked = !empty($opts[$key]) ? ' checked' : '';
            // Hidden "0" before the checkbox so an unchecked box still submits
            // a (falsey) value at this name, keeping the stored shape stable.
            echo '<label class="ur-checkbox" for="' . ur_h($id) . '">';
            echo '<input type="hidden" name="' . ur_h($name) . '" value="0">';
            echo '<input type="checkbox" id="' . ur_h($id) . '" name="' . ur_h($name) . '" value="1"' . $checked . '> ';
            echo ur_h(ur_t($label)) . ' <code>' . ur_h($flag) . '</code>';
            echo '</label>';
        }
        echo '</dd>';
        echo '</dl>';

        // --- destructive flags (warned) ------------------------------------
        echo '<dl>';
        echo '<dt>' . ur_h(ur_t('destructive flags')) . ':</dt>';
        echo '<dd>';
        foreach ($destructive as $key => [$label, $flag]) {
            $name    = $prefix . '[' . $key . ']';
            $id      = $idBase . '_' . $key;
            $checked = !empty($opts[$key]) ? ' checked' : '';
            echo '<label class="ur-checkbox" for="' . ur_h($id) . '">';
            echo '<input type="hidden" name="' . ur_h($name) . '" value="0">';
            echo '<input type="checkbox" id="' . ur_h($id) . '" name="' . ur_h($name) . '" value="1"' . $checked . '> ';
            echo ur_h(ur_t($label)) . ' <code>' . ur_h($flag) . '</code>';
            echo '</label>';
        }
        echo '</dd>';
        echo '</dl>';
        echo '<blockquote class="inline_help"><p>'
            . ur_h(ur_t('Delete options remove files on the destination that are no longer on the source. '
                . 'When enabled, the destination must be a specific sub-directory and a "Max delete" cap is strongly recommended.'))
            . '</p></blockquote>';

        // --- excludes / includes (repeatable rows) -------------------------
        foreach (['excludes' => '--exclude=', 'includes' => '--include='] as $key => $flag) {
            $name      = $prefix . '[' . $key . '][]';
            $rowsId    = $idBase . '_' . $key . '_rows';
            $values    = (isset($opts[$key]) && is_array($opts[$key])) ? $opts[$key] : [];
            $labelText = ($key === 'excludes') ? 'Excludes' : 'Includes';
            echo '<dl>';
            echo '<dt>' . ur_h(ur_t($labelText)) . ' <code>' . ur_h($flag) . '</code>:</dt>';
            echo '<dd>';
            echo '<div class="ur-rows" id="' . ur_h($rowsId) . '" data-name="' . ur_h($name) . '">';
            if (empty($values)) {
                // one empty starter row
                echo '<div class="ur-row"><input type="text" name="' . ur_h($name) . '" value=""> '
                    . '<button type="button" class="ur-row-del">&minus;</button></div>';
            } else {
                foreach ($values as $val) {
                    echo '<div class="ur-row"><input type="text" name="' . ur_h($name) . '" value="' . ur_h($val) . '"> '
                        . '<button type="button" class="ur-row-del">&minus;</button></div>';
                }
            }
            echo '</div>';
            echo '<button type="button" class="ur-row-add" data-rows="' . ur_h($rowsId) . '">'
                . ur_h(ur_t('Add')) . '</button>';
            echo '</dd>';
            echo '</dl>';
        }

        // --- scalar value inputs -------------------------------------------
        echo '<dl>';
        foreach ($scalars as $key => [$label, $flag]) {
            $name = $prefix . '[' . $key . ']';
            $id   = $idBase . '_' . $key;
            $val  = isset($opts[$key]) ? (string) $opts[$key] : '';
            echo '<dt><label for="' . ur_h($id) . '">' . ur_h(ur_t($label)) . ' <code>' . ur_h($flag) . '</code></label>:</dt>';
            echo '<dd><input type="text" id="' . ur_h($id) . '" name="' . ur_h($name) . '" value="' . ur_h($val) . '"></dd>';
        }
        echo '</dl>';

        echo '</div>';
    }
}

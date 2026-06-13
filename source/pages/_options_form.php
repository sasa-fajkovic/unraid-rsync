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
 * Every option control carries a click-to-read help affordance (a small "?"
 * button next to the label) that reveals a short, plain-English description of
 * what the flag does, the actual rsync flag it maps to, and a caution for the
 * destructive ones. The descriptions live in ur_option_help() so they are
 * unit-testable (one per whitelist key) and the same help appears in BOTH the
 * Global Settings defaults form and the per-job options block, since both
 * include this shared partial.
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

if (!function_exists('ur_option_help')) {
    /**
     * The plain-English help text for every whitelisted rsync option, keyed by
     * the same key the form uses (and that Config::defaultRsyncOptions() defines).
     *
     * Each entry is one or two short sentences: what the option does, the actual
     * rsync flag it maps to (shown in parentheses, e.g. "(-a)"/"(--delete)"), and
     * a caution for the destructive ones. These strings are what the click-to-read
     * "?" affordance reveals next to each control, and they are asserted complete
     * by OptionsFormHelpTest so a future option added without a description fails
     * CI.
     *
     * Kept as a pure function (no output, no webGui dependency) so it is unit
     * testable in isolation. Wrap the returned strings with ur_t() at render time
     * for translation; they are stored untranslated here.
     *
     * @return array<string,string> key => description (never empty per key)
     */
    function ur_option_help(): array
    {
        return [
            // --- boolean flags ---------------------------------------------
            'archive'        => 'Recurse into directories and preserve symlinks, permissions, modification times, group, owner and device files (-a). The usual baseline for a faithful copy.',
            'compress'       => 'Compress file data while it is in transit (-z). Helps over slow or metered links; little benefit on a fast local network.',
            'humanReadable'  => 'Show sizes in a human-readable format such as 1.5K or 2.3M instead of raw bytes (-h).',
            'times'          => 'Preserve modification times on the destination (-t). Without it, every file looks changed on the next run.',
            'perms'          => 'Preserve file permissions on the destination (-p).',
            'xattrs'         => 'Preserve extended attributes (-X). Both the source and destination filesystems must support xattrs.',
            'acls'           => 'Preserve Access Control Lists (-A). This implies preserving permissions (-p) as well.',
            'symlinks'       => 'Copy symbolic links as symbolic links rather than following them (-l).',
            'hardlinks'      => 'Detect hard-linked files in the source and re-create those links on the destination (-H).',
            'sparse'         => 'Handle sparse files efficiently so they take up less space on the destination (-S).',
            'numericIds'     => 'Transfer numeric user and group IDs rather than mapping them by name (--numeric-ids). Useful when the two systems do not share users.',
            'partial'        => 'Keep partially transferred files instead of deleting them, so an interrupted transfer can resume (--partial).',
            'inplace'        => 'Write updates directly into the destination file rather than to a temporary copy (--inplace). Saves space but leaves a partly-written file if the transfer is interrupted.',
            'checksum'       => 'Decide whether a file changed by comparing checksums instead of size and modification time (-c). More accurate but reads every file, so it is slower.',
            'update'         => 'Skip any file that is newer on the receiver than on the sender (-u).',
            'wholeFile'      => 'Copy whole files and skip the delta-transfer algorithm (-W). Often faster on a fast local link or local disks.',
            'sizeOnly'       => 'Treat files whose size matches as unchanged, ignoring modification time (--size-only).',
            'ignoreExisting' => 'Skip any file that already exists on the destination; only brand-new files are copied (--ignore-existing).',
            // --- destructive flags -----------------------------------------
            'delete'         => 'DELETE files on the destination that no longer exist in the source (--delete). Destructive: it removes data from the destination — pair it with a Max delete cap.',
            'deleteExcluded' => 'Also DELETE files on the destination that match your exclude patterns (--delete-excluded). Destructive: excluded files are removed from the destination, not just skipped.',
            // --- excludes / includes ---------------------------------------
            'excludes'       => 'Skip files and directories matching each pattern (--exclude=PATTERN). Add one pattern per row, e.g. *.tmp or .cache/.',
            'includes'       => 'Force matching files to be transferred even when a later exclude pattern would skip them (--include=PATTERN). Add one pattern per row.',
            // --- scalar value inputs ---------------------------------------
            'maxDelete'      => 'Refuse to delete more than this many files (--max-delete=N). A safety cap that aborts a run if --delete would remove too much.',
            'bwlimit'        => 'Cap the transfer bandwidth in KB/s (--bwlimit=RATE), e.g. 5000 for about 5 MB/s. Leave blank for no limit.',
            'timeout'        => 'Abort the transfer if no data is sent or received for this many seconds (--timeout=SECONDS).',
            'contimeout'     => 'Give up if the connection cannot be established within this many seconds (--contimeout=SECONDS).',
            'maxSize'        => 'Skip any file larger than this size (--max-size=SIZE), e.g. 100M or 2G.',
            'minSize'        => 'Skip any file smaller than this size (--min-size=SIZE), e.g. 10K.',
            'chmod'          => 'Force permissions on transferred files and directories (--chmod=CHMOD), e.g. D755,F644 for directories 755 and files 644.',
            'tempDir'        => 'Place temporary files in this directory during the transfer (--temp-dir=DIR) instead of next to the destination files.',
            'backupDir'      => 'Move changed or deleted files into this directory instead of overwriting them (--backup-dir=DIR). Also enables --backup.',
            'compressLevel'  => 'Set the zlib compression level from 0 (none) to 9 (maximum) (--compress-level=N). Only matters when Compress is on.',
            'modifyWindow'   => 'Allow this many seconds of tolerance when comparing modification times (--modify-window=SECONDS). Useful for FAT filesystems with coarse timestamps.',
        ];
    }
}

if (!function_exists('ur_option_help_for')) {
    /**
     * The translated help string for a single option key, or '' when the key has
     * no description. Centralises the ur_t() wrap so the renderer stays terse.
     */
    function ur_option_help_for(string $key): string
    {
        $map = ur_option_help();
        return isset($map[$key]) ? ur_t($map[$key]) : '';
    }
}

if (!function_exists('ur_option_help_affordance')) {
    /**
     * Render the click-to-read help affordance for one option: a small "?" button
     * the user clicks to toggle a hidden inline_help blockquote that holds the
     * description. The button also carries the text as a native `title=` tooltip
     * (additive convenience; the click-to-read text is the actual requirement).
     *
     * No-op when the key has no description, so callers can invoke it
     * unconditionally. The toggle behaviour is wired by the small script emitted
     * once per page by ur_render_rsync_options(); it is plain DOM (a sibling
     * lookup), so it works without per-control IDs and inside JS-cloned job cards.
     *
     * @param string $key    whitelist option key (used only to look up text)
     * @param string $helpId DOM id for the revealed blockquote (aria target)
     */
    function ur_option_help_affordance(string $key, string $helpId): string
    {
        $text = ur_option_help_for($key);
        if ($text === '') {
            return '';
        }
        // The "?" trigger: a <button> (keyboard-focusable, not a link) styled as a
        // small badge. aria-expanded/aria-controls keep it accessible; the JS flips
        // them. type="button" so it never submits the surrounding form.
        return ' <button type="button" class="ur-help-toggle" aria-expanded="false"'
            . ' aria-controls="' . ur_h($helpId) . '"'
            . ' title="' . ur_h($text) . '">'
            . '<i class="fa fa-question-circle" aria-hidden="true"></i>'
            . '<span class="ur-help-sr">' . ur_h(ur_t('Help')) . '</span>'
            . '</button>';
    }
}

if (!function_exists('ur_option_help_block')) {
    /**
     * The hidden inline_help blockquote that the "?" affordance reveals. Uses the
     * native Unraid inline_help/blockquote styling so it matches other settings
     * pages, and starts collapsed (hidden) so we don't permanently show dozens of
     * long descriptions. No-op when the key has no description.
     *
     * @param string $key    whitelist option key
     * @param string $helpId DOM id matching the affordance's aria-controls
     */
    function ur_option_help_block(string $key, string $helpId): string
    {
        $text = ur_option_help_for($key);
        if ($text === '') {
            return '';
        }
        return '<blockquote class="inline_help ur-help-text" id="' . ur_h($helpId) . '" hidden>'
            . '<p>' . ur_h($text) . '</p></blockquote>';
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

        // Emit the toggle CSS + JS exactly once per page, even when several option
        // blocks render (Global Settings has one; the Jobs tab has one per card
        // plus the hidden template). Guarding here keeps the help UI self-contained
        // in this partial, so both including pages get it with no extra wiring.
        ur_emit_option_help_assets();

        echo '<div class="ur-rsync-options">';

        // --- boolean flags -------------------------------------------------
        echo '<dl>';
        echo '<dt>' . ur_h(ur_t('rsync flags')) . ':</dt>';
        echo '<dd>';
        foreach ($bools as $key => [$label, $flag]) {
            $name    = $prefix . '[' . $key . ']';
            $id      = $idBase . '_' . $key;
            $helpId  = $id . '_help';
            $checked = !empty($opts[$key]) ? ' checked' : '';
            // Hidden "0" before the checkbox so an unchecked box still submits
            // a (falsey) value at this name, keeping the stored shape stable.
            echo '<div class="ur-opt">';
            echo '<label class="ur-checkbox" for="' . ur_h($id) . '">';
            echo '<input type="hidden" name="' . ur_h($name) . '" value="0">';
            echo '<input type="checkbox" id="' . ur_h($id) . '" name="' . ur_h($name) . '" value="1"' . $checked . '> ';
            echo ur_h(ur_t($label)) . ' <code>' . ur_h($flag) . '</code>';
            echo '</label>';
            echo ur_option_help_affordance($key, $helpId);
            echo ur_option_help_block($key, $helpId);
            echo '</div>';
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
            $helpId  = $id . '_help';
            $checked = !empty($opts[$key]) ? ' checked' : '';
            echo '<div class="ur-opt">';
            echo '<label class="ur-checkbox" for="' . ur_h($id) . '">';
            echo '<input type="hidden" name="' . ur_h($name) . '" value="0">';
            echo '<input type="checkbox" id="' . ur_h($id) . '" name="' . ur_h($name) . '" value="1"' . $checked . '> ';
            echo ur_h(ur_t($label)) . ' <code>' . ur_h($flag) . '</code>';
            echo '</label>';
            echo ur_option_help_affordance($key, $helpId);
            echo ur_option_help_block($key, $helpId);
            echo '</div>';
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
            $helpId    = $idBase . '_' . $key . '_help';
            $values    = (isset($opts[$key]) && is_array($opts[$key])) ? $opts[$key] : [];
            $labelText = ($key === 'excludes') ? 'Excludes' : 'Includes';
            echo '<dl>';
            echo '<dt>' . ur_h(ur_t($labelText)) . ' <code>' . ur_h($flag) . '</code>'
                . ur_option_help_affordance($key, $helpId) . ':</dt>';
            echo '<dd>';
            echo ur_option_help_block($key, $helpId);
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
            $name   = $prefix . '[' . $key . ']';
            $id     = $idBase . '_' . $key;
            $helpId = $id . '_help';
            $val    = isset($opts[$key]) ? (string) $opts[$key] : '';
            echo '<dt><label for="' . ur_h($id) . '">' . ur_h(ur_t($label)) . ' <code>' . ur_h($flag) . '</code></label>'
                . ur_option_help_affordance($key, $helpId) . ':</dt>';
            echo '<dd><input type="text" id="' . ur_h($id) . '" name="' . ur_h($name) . '" value="' . ur_h($val) . '">';
            echo ur_option_help_block($key, $helpId);
            echo '</dd>';
        }
        echo '</dl>';

        echo '</div>';
    }
}

if (!function_exists('ur_emit_option_help_assets')) {
    /**
     * Emit the CSS + JS that powers the click-to-read help toggle, exactly once
     * per page. The first call prints the assets; later calls are no-ops (a static
     * guard), so multiple option blocks on the Jobs tab don't duplicate them.
     *
     * The script is a single delegated click listener on document, so it also
     * handles affordances inside job cards that are cloned client-side from the
     * hidden template (their "?" buttons exist in the cloned DOM and need no
     * per-card wiring). It toggles the sibling .ur-help-text blockquote's `hidden`
     * attribute and keeps aria-expanded in sync. All static markup; no user data.
     */
    function ur_emit_option_help_assets(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
<style>
/* Click-to-read help affordance for each rsync option. The "?" trigger is a small
   round badge that matches the native Unraid look; the revealed text reuses the
   stock .inline_help blockquote styling so it reads like other settings pages. */
.ur-opt { margin: 1px 0; }
.ur-help-toggle {
  display: inline-flex; align-items: center; justify-content: center;
  margin-left: 6px; padding: 0; border: 0; background: transparent;
  color: var(--text-color, #2a87d0); cursor: pointer; font-size: 13px;
  line-height: 1; vertical-align: middle;
}
.ur-help-toggle:hover, .ur-help-toggle:focus { color: var(--orange-500, #ff8c2f); }
.ur-help-toggle[aria-expanded="true"] { color: var(--orange-500, #ff8c2f); }
.ur-help-toggle .fa { pointer-events: none; }
/* Visually-hidden label so the button is still announced by screen readers when
   the FontAwesome glyph isn't read out. */
.ur-help-sr {
  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}
/* The revealed description. `hidden` keeps it collapsed until the user clicks. */
.ur-help-text[hidden] { display: none; }
.ur-help-text { margin-top: 4px; }
</style>
<script type="text/javascript">
/* Click-to-read help: one delegated listener toggles the description blockquote
 * that follows each "?" button. Works for server-rendered controls and for job
 * cards cloned client-side (the buttons live in the cloned DOM). */
(function () {
  'use strict';
  if (window.urOptionHelpWired) { return; }
  window.urOptionHelpWired = true;

  function findHelp(btn) {
    /* Prefer the aria-controls target by id; fall back to the next sibling
     * blockquote so the toggle still works if ids ever collide. */
    var id = btn.getAttribute('aria-controls');
    if (id) {
      var byId = document.getElementById(id);
      if (byId) { return byId; }
    }
    var n = btn.nextElementSibling;
    while (n) {
      if (n.classList && n.classList.contains('ur-help-text')) { return n; }
      n = n.nextElementSibling;
    }
    return null;
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target;
    while (btn && btn !== document) {
      if (btn.classList && btn.classList.contains('ur-help-toggle')) { break; }
      btn = btn.parentNode;
    }
    if (!btn || btn === document) { return; }
    ev.preventDefault();
    var help = findHelp(btn);
    if (!help) { return; }
    var show = help.hasAttribute('hidden');
    if (show) { help.removeAttribute('hidden'); } else { help.setAttribute('hidden', ''); }
    btn.setAttribute('aria-expanded', show ? 'true' : 'false');
  });
})();
</script>
        <?php
    }
}

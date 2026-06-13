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
 * NATIVE LOOK. The options are laid out in Unraid's native two-column settings
 * style: every option is a definition-list row (<dl><dt>label</dt><dd>control
 * </dd></dl>), exactly like DiskSettings/DockerSettings, so the grid columns,
 * spacing and label alignment come from the inherited dynamix stylesheet
 * (default-base.css `dl/dt/dd`) and the page blends in with the rest of Unraid.
 *
 * NATIVE INLINE HELP. Each row carries a subtle "?" affordance that is hidden
 * until you hover (or keyboard-focus) the row, mirroring how native settings
 * pages reveal help. Clicking it toggles a real <blockquote class="inline_help">
 * blue callout - the same element + class Unraid's Markdown.php emits for a
 * "> help" line, so it picks up the stock blue box styling (--blue-100 fill,
 * --blue-200 top/bottom rules) verbatim. The "?" is deliberately NOT a <button>
 * (the webGui base stylesheet renders every <button> as a big, bordered,
 * uppercase pill - that is what produced the previous "orange HELP button"); it
 * is a small inline span with role="button" so it stays a lightweight icon.
 *
 * The descriptions live in ur_option_help() so they are unit-testable (one per
 * whitelist key) and the same help appears in BOTH the Global Settings defaults
 * form and the per-job options block, since both include this shared partial.
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
     * a caution for the destructive ones. These strings are what the "?" help
     * affordance reveals in a native blue inline_help box next to each control,
     * and they are asserted complete by OptionsFormHelpTest so a future option
     * added without a description fails CI.
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
     *
     * The renderer calls this twice per option (affordance + block) and the Jobs
     * tab renders many cards, so the (immutable) help map is built once per
     * request and cached in a static rather than rebuilt on every call; only the
     * cheap ur_t() lookup runs per call.
     */
    function ur_option_help_for(string $key): string
    {
        static $map = null;
        if ($map === null) {
            $map = ur_option_help();
        }
        return isset($map[$key]) ? ur_t($map[$key]) : '';
    }
}

if (!function_exists('ur_option_help_affordance')) {
    /**
     * Render the native "?" help affordance for one option: a small question-mark
     * icon that stays subtle (hidden until the row is hovered or the icon is
     * focused, exactly like native settings rows) and, on click, toggles the
     * row's blue inline_help box.
     *
     * It is a <span role="button"> rather than a <button> on purpose: the webGui
     * base stylesheet styles EVERY <button>/<input type=submit> as a large,
     * bordered, uppercase pill (min-width:86px, border, text-transform:uppercase),
     * which is what turned the old affordance into a big "orange HELP button".
     * A span escapes that chrome entirely and renders as a light inline icon while
     * still being keyboard-operable (tabindex=0 + aria-* + Enter/Space handled by
     * the shared script). The description is also exposed as a native title=
     * tooltip for good measure.
     *
     * No-op when the key has no description, so callers can invoke it
     * unconditionally. The toggle behaviour is wired by the small script emitted
     * once per page by ur_emit_option_help_assets(); it resolves the help block by
     * the affordance's aria-controls id (with a next-sibling scan only as a
     * fallback), so it works for every layout here and inside JS-cloned job cards.
     * $helpId must therefore match the id of the ur_option_help_block() for the
     * same key.
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
        return ' <span class="ur-help" role="button" tabindex="0" aria-expanded="false"'
            . ' aria-controls="' . ur_h($helpId) . '"'
            . ' aria-label="' . ur_h(ur_t('Help')) . '"'
            . ' title="' . ur_h($text) . '">?</span>';
    }
}

if (!function_exists('ur_option_help_block')) {
    /**
     * The hidden inline_help blockquote that the "?" affordance reveals. Uses the
     * native Unraid inline_help/blockquote element + class so it gets the stock
     * blue callout styling (the same markup Markdown.php emits for a "> help"
     * line). It starts collapsed via the native `.inline_help { display:none }`
     * rule, and the shared script reveals it by adding the `ur-open` class. No-op
     * when the key has no description.
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
        return '<blockquote class="inline_help ur-help-text" id="' . ur_h($helpId) . '">'
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

        // Emit the help CSS + JS exactly once per page, even when several option
        // blocks render (Global Settings has one; the Jobs tab has one per card
        // plus the hidden template). Guarding here keeps the help UI self-contained
        // in this partial, so both including pages get it with no extra wiring.
        ur_emit_option_help_assets();

        echo '<div class="ur-rsync-options">';

        // --- boolean flags -------------------------------------------------
        // One native dl row per flag: label on the left (<dt>), the checkbox
        // control on the right (<dd>) with its "?" + collapsible help box.
        echo '<dl>';
        foreach ($bools as $key => [$label, $flag]) {
            ur_render_bool_row($prefix, $idBase, $key, $label, $flag, !empty($opts[$key]));
        }
        echo '</dl>';

        // --- destructive flags (warned) ------------------------------------
        echo '<dl>';
        foreach ($destructive as $key => [$label, $flag]) {
            ur_render_bool_row($prefix, $idBase, $key, $label, $flag, !empty($opts[$key]));
        }
        echo '</dl>';

        // --- excludes / includes (repeatable rows) -------------------------
        foreach (['excludes' => '--exclude=', 'includes' => '--include='] as $key => $flag) {
            $name      = $prefix . '[' . $key . '][]';
            $rowsId    = $idBase . '_' . $key . '_rows';
            $helpId    = $idBase . '_' . $key . '_help';
            $values    = (isset($opts[$key]) && is_array($opts[$key])) ? $opts[$key] : [];
            $labelText = ($key === 'excludes') ? 'Excludes' : 'Includes';
            echo '<dl>';
            echo '<dt class="ur-dt">' . ur_h(ur_t($labelText)) . ' <code>' . ur_h($flag) . '</code>'
                . ur_option_help_affordance($key, $helpId) . '</dt>';
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
            echo ur_option_help_block($key, $helpId);
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
            echo '<dt class="ur-dt"><label for="' . ur_h($id) . '">' . ur_h(ur_t($label)) . ' <code>' . ur_h($flag) . '</code></label>'
                . ur_option_help_affordance($key, $helpId) . '</dt>';
            echo '<dd><input type="text" id="' . ur_h($id) . '" name="' . ur_h($name) . '" value="' . ur_h($val) . '">';
            echo ur_option_help_block($key, $helpId);
            echo '</dd>';
        }
        echo '</dl>';

        echo '</div>';
    }
}

if (!function_exists('ur_render_bool_row')) {
    /**
     * Render one boolean-flag option as a native two-column dl row: the label
     * (with its rsync flag and "?" affordance) in the <dt>, the checkbox control
     * and its collapsible inline_help box in the <dd>.
     *
     * A hidden "0" input precedes the checkbox so an unchecked box still submits a
     * (falsey) value at the same name, keeping the stored shape stable. The field
     * name/value/id are unchanged from before so $_POST round-trips identically.
     */
    function ur_render_bool_row(string $prefix, string $idBase, string $key, string $label, string $flag, bool $checked): void
    {
        $name   = $prefix . '[' . $key . ']';
        $id     = $idBase . '_' . $key;
        $helpId = $id . '_help';
        echo '<dt class="ur-dt"><label for="' . ur_h($id) . '">' . ur_h(ur_t($label)) . ' <code>' . ur_h($flag) . '</code></label>'
            . ur_option_help_affordance($key, $helpId) . '</dt>';
        echo '<dd>';
        echo '<input type="hidden" name="' . ur_h($name) . '" value="0">';
        echo '<input type="checkbox" id="' . ur_h($id) . '" name="' . ur_h($name) . '" value="1"' . ($checked ? ' checked' : '') . '>';
        echo ur_option_help_block($key, $helpId);
        echo '</dd>';
    }
}

if (!function_exists('ur_emit_option_help_assets')) {
    /**
     * Emit the CSS + JS that powers the native "?" help affordance, exactly once
     * per page. The first call prints the assets; later calls are no-ops (a static
     * guard), so multiple option blocks on the Jobs tab don't duplicate them.
     *
     * CSS: the "?" is a small circular icon that is invisible (opacity:0) until
     * the row is hovered or the icon focused — matching how native settings rows
     * keep help subtle. The revealed text is a genuine `blockquote.inline_help`,
     * so it inherits the stock blue callout (--blue-100 fill, --blue-200 rules)
     * from the inherited dynamix stylesheet; we only flip it from the native
     * `display:none` to shown via a `.ur-open` class.
     *
     * JS: a single delegated click/keydown listener on document, so it also
     * handles affordances inside job cards that are cloned client-side from the
     * hidden template (their "?" spans exist in the cloned DOM and need no
     * per-card wiring). For an activated "?" it resolves the target
     * .ur-help-text blockquote by the icon's aria-controls id (falling back to a
     * next-sibling scan), toggles that block's `ur-open` class and keeps
     * aria-expanded in sync. All static markup; no user data.
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
/* Native two-column rsync options. The dl/dt/dd grid + label alignment come from
   the inherited dynamix stylesheet; these rules only tune the inline bits. */
.ur-rsync-options code { font-size: 0.95em; opacity: 0.85; }
.ur-rsync-options .ur-dt { position: relative; }

/* The "?" help affordance. A small circular icon in the native help-blue, kept
   subtle (opacity:0) until the row is hovered or the icon focused — mirroring how
   native settings rows reveal help. Deliberately a <span role=button>, NOT a
   <button>, so it never inherits the heavy bordered/uppercase webGui button
   chrome (that produced the old "orange HELP button"). */
.ur-help {
  display: inline-flex; align-items: center; justify-content: center;
  width: 16px; height: 16px; margin-left: 6px;
  border-radius: 50%;
  border: 1px solid var(--blue-200, #bce8f1);
  background: var(--blue-100, #d9edf7);
  color: var(--blockquote-text-color, #31708f);
  font-size: 11px; font-weight: bold; line-height: 1;
  text-align: center; cursor: help; vertical-align: middle;
  opacity: 0; transition: opacity .12s ease-in-out;
  -webkit-user-select: none; -moz-user-select: none; user-select: none;
}
/* Reveal on hover/focus of the row (dt), and keep it visible while open. */
.ur-dt:hover .ur-help,
.ur-help:focus,
.ur-help[aria-expanded="true"] { opacity: 1; }
.ur-help:focus { outline: 1px solid var(--blue-200, #bce8f1); outline-offset: 1px; }
/* Touch devices have no hover: always show the icon there. */
@media (hover: none) { .ur-help { opacity: 1; } }

/* The revealed description reuses the native blockquote.inline_help blue box.
   `.inline_help` is `display:none` in the base stylesheet; reveal by adding the
   ur-open class. Tighten the native margins a touch so it sits under its row. */
.ur-help-text.ur-open { display: block; }
.ur-rsync-options .inline_help { margin: .4rem 0; }
</style>
<script type="text/javascript">
/* Native-style inline help: one delegated listener toggles the blue
 * blockquote.inline_help box that belongs to each "?" affordance. Works for
 * server-rendered controls and for job cards cloned client-side (the spans live
 * in the cloned DOM, so no per-card wiring is needed). */
(function () {
  'use strict';
  if (window.urOptionHelpWired) { return; }
  window.urOptionHelpWired = true;

  function findHelp(el) {
    /* Prefer the aria-controls target by id; fall back to a forward scan within
     * the same row/cell so the toggle still works if ids ever collide. */
    var id = el.getAttribute('aria-controls');
    if (id) {
      var byId = document.getElementById(id);
      if (byId) { return byId; }
    }
    var dt = el.closest ? el.closest('dt') : null;
    var dd = dt ? dt.nextElementSibling : null;
    if (dd && dd.querySelector) {
      var inDd = dd.querySelector('.ur-help-text');
      if (inDd) { return inDd; }
    }
    var n = el.nextElementSibling;
    while (n) {
      if (n.classList && n.classList.contains('ur-help-text')) { return n; }
      n = n.nextElementSibling;
    }
    return null;
  }

  function toggle(el) {
    var help = findHelp(el);
    if (!help) { return; }
    var show = !help.classList.contains('ur-open');
    if (show) { help.classList.add('ur-open'); } else { help.classList.remove('ur-open'); }
    el.setAttribute('aria-expanded', show ? 'true' : 'false');
  }

  function helpFromEvent(target) {
    var el = target;
    while (el && el !== document) {
      if (el.classList && el.classList.contains('ur-help')) { return el; }
      el = el.parentNode;
    }
    return null;
  }

  document.addEventListener('click', function (ev) {
    var el = helpFromEvent(ev.target);
    if (!el) { return; }
    ev.preventDefault();
    toggle(el);
  });

  /* Keyboard: Enter/Space activate the focused "?" (it is a role=button). */
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' && ev.key !== ' ' && ev.key !== 'Spacebar') { return; }
    var el = ev.target;
    if (!el || !el.classList || !el.classList.contains('ur-help')) { return; }
    ev.preventDefault();
    toggle(el);
  });
})();
</script>
        <?php
    }
}

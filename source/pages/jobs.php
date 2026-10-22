<?php
/**
 * jobs.php - the Jobs tab body.
 *
 * Renders:
 *   - a tablesorter summary list of the configured jobs (Name, Enabled,
 *     Transport, Schedule);
 *   - a full add / edit / remove CRUD form. Each job is an editable card with:
 *       name, enable switch, transport (SSH|LOCAL), direction (PUSH|PULL),
 *       connection (populated from the saved Credentials connections; an
 *       unknown existing connectionId is preserved as an option so edits don't
 *       drop it), per-job cron schedule, add/remove source->dest pair rows,
 *       a "use global defaults" toggle gating the whitelisted rsync options
 *       block, log-level select, notify-mode select, and pre/post hook
 *       textareas.
 *
 * Nested field names round-trip straight back into $_POST, e.g.
 *   jobs[<i>][name]
 *   jobs[<i>][pairs][<k>][local]
 *   jobs[<i>][rsyncOptions][archive]
 *   jobs[<i>][rsyncOptions][filters][type][]
 *   jobs[<i>][rsyncOptions][filters][pattern][]
 *
 * New jobs / pair rows are created client-side by cloning a hidden template and
 * rewriting the row index placeholders, so the indices stay contiguous and the
 * names round-trip. Every rendered value is htmlspecialchars-escaped.
 *
 * Per-job Run / Dry-run / Abort buttons POST to the handler (CSRF-checked) to
 * launch / stop the detached runner; Run/Dry-run are disabled while a job is
 * running (RunState::isRunning), Abort while it is idle. After a click the
 * shared getStatus poller (window.urStatus) refreshes the badges and button
 * state in place (no reload).
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Config.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Job.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Credentials.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/RunState.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Runner.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Cron.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php';

$csrf = ur_render_csrf_token();

// If the on-disk config can't be read (unreadable, corrupt, or from a newer
// schema), render defaults for DISPLAY only but surface a visible warning -
// otherwise it looks like jobs were lost, and the handler will refuse the save
// (409) anyway. We never persist on load.
$loadError = '';
try {
    $config = Config::load();
} catch (Throwable $e) {
    $config = Config::defaults();
    $loadError = $e->getMessage();
}
$jobs        = (isset($config['jobs']) && is_array($config['jobs'])) ? $config['jobs'] : [];
$globalOpts  = $config['global']['defaultRsyncOptions'] ?? Config::defaultRsyncOptions();
$handlerUrl  = PageHelpers::HANDLER_URL;

// Load the saved connections so the per-job Connection select can be populated.
// Point Credentials at the configured secrets dir (if any) BEFORE loading, so
// a custom global.secretsDir resolves credentials.json from the same place
// the handler writes it - see ur_push_secrets_dir_override(). A credentials
// read error here is non-fatal for the Jobs tab: we just render an empty
// connection list (the Connections tab surfaces the real error).
ur_push_secrets_dir_override();
$urConnections = [];
try {
    $credsForJobs = Credentials::load();
    $urConnections = (isset($credsForJobs['connections']) && is_array($credsForJobs['connections']))
        ? $credsForJobs['connections'] : [];
} catch (Throwable $e) {
    $urConnections = [];
}

/**
 * Render one editable job card. $index is either an int (an existing job's row
 * index) or the literal placeholder "__IDX__" for the JS template.
 *
 * @param array<string,mixed> $job
 * @param int|string          $index
 */
function ur_render_job_card($job, $index): void
{
    $p   = 'jobs[' . $index . ']';                  // POST name prefix
    $idb = 'ur_job_' . $index;                      // DOM-id base
    $job = is_array($job) ? $job : Config::defaultJob();

    $name        = (string) ($job['name'] ?? '');
    $id          = (string) ($job['id'] ?? '');
    $enabled     = !empty($job['enabled']);
    $manualOnly  = !empty($job['manualOnly']);
    $schedule    = (string) ($job['schedule'] ?? '0 3 * * *');
    $transport   = (string) ($job['transport'] ?? 'SSH');
    $direction   = (string) ($job['direction'] ?? 'PUSH');
    $connId      = (string) ($job['connectionId'] ?? '');
    $useGlobal   = !empty($job['useGlobalDefaults']);
    $logLevel    = (string) ($job['logLevel'] ?? 'normal');
    $notifyMode  = (string) ($job['notifyMode'] ?? 'failure-only');
    $preHook     = (string) ($job['preHook'] ?? '');
    $postHook    = (string) ($job['postHook'] ?? '');
    $marker      = (string) ($job['targetMarker'] ?? '');
    $markerText  = (string) ($job['targetMarkerContent'] ?? '');
    $pairs       = (isset($job['pairs']) && is_array($job['pairs'])) ? $job['pairs'] : [];
    $snapshot    = Config::normalizeSnapshot($job['snapshot'] ?? null);
    $snapOn      = $snapshot !== null;
    $snapshot    = $snapshot ?? Config::defaultSnapshot();
    $opts        = (isset($job['rsyncOptions']) && is_array($job['rsyncOptions']))
        ? $job['rsyncOptions'] : Config::defaultRsyncOptions();

    echo '<div class="ur-job-card" data-index="' . ur_h($index) . '">';

    // Collapsible header (COLLAPSED by default): a one-line summary so a page with
    // many jobs is not a wall of forms. Click / Enter / Space toggles the editable
    // body below (delegated in JS; works for server-rendered AND cloned cards).
    $summaryBits = [];
    $summaryBits[] = $enabled ? ur_t_text('enabled') : ur_t_text('disabled');
    if ($schedule !== '') {
        $summaryBits[] = $schedule;
    }
    echo '<div class="ur-job-head" role="button" tabindex="0" aria-expanded="false">';
    echo '<span class="ur-job-caret" aria-hidden="true">&#9654;</span>';
    echo '<span class="ur-job-title">' . ur_h($name !== '' ? $name : ur_t_text('(unnamed job)')) . '</span>';
    echo '<span class="ur-job-sub">' . ur_h(implode(' · ', $summaryBits)) . '</span>';
    echo '</div>';

    // The editable body. `hidden` keeps it collapsed by default; a display:none
    // region still SUBMITS its fields, so saved values are never lost while
    // collapsed. (New cards are expanded by the Add-job handler, and any card
    // with an invalid required field is auto-expanded before submit validation.)
    echo '<div class="ur-job-body" hidden>';

    // Hidden id (preserved across edits; blank for a new job - handler slugs it).
    echo '<input type="hidden" name="' . ur_h($p . '[id]') . '" value="' . ur_h($id) . '">';

    echo '<dl>';

    // name (required)
    echo '<dt><label for="' . ur_h($idb . '_name') . '">' . ur_h(ur_t_text('Name')) . '</label>' . ur_required_mark() . ':</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_name') . '" name="' . ur_h($p . '[name]') . '" value="' . ur_h($name) . '" required></dd>';

    // enabled (checkbox; styled as a switch by webGui where available)
    echo '<dt>' . ur_h(ur_t_text('Enabled')) . ':</dt>';
    echo '<dd>';
    echo '<input type="hidden" name="' . ur_h($p . '[enabled]') . '" value="0">';
    echo '<input type="checkbox" class="ur-switch" name="' . ur_h($p . '[enabled]') . '" value="1"' . ($enabled ? ' checked' : '') . '>';
    echo '</dd>';

    // transport (drives whether Connection is required - see the JS toggle)
    echo '<dt><label for="' . ur_h($idb . '_transport') . '">' . ur_h(ur_t_text('Transport')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_transport') . '" class="ur-transport-select" data-conn="' . ur_h($idb . '_conn') . '" name="' . ur_h($p . '[transport]') . '">';
    foreach (['SSH' => 'SSH (remote host)', 'LOCAL' => 'Local (this server)', 'DAEMON' => 'rsync daemon (host::module)'] as $val => $lbl) {
        $sel = ($transport === $val) ? ' selected' : '';
        echo '<option value="' . ur_h($val) . '"' . $sel . '>' . ur_h(ur_t_text($lbl)) . '</option>';
    }
    echo '</select></dd>';

    // direction
    echo '<dt><label for="' . ur_h($idb . '_direction') . '">' . ur_h(ur_t_text('Direction')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_direction') . '" name="' . ur_h($p . '[direction]') . '">';
    foreach (['PUSH' => 'Push (local -> remote)', 'PULL' => 'Pull (remote -> local)'] as $val => $lbl) {
        $sel = ($direction === $val) ? ' selected' : '';
        echo '<option value="' . ur_h($val) . '"' . $sel . '>' . ur_h(ur_t_text($lbl)) . '</option>';
    }
    echo '</select>';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('Direction applies to SSH and rsync daemon transports; a Local job always copies left to right.')) . '</p></blockquote>';
    echo '</dd>';

    // connection (populated from the saved Credentials connections). REQUIRED
    // for every transport EXCEPT Local (which uses no connection at all), so the
    // `required` attribute and the visual marker are toggled by JS off the
    // transport select (and seeded server-side here for the initial transport).
    // Expressed as "not LOCAL" rather than a list of remote transports so an
    // unknown hand-edited value still gets the marker - the safe side, and it
    // matches Job::validate, which requires a Connection for SSH and DAEMON.
    global $urConnections;
    $conns = is_array($urConnections) ? $urConnections : [];
    $connRequired = ($transport !== 'LOCAL');
    echo '<dt><label for="' . ur_h($idb . '_conn') . '">' . ur_h(ur_t_text('Connection')) . '</label>'
        . '<abbr class="ur-required ur-conn-required" title="' . ur_h(ur_t_text('Required')) . '"'
        . ($connRequired ? '' : ' style="display:none"') . '>*</abbr>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_conn') . '" class="ur-conn-select" data-transport="' . ur_h($idb . '_transport') . '" name="' . ur_h($p . '[connectionId]') . '"' . ($connRequired ? ' required' : '') . '>';
    echo '<option value=""' . ($connId === '' ? ' selected' : '') . '>' . ur_h(ur_t_text('(none)')) . '</option>';
    $connFound = false;
    foreach ($conns as $conn) {
        if (!is_array($conn)) {
            continue;
        }
        $cId   = (string) ($conn['id'] ?? '');
        $cName = (string) ($conn['name'] ?? $cId);
        if ($cId === '') {
            continue;
        }
        $sel = ($cId === $connId) ? ' selected' : '';
        if ($cId === $connId) {
            $connFound = true;
        }
        // $urConnections is the RAW credentials array - it never went through
        // Credentials::mergeConnection - so a pre-daemon record has no transport
        // key at all and `?? 'SSH'` is mandatory. The transport is used ONLY to
        // append a label suffix here: never to filter or group the options. A
        // bare branch that dropped a connection from this select would let the
        // next save silently clear the job's connectionId.
        $cSuffix = (strtoupper(trim((string) ($conn['transport'] ?? 'SSH'))) === 'DAEMON') ? ' [rsyncd]' : '';
        echo '<option value="' . ur_h($cId) . '"' . $sel . '>' . ur_h($cName . $cSuffix) . '</option>';
    }
    // Preserve an existing connectionId that no longer matches a saved
    // connection (e.g. the connection was deleted) so editing doesn't silently
    // drop the reference; flag it as missing.
    if ($connId !== '' && !$connFound) {
        echo '<option value="' . ur_h($connId) . '" selected>' . ur_h($connId) . ' ' . ur_h(ur_t_text('(missing)')) . '</option>';
    }
    echo '</select>';
    if (empty($conns)) {
        echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('Add connections in the Connections tab.')) . '</p></blockquote>';
    } else {
        echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('Used for SSH and rsync daemon transports. Manage connections in the Connections tab.')) . '</p></blockquote>';
    }
    echo '</dd>';

    // manual-only toggle: when ON the job is never scheduled (no cron line) and
    // the schedule field is hidden + not required; it runs only on demand.
    echo '<dt>' . ur_h(ur_t_text('Manual only (no schedule)')) . ':</dt>';
    echo '<dd>';
    echo '<input type="hidden" name="' . ur_h($p . '[manualOnly]') . '" value="0">';
    echo '<input type="checkbox" class="ur-switch ur-manual-only" name="' . ur_h($p . '[manualOnly]') . '" value="1"'
        . ($manualOnly ? ' checked' : '') . ' data-idb="' . ur_h($idb) . '">';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('When ON, this job is never scheduled — it runs only when you click Run or Dry-run, and the schedule below is ignored.')) . '</p></blockquote>';
    echo '</dd>';

    // schedule (5-field cron). Required UNLESS manual-only (then hidden); a hidden
    // required field is "not focusable" and would block submit, so the `required`
    // attribute + visual marker track the manual-only toggle (seeded here, kept in
    // sync by JS).
    $schedRowStyle = $manualOnly ? ' style="display:none"' : '';
    echo '<dt class="ur-sched-row" id="' . ur_h($idb . '_schedrow_dt') . '"' . $schedRowStyle . '><label for="' . ur_h($idb . '_schedule') . '">' . ur_h(ur_t_text('Schedule (cron)')) . '</label>'
        . '<abbr class="ur-required ur-sched-required" title="' . ur_h(ur_t_text('Required')) . '"' . ($manualOnly ? ' style="display:none"' : '') . '>*</abbr>:</dt>';
    echo '<dd class="ur-sched-row" id="' . ur_h($idb . '_schedrow_dd') . '"' . $schedRowStyle . '>';
    echo '<input type="text" class="ur-cron-input" id="' . ur_h($idb . '_schedule') . '" name="' . ur_h($p . '[schedule]') . '" value="' . ur_h($schedule) . '" placeholder="0 3 * * *"' . ($manualOnly ? '' : ' required') . '>';
    // Live, plain-English reading of the cron expression (filled by JS on input)
    // plus a field-order legend so the digit order is never guesswork.
    echo '<div class="ur-cron-human" id="' . ur_h($idb . '_cronhuman') . '" aria-live="polite"></div>';
    echo '<div class="ur-cron-legend"><code>* * * * *</code> = '
        . ur_h(ur_t_text('minute(0-59) hour(0-23) day-of-month(1-31) month(1-12) day-of-week(0-6, Sun=0)'))
        . ' &nbsp;·&nbsp; ' . ur_h(ur_t_text('e.g.')) . ' <code>0 3 * * *</code> = ' . ur_h(ur_t_text('03:00 daily'))
        . ', <code>*/15 * * * *</code> = ' . ur_h(ur_t_text('every 15 min')) . '</div>';
    echo '</dd>';

    // pairs
    $pairsRowsId = $idb . '_pairs';
    echo '<dt>' . ur_h(ur_t_text('Source -> destination pairs')) . ':</dt>';
    echo '<dd>';
    echo '<div class="ur-pairs" id="' . ur_h($pairsRowsId) . '" data-prefix="' . ur_h($p . '[pairs]') . '">';
    if (empty($pairs)) {
        ur_render_pair_row($p, 0, [], $snapOn);
    } else {
        foreach ($pairs as $k => $pair) {
            ur_render_pair_row($p, (int) $k, is_array($pair) ? $pair : [], $snapOn);
        }
    }
    echo '</div>';
    echo '<div><button type="button" class="ur-pair-add" data-rows="' . ur_h($pairsRowsId) . '">' . ur_h(ur_t_text('Add pair')) . '</button></div>';
    // The pairs row was the only major field with no help at all, and its boxes
    // are local|remote by POSITION - not source|destination, which Direction
    // decides. Spell that out, plus the rsync-daemon module trap that reaches
    // the support forum: a module name here becomes host:/module over SSH and
    // fails with an opaque link_stat error long after save.
    // display:block because the base stylesheet ships `.inline_help {display:none}`
    // until the page Help toggle is on - and the module trap below is the one
    // sentence that has to be readable without hunting for that toggle.
    echo '<blockquote class="inline_help" style="display:block;margin:6px 0 0"><p>'
        . ur_h(ur_t_text('The LEFT box is always a path on this server; the RIGHT box is the path on the other host (SSH transport) or a second path on this server (Local transport). Which one is the source is set by Direction above: Push reads from the left, Pull reads from the right.'))
        . '</p><p>'
        . ur_h(ur_t_text('For an SSH job the right box must be an absolute filesystem path on the remote host - NOT an rsync daemon module name. If your NAS "Rsync Server" page shows only a module such as "backup", use the folder that module points at (for example /volume1/Backup/data).'))
        . '</p><p>'
        . ur_h(ur_t_text('For an rsync daemon job the right box is a MODULE reference on the daemon - just "rsync_bkp", or "rsync_bkp/photos" for a folder inside it. No host and no leading slash: the host, port, username and module secret all come from the Connection.'))
        . '</p></blockquote>';
    echo '<div class="ur-snap-only"' . ur_snap_style($snapOn) . '><blockquote class="inline_help" style="display:block;margin:6px 0 0"><p>'
        . ur_h(ur_t_text('In snapshot mode the right box is a sub-directory NAME instead of a path: each snapshot holds this pair under <root>/<stamp>/<name>/. Use 1 to 64 letters, digits, "_" or "-", starting with a letter or digit; "latest" is reserved, and each pair of the job needs its own name (compared ignoring case).'))
        . '</p><p>'
        . ur_h(ur_t_text('Seed: allow this pair\'s first copy without a previous snapshot to link against: a full copy. Every pair needs it on the job\'s first snapshot, and so does a pair added later. Once the pair is in a snapshot the flag has no effect and can be cleared.'))
        . '</p></blockquote></div>';
    echo '</dd>';

    // use global config toggle
    echo '<dt>' . ur_h(ur_t_text('Use global config')) . ':</dt>';
    echo '<dd>';
    echo '<input type="hidden" name="' . ur_h($p . '[useGlobalDefaults]') . '" value="0">';
    echo '<input type="checkbox" class="ur-switch ur-use-global" name="' . ur_h($p . '[useGlobalDefaults]') . '" value="1"'
        . ($useGlobal ? ' checked' : '') . ' data-target="' . ur_h($idb . '_opts') . '">';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('When ON, this job uses the rsync options you set on the Global Settings tab (and follows any change you make there). Turn it OFF to give this job its own options below.')) . '</p></blockquote>';
    echo '</dd>';

    // log level
    echo '<dt><label for="' . ur_h($idb . '_log') . '">' . ur_h(ur_t_text('Log level')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_log') . '" name="' . ur_h($p . '[logLevel]') . '">';
    foreach (Job::LOG_LEVELS as $lvl) {
        $sel = ($logLevel === $lvl) ? ' selected' : '';
        echo '<option value="' . ur_h($lvl) . '"' . $sel . '>' . ur_h(ur_t_text(ucfirst($lvl))) . '</option>';
    }
    echo '</select>';
    echo '<blockquote class="inline_help"><p>'
        . ur_h(ur_t_text('Quiet: errors, every deletion, and the end-of-run summary. Summary: adds one overall progress line every 5% or 30 seconds - no per-file lines, so a big backup stays readable. Normal: a line per transferred file. Verbose/Debug: add itemised changes and rsync protocol debugging - for troubleshooting, and they can make the log very large. Errors and deletions are logged at every level, and a Dry-run is never quieter than Normal so the preview always names what it would change.'))
        . '</p></blockquote>';
    echo '</dd>';

    // notify mode
    echo '<dt><label for="' . ur_h($idb . '_notify') . '">' . ur_h(ur_t_text('Notify')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_notify') . '" name="' . ur_h($p . '[notifyMode]') . '">';
    foreach (['off' => 'Off', 'success-only' => 'Success only', 'failure-only' => 'Failure only', 'always' => 'Always'] as $val => $lbl) {
        $sel = ($notifyMode === $val) ? ' selected' : '';
        echo '<option value="' . ur_h($val) . '"' . $sel . '>' . ur_h(ur_t_text($lbl)) . '</option>';
    }
    echo '</select></dd>';

    // optional target marker (empty = off)
    echo '<dt><label for="' . ur_h($idb . '_marker') . '">' . ur_h(ur_t_text('Target marker')) . '</label>:</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_marker') . '" name="' . ur_h($p . '[targetMarker]')
        . '" value="' . ur_h($marker) . '" placeholder="/mnt/disks/backup/.unraid-rsync-target" spellcheck="false">';
    echo ' <input type="text" id="' . ur_h($idb . '_marker_content') . '" name="' . ur_h($p . '[targetMarkerContent]')
        . '" value="' . ur_h($markerText) . '" placeholder="' . ur_h(ur_t_text('expected content (optional)')) . '" spellcheck="false"'
        . ' aria-label="' . ur_h(ur_t_text('Target marker expected content')) . '">';
    echo '<blockquote class="inline_help"><p>'
        . ur_h(ur_t_text('Protects against writing to an unmounted drive. Optional: the path of a small file on the DESTINATION side (on the remote host for a push, on this server for a pull or a local job) that must exist before the run starts - for example a .unraid-rsync-target file in the root of the backup drive. If it is missing, cannot be read, or does not contain the expected content (when one is set, e.g. a UUID), the run fails before the pre-run hook and before anything is copied. Leave empty to turn the check off.'))
        . '</p></blockquote></dd>';

    // snapshot mode. Every field is always rendered (the JS template is this
    // same card) and only shown and enabled while the switch is on: a disabled
    // field is not posted, so a mirror job posts just snapshot[enabled]=0.
    $sp = $p . '[snapshot]';
    echo '<dt>' . ur_h(ur_t_text('Snapshot mode')) . ':</dt>';
    echo '<dd>';
    echo '<input type="hidden" name="' . ur_h($sp . '[enabled]') . '" value="0">';
    echo '<input type="checkbox" class="ur-switch ur-snap-toggle" name="' . ur_h($sp . '[enabled]') . '" value="1"' . ($snapOn ? ' checked' : '') . '>';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('When ON, every run writes a new dated copy (a snapshot) on the receiver instead of updating one mirror. SSH transport and Push only.')) . '</p></blockquote>';
    echo '<div class="ur-snap-only"' . ur_snap_style($snapOn) . '><blockquote class="inline_help" style="display:block;margin:6px 0 0"><p>'
        . ur_h(ur_t_text('A snapshot is a complete, dated copy of every pair under <root>/<stamp>/ on the receiver. Files that did not change since the previous snapshot are hard links to it (rsync --link-dest), so each snapshot only costs the space of what changed. A snapshot counts only once its run finished cleanly; old ones are pruned by the retention below.'))
        . '</p><p>'
        . ur_h(ur_t_text('The target marker and its expected content above become mandatory, and the snapshot root must be the marker\'s directory or lie inside it. You create the root (mode 0700) and its .ur-snapshot-root file holding this job\'s id; the plugin never creates them.'))
        . '</p><p>'
        . ur_h(ur_t_text('The transfer\'s SSH login must be able to read the root as root: either log in as root, or point Remote rsync path at a wrapper on the receiver that runs rsync through sudo, and turn on "Use sudo on the receiver" on the connection.'))
        . '</p><p>'
        . ur_h(ur_t_text('Pruning starts as a dry run: it logs which snapshots it would delete and deletes none until you turn "Prune dry run" off. Unfinished copies left by failed runs are still cleaned up.'))
        . '</p><p><a href="' . ur_h('https://github.com/sasa-fajkovic/unraid-rsync/blob/main/docs/snapshot-mode.md') . '" target="_blank" rel="noopener noreferrer">' . ur_h(ur_t_text('Full guide')) . '</a>'
        . '</p></blockquote></div>';
    echo '</dd>';

    echo '<dt class="ur-snap-only"' . ur_snap_style($snapOn) . '><label for="' . ur_h($idb . '_snaproot') . '">' . ur_h(ur_t_text('Snapshot root')) . '</label>' . ur_required_mark() . ':</dt>';
    echo '<dd class="ur-snap-only"' . ur_snap_style($snapOn) . '><input type="text" class="ur-snap-field" id="' . ur_h($idb . '_snaproot') . '" name="' . ur_h($sp . '[root]')
        . '" value="' . ur_h($snapshot['root']) . '" placeholder="/mnt/backup/snapshots/appdata" spellcheck="false"' . ur_snap_disabled($snapOn) . '>';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('Absolute path on the receiver that holds this job\'s snapshots and nothing else; no other job may write into it. At least two directories deep, three under /mnt.')) . '</p></blockquote></dd>';

    echo '<dt class="ur-snap-only"' . ur_snap_style($snapOn) . '>' . ur_h(ur_t_text('Keep snapshots')) . ':</dt>';
    echo '<dd class="ur-snap-only"' . ur_snap_style($snapOn) . '>';
    foreach (['keepDaily' => 'daily', 'keepWeekly' => 'weekly', 'keepMonthly' => 'monthly'] as $key => $lbl) {
        echo '<label>' . ur_h(ur_t_text($lbl)) . ' <input type="text" class="ur-snap-field" inputmode="numeric" size="4" name="' . ur_h($sp . '[' . $key . ']')
            . '" value="' . ur_h((string) $snapshot[$key]) . '"' . ur_snap_disabled($snapOn) . '></label> ';
    }
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('Keeps the newest snapshot of each of the last N days (0-366), ISO weeks (0-260) and months (0-120) that have one; at least one in total. The newest snapshot is always kept.')) . '</p></blockquote></dd>';

    echo '<dt class="ur-snap-only"' . ur_snap_style($snapOn) . '><label for="' . ur_h($idb . '_snapmax') . '">' . ur_h(ur_t_text('Max deletions per run')) . '</label>:</dt>';
    echo '<dd class="ur-snap-only"' . ur_snap_style($snapOn) . '><input type="text" class="ur-snap-field" inputmode="numeric" size="4" id="' . ur_h($idb . '_snapmax') . '" name="' . ur_h($sp . '[maxPrunePerRun]')
        . '" value="' . ur_h((string) $snapshot['maxPrunePerRun']) . '"' . ur_snap_disabled($snapOn) . '>';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('At most this many old snapshots (1-20) are deleted per run; the rest wait for the next run.')) . '</p></blockquote></dd>';

    echo '<dt class="ur-snap-only"' . ur_snap_style($snapOn) . '>' . ur_h(ur_t_text('Prune dry run')) . ':</dt>';
    echo '<dd class="ur-snap-only"' . ur_snap_style($snapOn) . '>';
    echo '<input type="hidden" class="ur-snap-field" name="' . ur_h($sp . '[pruneDryRun]') . '" value="0"' . ur_snap_disabled($snapOn) . '>';
    echo '<input type="checkbox" class="ur-switch ur-snap-field" name="' . ur_h($sp . '[pruneDryRun]') . '" value="1"' . ($snapshot['pruneDryRun'] ? ' checked' : '') . ur_snap_disabled($snapOn) . '>';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('When ON, each run only logs which snapshots it would delete. Review those plans for a while, then turn it OFF to let pruning delete. Older unfinished .inprogress copies are cleaned up either way.')) . '</p></blockquote></dd>';

    // pre/post hooks. Both run as ROOT via a SINGLE `bash -c "$hook"` call
    // (Runner::runHook) — the whole textarea value is one bash script, so
    // multi-line hooks just execute top-to-bottom in one shell session
    // (a variable set on line 1 is still set on line 5); it is NOT one
    // process per line. The textareas are styled as small code editors
    // (monospace, resizable, no wrap/spellcheck, line-numbered gutter so a
    // multi-line script and a single long one-liner are unambiguous at a
    // glance) and a VISIBLE helper explains both hooks + the outcome env
    // vars + the root/secret-leak warning. (The stock blockquote.inline_help
    // is hidden by default unless help mode is on, so the explanation would
    // otherwise be invisible.)
    $hookEnvVars = 'UR_JOB_ID, UR_JOB_NAME, UR_DRY_RUN, UR_TRIGGER, UR_JOB_STATUS, UR_EXIT_CODE';
    echo '<dt>' . ur_h(ur_t_text('Pre / post-run hooks')) . ':</dt>';
    echo '<dd><div class="ur-hook-help">';
    echo '<p>' . ur_h(ur_t_text('Optional shell commands run on THIS server as root (via bash -c) around the transfer: the pre-run hook just before rsync starts, the post-run hook just after it finishes. The post-run hook ALWAYS runs — even when the transfer fails or is aborted.')) . '</p>';
    echo '<p>' . ur_h(ur_t_text('A hook that runs longer than the pre-/post-run hook timeout on the Global Settings tab (30 and 60 minutes by default) is killed: a timed-out pre-run hook fails the run, a timed-out post-run hook turns a successful run into a warning.')) . '</p>';
    echo '<p>' . ur_h(ur_t_text('Use them to prepare or clean up around a backup — e.g. mount/unmount a share, spin a disk up or down, or send a custom notification. Write it like a small bash script: one command per line, or a single long one-liner — no shebang needed.')) . '</p>';
    echo '<p>' . ur_h(ur_t_text('The whole box runs as ONE shell session, top to bottom, in order: line 1 finishes before line 2 starts, and a variable or `cd` from an earlier line still applies later — just like running a saved .sh file. The numbers on the left mark each line so you can see exactly what runs and in what order.')) . '</p>';
    echo '<p>' . ur_h(ur_t_text('Available to both hooks as environment variables')) . ': <code>' . ur_h($hookEnvVars) . '</code>. '
        . ur_h(ur_t_text('UR_DRY_RUN is 0/1, UR_TRIGGER is manual/schedule; UR_JOB_STATUS and UR_EXIT_CODE hold the run outcome (post-run hook).')) . '</p>';
    echo '<p class="ur-hook-warn">' . ur_h(ur_t_text('Hooks run with full root privileges — be careful what you put here. Their output is captured into the run log (visible in the UI), so do not echo passwords or keys.')) . '</p>';
    echo '</div></dd>';

    $preExample  = ur_t_text("# Runs before the transfer. Example:\n# mount /mnt/remotes/nas-backup");
    $postExample = ur_t_text("# Runs after the transfer (always). Example:\n# [ \"\$UR_JOB_STATUS\" = SUCCESS ] && logger \"backup finished ok\"");
    $preLines    = PageHelpers::hookLineCount($preHook, $preExample);
    $postLines   = PageHelpers::hookLineCount($postHook, $postExample);
    echo '<dt class="ur-hook-dt"><label for="' . ur_h($idb . '_pre') . '">' . ur_h(ur_t_text('Pre-run hook')) . '</label>:</dt>';
    echo '<dd class="ur-hook-dd"><div class="ur-hook-editor">'
        . ur_hook_gutter_html($preLines)
        . '<textarea class="ur-hook-ta" id="' . ur_h($idb . '_pre') . '" name="' . ur_h($p . '[preHook]')
        . '" rows="' . ur_h((string) ($preLines + 1)) . '" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" placeholder="' . ur_h($preExample) . '">' . ur_h($preHook) . '</textarea></div></dd>';
    echo '<dt class="ur-hook-dt"><label for="' . ur_h($idb . '_post') . '">' . ur_h(ur_t_text('Post-run hook')) . '</label>:</dt>';
    echo '<dd class="ur-hook-dd"><div class="ur-hook-editor">'
        . ur_hook_gutter_html($postLines)
        . '<textarea class="ur-hook-ta" id="' . ur_h($idb . '_post') . '" name="' . ur_h($p . '[postHook]')
        . '" rows="' . ur_h((string) ($postLines + 1)) . '" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" placeholder="' . ur_h($postExample) . '">' . ur_h($postHook) . '</textarea></div></dd>';

    echo '</dl>';

    // rsync options block (whitelist), gated visually by the use-global toggle
    echo '<div class="ur-job-opts" id="' . ur_h($idb . '_opts') . '"' . ($useGlobal ? ' style="display:none"' : '') . '>';
    ur_render_rsync_options($opts, $p . '[rsyncOptions]', $idb . '_opts_fields', $transport);
    echo '</div>';

    // Run / Dry-run / Abort actions. These are only meaningful for a saved job
    // (one with an id); the JS-cloned template card has the literal "__IDX__"
    // placeholder and no real id, so its run controls are rendered disabled and
    // the user must save first. A job currently running has Run/Dry-run disabled
    // and Abort enabled (and vice versa); state comes from RunState::isRunning,
    // PID-reuse-safe; the getStatus poll keeps them current after load.
    $hasId   = ($id !== '' && strpos($id, '__IDX__') === false);
    $running = $hasId && RunState::isRunning($id);
    $runDis  = (!$hasId || $running) ? ' disabled' : '';
    $abortDis = (!$hasId || !$running) ? ' disabled' : '';
    echo '<div class="ur-job-card-actions">';
    $jobNameAttr = ' data-jobname="' . ur_h($name !== '' ? $name : $id) . '"';
    echo '<button type="button" class="ur-job-run" data-jobid="' . ur_h($id) . '"' . $jobNameAttr . $runDis . '>' . ur_h(ur_t_text('Run')) . '</button> ';
    echo '<button type="button" class="ur-job-dry" data-jobid="' . ur_h($id) . '"' . $jobNameAttr . $runDis . '>' . ur_h(ur_t_text('Dry-run')) . '</button> ';
    echo '<button type="button" class="ur-job-abort" data-jobid="' . ur_h($id) . '"' . $abortDis . '>' . ur_h(ur_t_text('Abort')) . '</button> ';
    echo '<button type="button" class="ur-job-del">' . ur_h(ur_t_text('Remove job')) . '</button>';
    echo '</div>';
    if (!$hasId) {
        echo '<blockquote class="inline_help"><p>' . ur_h(ur_t_text('Save the job before running it.')) . '</p></blockquote>';
    }
    echo '<div class="ur-job-run-result ur-result" data-jobid="' . ur_h($id) . '"></div>';

    echo '</div>'; // .ur-job-body
    echo '</div>'; // .ur-job-card
}

/**
 * Build the line-number gutter markup for a hook textarea: numbers 1..N.
 */
function ur_hook_gutter_html(int $lines): string
{
    $nums = implode("\n", range(1, $lines));

    return '<div class="ur-hook-gutter" aria-hidden="true">' . ur_h($nums) . '</div>';
}

/**
 * Render a single source->dest pair row. $k is an int row index or "__PIDX__"
 * for the JS template.
 *
 * @param int|string          $k
 * @param array<string,mixed> $pair
 */
function ur_render_pair_row(string $prefix, $k, array $pair, bool $snapOn): void
{
    // Both the source (local) and destination (remote) of a pair are mandatory;
    // mark them required so the browser blocks a half-filled pair before submit
    // (the server re-enforces this and the path guardrails). In snapshot mode
    // the sub name takes the remote's place; whichever one is inactive is
    // disabled, so it is neither validated nor posted.
    $base = $prefix . '[pairs][' . $k . ']';
    echo '<div class="ur-pair-row">';
    echo '<input type="text" name="' . ur_h($base . '[local]') . '" value="' . ur_h((string) ($pair['local'] ?? '')) . '" placeholder="/mnt/user/share/sub/" required>';
    echo ' &rarr; ';
    echo '<input type="text" class="ur-pair-remote" name="' . ur_h($base . '[remote]') . '" value="' . ur_h((string) ($pair['remote'] ?? ''))
        . '" placeholder="/mnt/disk/backup/ or /remote/path/on/host/"' . ($snapOn ? ' disabled style="display:none"' : ' required') . '>';
    echo '<input type="text" class="ur-snap-field ur-snap-only ur-pair-sub" name="' . ur_h($base . '[sub]') . '" value="' . ur_h((string) ($pair['sub'] ?? ''))
        . '" placeholder="' . ur_h(ur_t_text('sub-directory name, e.g. appdata')) . '" spellcheck="false" aria-label="' . ur_h(ur_t_text('Snapshot sub-directory name')) . '"'
        . ($snapOn ? ' required' : ' disabled style="display:none"') . '>';
    echo '<label class="ur-snap-only"' . ur_snap_style($snapOn) . '> <input type="checkbox" class="ur-snap-field" name="' . ur_h($base . '[seed]') . '" value="1"'
        . (!empty($pair['seed']) ? ' checked' : '') . ur_snap_disabled($snapOn) . '> ' . ur_h(ur_t_text('seed')) . '</label>';
    echo ' <button type="button" class="ur-pair-del">&minus;</button>';
    echo '</div>';
}

/** Inline style of a snapshot-only element: hidden in mirror mode. */
function ur_snap_style(bool $snapOn): string
{
    return $snapOn ? '' : ' style="display:none"';
}

/** A snapshot-only control is disabled, so not posted, in mirror mode. */
function ur_snap_disabled(bool $snapOn): string
{
    return $snapOn ? '' : ' disabled';
}

?>

<style>
/* Clear Unraid's fixed bottom status bar (#footer, "Array Started", ~30-40px tall
   + z-index:10000) so the per-job Run / Dry-run / Abort / Remove-job row and the
   Apply button at the foot of the form are never hidden behind it. A bottom
   buffer on the page wrapper reserves space below the overlaid footer. */
.ur-jobs-page { padding-bottom: 90px; }
/* A little breathing room above each job card's action row + the form actions so
   the bottom buttons sit clear of the buffer's edge. */
.ur-job-card-actions { margin-top: 8px; }

/* Collapsible job cards: a clickable summary header with the body collapsed by
   default, so a page with many jobs stays compact. */
.ur-job-card { border: 1px solid var(--border-color, #444); border-radius: 6px; margin: 8px 0; padding: 6px 8px; }
.ur-job-head {
  display: flex; align-items: center; gap: 8px; cursor: pointer;
  padding: 6px 4px; border-radius: 4px; -webkit-user-select: none; user-select: none;
}
.ur-job-head:hover { background: var(--color-tablebody, rgba(127,127,127,0.12)); }
.ur-job-head:focus { outline: 1px solid var(--blue-200, #bce8f1); outline-offset: 1px; }
.ur-job-caret { display: inline-block; font-size: 11px; transition: transform .12s ease-in-out; opacity: 0.8; }
.ur-job-card.ur-open .ur-job-caret { transform: rotate(90deg); }
.ur-job-title { font-weight: bold; }
.ur-job-sub { margin-left: auto; color: var(--color-text-secondary, #888); font-size: 0.85em; white-space: nowrap; }

.ur-snap-status { font-size: 0.85em; color: var(--color-text-secondary, #888); }

/* Live cron reading + field-order legend under the schedule input. */
.ur-cron-human { margin-top: 4px; font-size: 0.9em; font-weight: bold; color: var(--blue-800, #1565c0); min-height: 1.1em; }
.ur-cron-legend { margin-top: 3px; font-size: 0.8em; color: var(--color-text-secondary, #888); }
.ur-cron-legend code { font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
  background: rgba(127,127,127,0.15); border-radius: 3px; padding: 0 4px; }

/* Native-looking slider toggle for the Enabled / Use-global-defaults checkboxes.
   These are plain <input type="checkbox" class="ur-switch"> (preceded by a hidden
   "0" so an unchecked box still POSTs a value at the same name — the round-trip is
   unchanged), so we restyle the checkbox itself with appearance:none into a pill
   slider rather than introducing a wrapper element. Colours pull from the inherited
   dynamix palette (green "on", grey "off") with safe fallbacks under any theme. */
input.ur-switch {
  -webkit-appearance: none; -moz-appearance: none; appearance: none;
  position: relative; display: inline-block; vertical-align: middle;
  width: 40px; height: 20px; margin: 0; padding: 0;
  border-radius: 20px; cursor: pointer;
  background: var(--color-tablebody, #b0b0b0);
  transition: background .15s ease-in-out;
}
input.ur-switch::before {
  content: ""; position: absolute; top: 2px; left: 2px;
  width: 16px; height: 16px; border-radius: 50%;
  background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.3);
  transition: transform .15s ease-in-out;
}
input.ur-switch:checked { background: var(--green, #1c7d3f); }
input.ur-switch:checked::before { transform: translateX(20px); }
input.ur-switch:focus-visible { outline: 2px solid var(--blue-500, #2196f3); outline-offset: 2px; }
input.ur-switch:disabled { opacity: 0.5; cursor: default; }

/* The state-badge palette lives in ur_emit_badge_assets() (_options_form.php),
   shared with the Status / History / Overview tabs. */

.ur-log-modal {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0,0,0,0.5);
  display: none;
}
.ur-log-modal.ur-open { display: block; }
.ur-log-modal-inner {
  position: absolute; top: 6%; left: 50%; transform: translateX(-50%);
  width: 80%; max-width: 1000px; max-height: 84%;
  background: var(--background-color, #1c1c1c);
  border: 1px solid var(--border-color, #444);
  border-radius: 6px;
  padding: 14px 18px;
  display: flex; flex-direction: column;
  box-shadow: 0 8px 30px rgba(0,0,0,0.4);
}
.ur-log-modal-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.ur-log-modal-head .ur-log-title { font-weight: bold; flex: 1; }
.ur-log-pre {
  flex: 1; overflow: auto; margin: 0;
  background: #000; color: #d0d0d0;
  padding: 10px; border-radius: 4px;
  font-family: monospace; font-size: 12px;
  white-space: pre-wrap; word-break: break-word;
  min-height: 240px; max-height: 60vh;
}
/* Confirm dialog (reuses the .ur-log-modal overlay) for destructive actions
   like Remove job. */
.ur-confirm-box {
  position: absolute; top: 28%; left: 50%; transform: translate(-50%, -28%);
  width: min(460px, 92vw);
  background: var(--background-color, #1c1c1c);
  border: 1px solid var(--border-color, #444); border-radius: 6px;
  padding: 18px 20px; box-shadow: 0 8px 30px rgba(0,0,0,0.4);
}
.ur-confirm-box p { margin: 10px 0 0; }
.ur-confirm-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.ur-confirm-danger { background: var(--red-800, #b71c1c); color: #fff; border: none; font-weight: bold; }

/* Inline spinner shown on a Run/Dry-run button the instant it is clicked. */
.ur-spin {
  display: inline-block; width: 12px; height: 12px;
  border: 2px solid currentColor; border-right-color: transparent;
  border-radius: 50%; vertical-align: middle; margin-right: 6px;
  animation: ur-rot 0.7s linear infinite;
}
@keyframes ur-rot { to { transform: rotate(360deg); } }
/* Transient toast confirming a Run/Dry-run/Abort was picked up. Sits above the
   overlaid Unraid footer (bottom:96px). */
.ur-toast {
  position: fixed; right: 16px; bottom: 96px; z-index: 11000;
  max-width: 360px; padding: 10px 14px; border-radius: 4px;
  color: #fff; background: #2e7d32; box-shadow: 0 2px 8px rgba(0,0,0,0.3);
  opacity: 0; transition: opacity 0.3s ease;
}
.ur-toast.ur-toast-err { background: #c62828; }
.ur-toast.ur-show { opacity: 1; }

/* Pre/post hook editors: users paste shell snippets here, so the textareas read
   as small code editors — monospace, spellcheck off, with a line-number gutter
   (.ur-hook-gutter) so a multi-line script and a single long one-liner are
   unambiguous at a glance ("iterating gray line numbers"). Height is FIXED at
   (line count + 1) rows - no drag-resize handle - and kept in sync with the
   content by JS (rows attribute, set server-side on render and client-side on
   every keystroke via syncHookGutter()); width is fixed too, and lines do NOT
   soft-wrap (white-space: pre) — a long one-liner stays on its own numbered
   row and scrolls HORIZONTALLY instead of folding across several visual rows,
   which would otherwise look like several separate lines (and would also
   throw off the fixed-height row count). .ur-hook-dt/.ur-hook-dd are PLAIN
   dt/dd - no grid-column span, no wrapper, nothing - same as every other
   field's, same as the plain intro dt/dd right above them (the "Pre / post-
   run hooks:" help row). That's deliberate, and the product of getting this
   wrong twice: webGui's dl here is CSS GRID (`grid-template-columns: <label
   track> <value track>`), and it was tempting to assume the value track
   must be narrow, forcing .ur-hook-dd to `grid-column: 1 / -1` to "escape"
   it - but that forces the dd onto a SEPARATE row (a dt sitting normally in
   column 1 collides with a dd spanning columns 1 through -1 on the same
   row, so grid auto-placement bumps the dd down), which is exactly the
   "label stacked above the field" bug this fought for six commits. Wrapping
   dt+dd in an extra flex div to get them back onto one row (tried once)
   traded that bug for a worse one: the wrapper has no idea what width the
   dl's real label column is, so the label renders flush against the page's
   left edge instead of aligned under every other field's label. The value
   track was never actually narrow - `git log` for this block turns up a
   commit that live-measured it directly against the real deployed page:
   ~933px, out of a ~1466px full row. The ONLY real narrowing culprit was
   .ur-hook-ta's own `max-width: none` need - webGui ships a generic
   `textarea { max-width: 400px }` (a sane default for ordinary text
   fields) that clamps the box to 400px regardless of how wide its flex
   container actually is, leaving the remaining width as dead space the
   user can't see or scroll into - and that fix stands on its own, doesn't
   need any dt/dd override to work. .ur-hook-editor keeps a max-width: 960px
   ceiling as a defensive cap for very wide screens; it doesn't bind at the
   ~933px natural track width. The accompanying .ur-hook-help is a VISIBLE
   callout (the stock blockquote.inline_help is hidden unless help mode is
   on).

   The palette is a FIXED dark code-editor look, deliberately NOT theme vars: the
   old `color: var(--font-color)` resolved to its #d0d0d0 fallback on Unraid's
   white theme (--font-color is undefined there) while `--background-color` flipped
   to #f2f2f2, leaving light-grey text on a light-grey box — the actual hook
   content was all but invisible. An explicit dark terminal palette reads clearly
   on BOTH the white and black webGui themes. */
.ur-hook-row {
  grid-column: 1 / -1 !important;
  float: none !important; width: auto !important; margin-left: 0 !important; clear: both;
  display: flex; align-items: flex-start; gap: 10px;
}
.ur-hook-dt {
  flex: 0 0 auto; white-space: nowrap;
  padding-top: 8px; /* lines the label up with the editor's first text row */
}
.ur-hook-dd {
  flex: 1 1 auto; min-width: 0; /* let the box shrink below its content's
    width so the textarea's own overflow-x:auto scroller kicks in instead
    of the row stretching the page wide */
  float: none !important; width: auto !important; margin-left: 0 !important;
  display: block;
}
.ur-hook-editor {
  display: flex; align-items: stretch; width: 100%; max-width: 960px; box-sizing: border-box;
  border: 1px solid var(--border-color, #555); border-radius: 4px; overflow: hidden;
  background: #1e1e1e;
}
.ur-hook-editor:focus-within { border-color: var(--blue-500, #2196f3); }
.ur-hook-gutter {
  flex: 0 0 auto; box-sizing: border-box !important; min-width: 2.5em; padding: 8px 8px 8px 0 !important;
  font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
  font-size: 12px !important; line-height: 1.5 !important; white-space: pre; overflow: hidden;
  text-align: right; -webkit-user-select: none; user-select: none;
  color: #6e6e6e !important; background: #242424 !important; border-right: 1px solid #3a3a3a;
}
.ur-hook-ta {
  flex: 1 1 auto; width: auto; min-width: 0; max-width: none !important;
  box-sizing: border-box !important; resize: none !important;
  font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
  /* font-size/line-height/padding are !important - the webGui theme ships a
     generic `textarea { ... }` rule (same one the focus-repaint comment below
     talks about) that otherwise wins on these too, inflating the box past
     what our JS height calc (which reads these back via getComputedStyle -
     see syncHookGutter()) assumes, so the box read as mostly-empty below the
     actual text. Forcing our own metrics keeps JS's measurement and the
     visible box in agreement. Extra right padding (vs left) gives scrolled-to
     the long one-liners breathing room before the border instead of the text
     butting right up against it. */
  font-size: 12px !important; line-height: 1.5 !important; tab-size: 2; -moz-tab-size: 2;
  white-space: pre; overflow-x: auto; overflow-y: hidden;
  /* !important so the dark palette survives FOCUS: the webGui theme ships a
     `textarea:focus` rule that repaints the background light (#e8e8e8) on focus,
     which against our light #e6e6e6 text left the focused box unreadable. An
     !important background/color defeats that non-important focus rule, keeping
     the editor dark and legible whether or not it has focus. */
  background: #1e1e1e !important; color: #e6e6e6 !important;
  border: 0; border-radius: 0; padding: 8px 22px 8px 10px !important;
}
.ur-hook-ta::placeholder { color: #7d7d7d !important; opacity: 1; }
.ur-hook-ta:focus { outline: none; background: #1e1e1e !important; color: #e6e6e6 !important; }
.ur-hook-help {
  margin: 4px 0 10px; padding: 10px 12px; border-radius: 4px;
  background: var(--blue-100, #d9edf7); border: 1px solid var(--blue-200, #bce8f1);
  color: var(--blockquote-text-color, #31708f); font-size: 0.9em;
}
.ur-hook-help p { margin: 0 0 6px; }
.ur-hook-help p:last-child { margin-bottom: 0; }
.ur-hook-help code {
  font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
  background: rgba(127,127,127,0.18); border-radius: 3px; padding: 0 4px; word-break: break-word;
}
.ur-hook-warn { font-weight: bold; }
</style>
<?php /* (the markup below re-opens output) */ ?>
<?php
/* Emit the option help CSS/JS once, in LIVE page-body context, BEFORE any
 * <script type="text/html"> template renders below. On a fresh install (no
 * live job cards) the first ur_render_rsync_options() call would otherwise be
 * the one INSIDE the hidden #ur-job-template, trapping these assets as inert
 * text and leaving the "?" help dead page-wide. Emitting here makes the assets
 * deterministically active; the static guard inside the function then makes the
 * template's and Global Settings' later calls no-ops. */
ur_emit_option_help_assets();
/* The ONE state-badge palette + window.urBadge, shared with the Status /
 * History / Overview tabs. Must precede the badge markup and the script below. */
ur_emit_badge_assets();
/* Emit the shared robust-fetch helpers (window.urAjax) so the run/dry/abort and
 * save AJAX surface a non-JSON 403/500 WITH its HTTP status instead of failing
 * silently in r.json() (the same fix the Credentials page already carries). */
ur_emit_ajax_helpers();
/* Re-enable the plugin's own Apply button once the form is edited (Unraid's
 * framework disables it on load and won't re-enable our custom forms). */
ur_emit_form_enable_assets();
/* UR_TZ + window.urFmtLocal, so the JS-updated cells render in the SERVER's
 * timezone and agree with the PHP-rendered ones above them. */
ur_emit_time_helpers();
/* Shared CSS + window.urUtil, and the ONE getStatus poller (window.urStatus)
 * this tab subscribes to alongside Overview and Status. */
ur_emit_status_poller();
?>
<div class="ur-jobs-page">
<div class="title">
  <span class="left">
    <i class="fa fa-list title"></i>&nbsp;<?=_('Jobs')?>
  </span>
</div>

<?php if ($loadError !== ''): ?>
<div class="ur-result ur-err">
  <?=_('The saved configuration could not be read, so defaults are shown below. Saving is blocked until this is resolved')?>:
  <?=htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8')?>
</div>
<?php endif; ?>

<p>
  <?=_('Define independent rsync backup jobs. Each job has its own schedule and runs one rsync per source -> destination pair (no cartesian product)')?>.
  <?=_('Enabled jobs run automatically on their cron schedule; the Next run column shows when each will fire. You can also Run or Dry-run a job on demand')?>.
  <?=_('The State column shows each job\'s live status (updated automatically while a job runs); use the Logs button to view per-run output. Per-job notifications are sent through Unraid\'s notification system — pick when to notify with each job\'s Notify setting')?>. <?=ur_tz_note()?>
</p>

<!-- Summary list ------------------------------------------------------------>
<?php $urNow = time(); ?>
<table class="tablesorter ur-job-list">
  <thead>
    <tr>
      <th><?=_('Name')?></th>
      <th><?=_('State')?></th>
      <th><?=_('Enabled')?></th>
      <th><?=_('Transport')?></th>
      <th><?=_('Schedule')?></th>
      <th><?=_('Last run')?></th>
      <th><?=_('Next run')?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($jobs)): ?>
      <tr><td colspan="7"><?=_('No jobs configured yet')?>.</td></tr>
    <?php else: foreach ($jobs as $job):
        $job     = is_array($job) ? $job : [];
        $jid     = (string) ($job['id'] ?? '');
        $running = ($jid !== '') ? RunState::isRunning($jid) : false;
        $summary = ($jid !== '') ? Runner::readSummary($jid) : null;
        $lastReal = Runner::lastRealFor($jid, $summary);
        $state   = PageHelpers::deriveState($running, $lastReal);
        $dryLine = PageHelpers::dryRunLine(PageHelpers::lastDryRun($summary), $urNow);
        $snapLast = Config::normalizeSnapshot($job['snapshot'] ?? null) !== null ? Snapshot::lastSnapshot($summary) : null;
    ?>
      <tr data-jobid="<?=htmlspecialchars($jid, ENT_QUOTES, 'UTF-8')?>">
        <td><?=htmlspecialchars((string)($job['name'] ?? ''), ENT_QUOTES, 'UTF-8')?></td>
        <td>
          <span class="ur-badge ur-state-badge <?=htmlspecialchars(ur_state_badge_class($state), ENT_QUOTES, 'UTF-8')?>"
                data-jobid="<?=htmlspecialchars($jid, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars(ur_state_label($state), ENT_QUOTES, 'UTF-8')?></span>
          <?php if ($jid !== ''): ?>
            <button type="button" class="ur-job-viewlog" data-jobid="<?=htmlspecialchars($jid, ENT_QUOTES, 'UTF-8')?>" data-jobname="<?=htmlspecialchars((string)($job['name'] ?? $jid), ENT_QUOTES, 'UTF-8')?>"><?=_('Logs')?></button>
          <?php endif; ?>
        </td>
        <td><?=!empty($job['enabled']) ? _('Yes') : _('No')?></td>
        <td><?=htmlspecialchars((string)($job['transport'] ?? ''), ENT_QUOTES, 'UTF-8')?></td>
        <td><?=!empty($job['manualOnly']) ? _('Manual') : htmlspecialchars((string)($job['schedule'] ?? ''), ENT_QUOTES, 'UTF-8')?></td>
        <td class="ur-last-run-cell"><?=htmlspecialchars(PageHelpers::lastRunLabel($lastReal, $urNow), ENT_QUOTES, 'UTF-8')?><?php if ($dryLine !== ''): ?><div class="ur-dry-run"><?=htmlspecialchars($dryLine, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?><?php if ($snapLast !== null): ?><div class="ur-snap-status" title="<?=htmlspecialchars($snapLast['stamp'], ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($snapLast['label'], ENT_QUOTES, 'UTF-8')?></div><?php endif; ?></td>
        <td class="ur-next-run-cell"><?=htmlspecialchars(PageHelpers::nextRunLabel($job, $urNow), ENT_QUOTES, 'UTF-8')?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<!-- Per-job run log viewer (modal). A run selector (filled from listRuns) + a
     scrollable <pre> fed by getJobLog. The log text is ALREADY HTML-escaped by
     the handler; we inject it with textContent / as-escaped HTML and never build
     raw innerHTML from unescaped bytes. -->
<div id="ur-log-modal" class="ur-log-modal" aria-hidden="true">
  <div class="ur-log-modal-inner" role="dialog" aria-modal="true" aria-labelledby="ur-log-title">
    <div class="ur-log-modal-head">
      <span class="ur-log-title" id="ur-log-title"><?=_('Run log')?></span>
      <label for="ur-log-run-select"><?=_('Run')?>:</label>
      <select id="ur-log-run-select"></select>
      <span id="ur-log-live" class="ur-badge ur-badge-running" style="display:none"><?=_('Live')?></span>
      <button type="button" id="ur-log-close"><?=_('Close')?></button>
    </div>
    <pre id="ur-log-pre" class="ur-log-pre"></pre>
  </div>
</div>

<!-- Confirm dialog for Remove job -------------------------------------------->
<div id="ur-confirm-modal" class="ur-log-modal" aria-hidden="true">
  <div class="ur-confirm-box" role="dialog" aria-modal="true" aria-labelledby="ur-confirm-title">
    <strong id="ur-confirm-title"><?=_('Remove job?')?></strong>
    <p id="ur-confirm-msg"></p>
    <div class="ur-confirm-actions">
      <button type="button" id="ur-confirm-cancel"><?=_('Cancel')?></button>
      <button type="button" id="ur-confirm-ok" class="ur-confirm-danger"><?=_('Remove job')?></button>
    </div>
  </div>
</div>

<!-- CRUD form ---------------------------------------------------------------->
<form markdown="1" method="POST" action="<?=htmlspecialchars($handlerUrl, ENT_QUOTES, 'UTF-8')?>" id="ur-jobs-form" data-ur-action="saveConfig">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8')?>">
  <!-- Sentinel: marks this as a Jobs-tab submission so the handler rebuilds the
       jobs list even when the user has deleted every card (an intentional
       "clear all jobs"), instead of treating it as "Jobs not submitted". -->
  <input type="hidden" name="jobs_present" value="1">
  <input type="hidden" name="configRevision" value="<?=Config::revision($config)?>">

  <div id="ur-jobs-container">
    <?php foreach ($jobs as $i => $job) {
        ur_render_job_card($job, (int) $i);
    } ?>
  </div>

  <div class="ur-actions">
    <button type="button" id="ur-add-job"><?=_('Add job')?></button>
    <input type="submit" value="<?=_('Apply')?>">
  </div>
</form>

<div id="ur-jobs-result" class="ur-result"></div>
</div><!-- .ur-jobs-page -->

<!-- Hidden templates for JS cloning. Index placeholders are rewritten on add.
     Rendered with htmlspecialchars too; only the literal placeholders are
     swapped client-side. The new-job template is seeded from the user's saved
     Global Settings (global.defaultRsyncOptions) so a new job starts from the
     configured defaults, not the built-in ones. -->
<script type="text/html" id="ur-job-template">
<?php
    $templateJob = Config::defaultJob();
    $templateJob['rsyncOptions'] = $globalOpts;      // seed from Global Settings
    $templateJob['useGlobalDefaults'] = true;        // new jobs default to "Use global config" ON
    ur_render_job_card($templateJob, '__IDX__');
?>
</script>
<script type="text/html" id="ur-pair-template">
<?php ur_render_pair_row('jobs[__IDX__]', '__PIDX__', [], false); ?>
</script>

<script type="text/javascript">
(function () {
  'use strict';

  /* Next free top-level job index: max existing index + 1, so newly-added
   * cards never collide with edited ones. */
  function nextJobIndex() {
    var cards = document.querySelectorAll('#ur-jobs-container .ur-job-card');
    var max = -1;
    cards.forEach(function (c) {
      var n = parseInt(c.getAttribute('data-index'), 10);
      if (!isNaN(n) && n > max) { max = n; }
    });
    return max + 1;
  }

  /* Next free pair index within a given pairs container: max existing index + 1.
   * Using the row COUNT would reuse an index after a middle row is deleted,
   * producing duplicate pairs[<k>] names that PHP would silently collapse. We
   * parse the [pairs][<k>] index out of each row's first input name instead. */
  function nextPairIndex(rowsEl) {
    var rows = rowsEl.querySelectorAll('.ur-pair-row');
    var max = -1;
    rows.forEach(function (row) {
      var input = row.querySelector('input[name]');
      if (!input) { return; }
      var m = input.name.match(/\[pairs\]\[(\d+)\]/);
      if (m) {
        var n = parseInt(m[1], 10);
        if (!isNaN(n) && n > max) { max = n; }
      }
    });
    return max + 1;
  }

  function addJob() {
    var tpl = document.getElementById('ur-job-template').innerHTML;
    var idx = nextJobIndex();
    /* Replace every "__IDX__" placeholder (in names, ids, data-index) with the
     * real index. Pair rows inside start at pair index 0. */
    var html = tpl.split('__IDX__').join(String(idx)).split('__PIDX__').join('0');
    var wrap = document.createElement('div');
    wrap.innerHTML = html.trim();
    var card = wrap.firstElementChild;
    document.getElementById('ur-jobs-container').appendChild(card);
    /* A cloned card defaults to SSH transport, so seed its Connection-required
     * state to match. */
    syncAllConnRequired();
    syncAllManualOnly();
    syncAllCronHuman();
    syncAllHookGutters();
    syncSnapshot(card);
    /* Filter reorder arrows + the "implied by -a" group in the cloned card
     * (owned by the shared options partial; its listeners are delegated, only
     * this initial state needs a nudge). */
    if (window.urOptionsForm) { window.urOptionsForm.sync(); }
    /* A just-added card starts EXPANDED (the user wants to fill it in) and gets
     * focus on its name field. */
    setCardOpen(card, true);
    var nameInput = card.querySelector('input[name$="[name]"]');
    if (nameInput && nameInput.focus) { nameInput.focus(); }
  }

  function addPair(rowsEl) {
    var prefix = rowsEl.getAttribute('data-prefix'); // e.g. jobs[2][pairs]
    var tpl = document.getElementById('ur-pair-template').innerHTML;
    var pidx = nextPairIndex(rowsEl);
    /* The pair template uses jobs[__IDX__][pairs][__PIDX__]; rewrite both: the
     * job prefix comes from this container, the pair index is the next slot. */
    var html = tpl
      .split('jobs[__IDX__][pairs]').join(prefix)
      .split('__PIDX__').join(String(pidx));
    var wrap = document.createElement('div');
    wrap.innerHTML = html.trim();
    var row = wrap.firstElementChild;
    rowsEl.appendChild(row);
    /* The template row is a mirror row; match the card's mode. */
    syncSnapshot(rowsEl.closest('.ur-job-card'));
  }

  /* Filter rows (add / remove / reorder) are handled by the shared options
   * partial's delegated listeners, so there is no per-page row builder here. */

  var HANDLER_URL = <?=ur_js($handlerUrl)?>;
  var CSRF_TOKEN  = <?=ur_js($csrf)?>;

  /* Fire a run/dry-run/abort action for a job and show the result inline next
   * to its card. Fire-and-confirm: the response just acknowledges the launch.
   * Instead of a full page reload, we kick the shared status poll so badges and
   * the Run/Dry/Abort enable state update live. */
  /* Lightweight transient toast (no dependency). Auto-removes after ~3.5s. */
  function urToast(msg, ok) {
    var t = document.createElement('div');
    /* Default .ur-toast styling is the success (green) look; only the error
     * variant needs an extra class. */
    t.className = 'ur-toast' + (ok ? '' : ' ur-toast-err');
    t.setAttribute('role', 'status');
    t.textContent = msg;
    document.body.appendChild(t);
    /* next frame -> fade in */
    requestAnimationFrame(function () { t.classList.add('ur-show'); });
    setTimeout(function () {
      t.classList.remove('ur-show');
      setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 350);
    }, 3500);
  }

  /* Optimistically paint a job's badge RUNNING the instant a launch is accepted;
   * the shared status poll then confirms or corrects it. */
  function setBadgeRunning(jobId) {
    var badges = document.querySelectorAll('.ur-state-badge[data-jobid="' + window.urUtil.cssEsc(jobId) + '"]');
    badges.forEach(function (b) { window.urBadge.apply(b, 'RUNNING'); });
  }

  function postJobAction(action, jobId, btn) {
    if (!jobId) { return; }
    var isLaunch = (action === 'runJob' || action === 'dryRunJob');
    var card = btn && btn.closest ? btn.closest('.ur-job-card') : null;
    var result = card ? card.querySelector('.ur-job-run-result') : null;
    /* Immediate, obvious feedback that the click was picked up: spinner on the
     * clicked button (its label cached so we can restore it), button disabled.
     * Built as nodes - the label is never re-parsed as HTML. */
    var prevLabel = null;
    if (btn) {
      prevLabel = btn.textContent;
      btn.disabled = true;
      var spin = document.createElement('span');
      spin.className = 'ur-spin';
      spin.setAttribute('aria-hidden', 'true');
      btn.insertBefore(spin, btn.firstChild);
    }
    /* Uses the shared robust text->JSON parse (window.urAjax) so a non-JSON
     * 403/500 from the front controller surfaces WITH its HTTP status instead of
     * throwing inside r.json() and showing a generic "Network error". This never
     * rejects, so the result line is ALWAYS updated. */
    window.urAjax.postForm(HANDLER_URL, { action: action, id: jobId }, CSRF_TOKEN)
      .then(function (res) {
        var okLaunch = !!(res.ok && res.body && res.body.ok);
        if (result) {
          if (okLaunch) {
            result.className = 'ur-job-run-result ur-result ur-ok';
            result.textContent = res.body.message || 'Done.';
          } else {
            result.className = 'ur-job-run-result ur-result ur-err';
            result.textContent = window.urAjax.errText(res, 'Action failed.');
          }
        }
        /* Restore the clicked button's label. On a failed launch re-enable it
         * (the job didn't start); on success the status poll re-derives the
         * enable state. */
        if (btn) {
          if (prevLabel !== null) { btn.textContent = prevLabel; }
          if (!okLaunch) { btn.disabled = false; }
        }
        if (okLaunch) {
          if (isLaunch) {
            /* Optimistic RUNNING badge + a toast + jump straight to the live log
             * so the user sees it pick up without hunting for the Logs button. */
            setBadgeRunning(jobId);
            urToast(action === 'dryRunJob' ? 'Dry-run started.' : 'Run started.', true);
            var jobName = (btn && btn.getAttribute('data-jobname')) || jobId;
            openLogViewer(jobId, jobName);
          } else {
            urToast(res.body.message || 'Done.', true);
          }
        } else {
          urToast(window.urAjax.errText(res, 'Action failed.'), false);
        }
        /* Poll now, and fast for a while, so the badge + buttons pick up the
         * new running state before the runner has even registered. */
        window.urStatus.refresh();
      });
  }

  /* ---- collapsible job cards ---- */
  function setCardOpen(card, open) {
    if (!card) { return; }
    var body = card.querySelector('.ur-job-body');
    var head = card.querySelector('.ur-job-head');
    card.classList.toggle('ur-open', open);
    if (body) { body.hidden = !open; }
    if (head) { head.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  }
  function toggleCard(card) {
    if (card) { setCardOpen(card, !card.classList.contains('ur-open')); }
  }

  /* ---- Remove-job confirmation modal ----
   * Removing a card drops the job (it is deleted from config on the next Apply),
   * so it must be confirmed explicitly rather than on a single stray click. */
  var confirmModal = document.getElementById('ur-confirm-modal');
  var confirmMsg   = document.getElementById('ur-confirm-msg');
  var pendingDelCard = null;

  function askRemoveJob(card) {
    pendingDelCard = card;
    var nameInput = card.querySelector('input[name$="[name]"]');
    var name = (nameInput && nameInput.value.trim()) || '';
    confirmMsg.textContent = name
      ? ('Remove the job "' + name + '"? It is deleted permanently when you click Apply.')
      : 'Remove this job? It is deleted permanently when you click Apply.';
    confirmModal.classList.add('ur-open');
    confirmModal.setAttribute('aria-hidden', 'false');
    var ok = document.getElementById('ur-confirm-ok');
    if (ok && ok.focus) { ok.focus(); }
  }
  function closeConfirm() {
    confirmModal.classList.remove('ur-open');
    confirmModal.setAttribute('aria-hidden', 'true');
    pendingDelCard = null;
  }
  if (confirmModal) {
    document.getElementById('ur-confirm-cancel').addEventListener('click', closeConfirm);
    document.getElementById('ur-confirm-ok').addEventListener('click', function () {
      if (pendingDelCard && pendingDelCard.parentNode) {
        pendingDelCard.parentNode.removeChild(pendingDelCard);
      }
      closeConfirm();
    });
    /* Backdrop click closes (only when the press STARTED on the backdrop). */
    var cDown = false;
    confirmModal.addEventListener('mousedown', function (ev) { cDown = (ev.target === confirmModal); });
    confirmModal.addEventListener('click', function (ev) {
      if (ev.target === confirmModal && cDown) { closeConfirm(); }
      cDown = false;
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && confirmModal.classList.contains('ur-open')) { closeConfirm(); }
    });
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.classList) { return; }

    /* A click anywhere on the collapsible header toggles that card. Buttons in
     * the body have their own handlers below and are never inside the header. */
    var head = t.closest ? t.closest('.ur-job-head') : null;
    if (head) { toggleCard(head.closest('.ur-job-card')); return; }

    if (t.id === 'ur-add-job') {
      addJob();
    } else if (t.classList.contains('ur-pair-add')) {
      var pr = document.getElementById(t.getAttribute('data-rows'));
      if (pr) { addPair(pr); }
    } else if (t.classList.contains('ur-pair-del')) {
      var prow = t.closest ? t.closest('.ur-pair-row') : null;
      if (prow && prow.parentNode) { prow.parentNode.removeChild(prow); }
    } else if (t.classList.contains('ur-job-run')) {
      postJobAction('runJob', t.getAttribute('data-jobid'), t);
    } else if (t.classList.contains('ur-job-dry')) {
      postJobAction('dryRunJob', t.getAttribute('data-jobid'), t);
    } else if (t.classList.contains('ur-job-abort')) {
      postJobAction('abortJob', t.getAttribute('data-jobid'), t);
    } else if (t.classList.contains('ur-job-viewlog')) {
      openLogViewer(t.getAttribute('data-jobid'), t.getAttribute('data-jobname') || t.getAttribute('data-jobid'));
    } else if (t.id === 'ur-log-close') {
      closeLogViewer();
    } else if (t.classList.contains('ur-job-del')) {
      var card = t.closest ? t.closest('.ur-job-card') : null;
      if (card) { askRemoveJob(card); }
    }
    /* ur-row-add / -del / -up / -down (the filter rules) are handled by the
     * shared options partial, which owns that markup. */
  });

  /* Toggle the Connection select's `required` attribute + its visual marker to
   * match the chosen transport: SSH and rsync-daemon jobs need a connection,
   * LOCAL does not. Expressed as "not LOCAL" (rather than a list of the remote
   * transports) so this stays in lockstep with the server rule in Job::validate
   * and with $connRequired above - a LOCAL job is never blocked and a remote job
   * without a connection is caught client-side too. Driven off the transport
   * select (which carries data-conn). */
  function syncConnRequired(transportSel) {
    if (!transportSel) { return; }
    var conn = document.getElementById(transportSel.getAttribute('data-conn'));
    if (!conn) { return; }
    var needsConn = (transportSel.value !== 'LOCAL');
    conn.required = needsConn;
    /* The marker lives in the same card; find it relative to the connection. */
    var card = conn.closest ? conn.closest('.ur-job-card') : null;
    var mark = card ? card.querySelector('.ur-conn-required') : null;
    if (mark) { mark.style.display = needsConn ? '' : 'none'; }
  }

  /* Toggle a job's options block when "use global defaults" changes, and keep
   * the Connection-required state in sync when the transport changes. */
  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!t || !t.classList) { return; }
    if (t.classList.contains('ur-use-global')) {
      var target = document.getElementById(t.getAttribute('data-target'));
      if (target) { target.style.display = t.checked ? 'none' : ''; }
    } else if (t.classList.contains('ur-transport-select')) {
      syncConnRequired(t);
    } else if (t.classList.contains('ur-manual-only')) {
      syncManualOnly(t);
    } else if (t.classList.contains('ur-snap-toggle')) {
      syncSnapshot(t.closest('.ur-job-card'));
    }
  });

  /* Snapshot mode switch: show + enable the snapshot fields and each pair's sub
   * name and seed, and hide + disable the remote path, or the reverse. A
   * disabled control is neither validated nor posted, so `required` only bites
   * on the visible one. Mirrors ur_render_job_card()'s server-seeded state. */
  function syncSnapshot(card) {
    if (!card) { return; }
    var toggle = card.querySelector('.ur-snap-toggle');
    var on = !!(toggle && toggle.checked);
    Array.prototype.forEach.call(card.querySelectorAll('.ur-snap-only'), function (el) { el.style.display = on ? '' : 'none'; });
    Array.prototype.forEach.call(card.querySelectorAll('.ur-snap-field'), function (el) { el.disabled = !on; });
    Array.prototype.forEach.call(card.querySelectorAll('.ur-pair-sub'), function (el) { el.required = on; });
    Array.prototype.forEach.call(card.querySelectorAll('.ur-pair-remote'), function (el) {
      el.style.display = on ? 'none' : '';
      el.disabled = on;
      el.required = !on;
    });
  }
  function syncAllSnapshot() {
    Array.prototype.forEach.call(document.querySelectorAll('#ur-jobs-container .ur-job-card'), syncSnapshot);
  }

  /* Manual-only toggle: hide the schedule row and drop its `required` when ON (a
   * hidden required field is "not focusable" and would block Apply), restore when
   * OFF. Mirrors the server-seeded initial state. */
  function syncManualOnly(cb) {
    if (!cb || !cb.getAttribute) { return; }
    var idb = cb.getAttribute('data-idb');
    if (!idb) { return; }
    var on = cb.checked;
    ['_schedrow_dt', '_schedrow_dd'].forEach(function (s) {
      var el = document.getElementById(idb + s);
      if (el) { el.style.display = on ? 'none' : ''; }
    });
    var input = document.getElementById(idb + '_schedule');
    if (input) { input.required = !on; }
    var dt = document.getElementById(idb + '_schedrow_dt');
    var mark = dt ? dt.querySelector('.ur-sched-required') : null;
    if (mark) { mark.style.display = on ? 'none' : ''; }
  }
  function syncAllManualOnly() {
    var cbs = document.querySelectorAll('.ur-manual-only');
    Array.prototype.forEach.call(cbs, syncManualOnly);
  }

  /* Line-number gutter + auto-height for the pre/post hook editors
   * (.ur-hook-ta). The gutter is the textarea's immediate previous sibling
   * inside .ur-hook-editor (see ur_hook_gutter_html() in jobs.php); no
   * id-matching needed. Line count comes from the LIVE value once the user
   * has typed anything; while the field is still empty, it falls back to the
   * placeholder text so an untouched field still shows a sensible gutter
   * (matching the server-rendered initial paint). The textarea has no
   * drag-resize handle (CSS `resize: none`) - height is FIXED at
   * (line count + 1) rows and only ever changes here, as content is typed or
   * deleted, so it always exactly fits what's actually in the box.
   *
   * Height is set as an explicit inline pixel height, NOT left to the native
   * `rows` attribute alone: the `rows`-based intrinsic size is computed by
   * the browser from the textarea's OWN metrics, so if anything upstream
   * (webGui's own textarea CSS, a browser quirk) ever wins over one of our
   * font-size/line-height/padding rules despite the !important guards, `rows`
   * silently produces a box taller than the real text - inline height, read
   * back from the SAME getComputedStyle() the browser itself would use, self-
   * corrects instead of drifting out of sync. */
  function urHookGutterFor(ta) {
    var prev = ta.previousElementSibling;
    return (prev && prev.classList.contains('ur-hook-gutter')) ? prev : null;
  }
  function syncHookGutter(ta) {
    var gutter = urHookGutterFor(ta);
    var basis = ta.value !== '' ? ta.value : (ta.getAttribute('placeholder') || '');
    /* Trim trailing blank lines before counting - see PageHelpers::hookLineCount()
     * for why: the +1 buffer row below already covers that space, so
     * counting a saved trailing "\n" too would double up on blank rows. */
    var trimmed = basis.replace(/\n+$/, '');
    var n = trimmed === '' ? 1 : trimmed.split('\n').length;
    if (gutter) {
      var nums = [];
      for (var i = 1; i <= n; i++) { nums.push(i); }
      gutter.textContent = nums.join('\n');
      gutter.scrollTop = ta.scrollTop;
    }
    var rows = n + 1;
    ta.rows = rows;
    var cs = window.getComputedStyle(ta);
    var lineHeight = parseFloat(cs.lineHeight);
    if (isNaN(lineHeight)) { lineHeight = parseFloat(cs.fontSize) * 1.2; }
    var boxExtra = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom)
      + parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth);
    ta.style.height = Math.ceil(rows * lineHeight + boxExtra) + 'px';
  }
  function syncAllHookGutters() {
    var tas = document.querySelectorAll('.ur-hook-ta');
    Array.prototype.forEach.call(tas, syncHookGutter);
  }
  /* 'scroll' does not bubble, so this listener must run in the CAPTURE phase
   * to catch it from any .ur-hook-ta via delegation (including cloned cards
   * added after load). */
  document.addEventListener('scroll', function (ev) {
    var t = ev.target;
    if (t && t.classList && t.classList.contains('ur-hook-ta')) {
      var gutter = urHookGutterFor(t);
      if (gutter) { gutter.scrollTop = t.scrollTop; }
    }
  }, true);

  /* Keyboard toggle for the collapsible header (it is a role=button). */
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' && ev.key !== ' ' && ev.key !== 'Spacebar') { return; }
    var head = ev.target && ev.target.classList && ev.target.classList.contains('ur-job-head') ? ev.target : null;
    if (!head) { return; }
    ev.preventDefault();
    toggleCard(head.closest('.ur-job-card'));
  });

  /* Live-update a card's collapsed header title as its Name field is typed, and
   * the plain-English cron reading as the Schedule field is typed. */
  document.addEventListener('input', function (ev) {
    var t = ev.target;
    if (!t || !t.name) { return; }
    if (/\[name\]$/.test(t.name)) {
      var card = t.closest ? t.closest('.ur-job-card') : null;
      var title = card ? card.querySelector('.ur-job-title') : null;
      if (title) { title.textContent = (t.value || '').trim() || '(unnamed job)'; }
    } else if (t.classList && t.classList.contains('ur-cron-input')) {
      updateCronHuman(t);
    } else if (t.classList && t.classList.contains('ur-hook-ta')) {
      syncHookGutter(t);
    }
  });

  /* Translate a 5-field cron expression into a short plain-English phrase. Covers
   * the common shapes (numbers, asterisk, step values, ranges, lists) and falls
   * back to a field-by-field reading. A reading only, never a verdict: whether
   * the expression is valid is Cron::parseExpression's call on save. */
  function describeCron(expr) {
    expr = (expr || '').trim().replace(/\s+/g, ' ');
    var f = expr.split(' ');
    if (expr === '' || f.length !== 5) { return ''; }
    var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var MON = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var min = f[0], hour = f[1], dom = f[2], mon = f[3], dow = f[4];
    var isNum = function (x) { return /^\d+$/.test(x); };
    var pad = function (n) { n = parseInt(n, 10); return (n < 10 ? '0' : '') + n; };

    var timeStr;
    if (isNum(min) && isNum(hour)) { timeStr = 'at ' + pad(hour) + ':' + pad(min); }
    else if (min === '*' && hour === '*') { timeStr = 'every minute'; }
    else if (/^\*\/\d+$/.test(min) && hour === '*') { timeStr = 'every ' + min.split('/')[1] + ' minutes'; }
    else if (isNum(min) && hour === '*') { timeStr = 'at minute ' + (+min) + ' of every hour'; }
    else if ((min === '0') && /^\*\/\d+$/.test(hour)) { timeStr = 'every ' + hour.split('/')[1] + ' hours'; }
    else { timeStr = 'minute ' + min + ', hour ' + hour; }

    function names(field, arr) {
      return field.split(',').map(function (part) {
        var m;
        if ((m = part.match(/^(\d+)-(\d+)$/))) { return (arr[+m[1]] || m[1]) + '–' + (arr[+m[2]] || m[2]); }
        if (isNum(part)) { return arr[+part] || part; }
        return part;
      }).join(', ');
    }

    var parts = [];
    if (dow !== '*') {
      var dn = dow.replace(/7/g, '0');
      parts.push(/^\d+([,-]\d+)*$/.test(dn) ? ('on ' + names(dn, DOW)) : ('on weekday ' + dow));
    }
    if (dom !== '*') { parts.push(isNum(dom) ? ('on day ' + (+dom)) : ('on day-of-month ' + dom)); }
    if (mon !== '*') { parts.push(/^\d+([,-]\d+)*$/.test(mon) ? ('in ' + names(mon, MON)) : ('in month ' + mon)); }

    var everyDay = (dom === '*' && dow === '*');
    var text;
    if (/^every /.test(timeStr) && everyDay) { text = timeStr; }
    else if (everyDay) { text = timeStr + ' every day'; }
    else { text = timeStr + ' ' + parts.join(' '); }
    return text.charAt(0).toUpperCase() + text.slice(1) + '.';
  }

  function updateCronHuman(input) {
    var idb = input.id.replace(/_schedule$/, '');
    var out = document.getElementById(idb + '_cronhuman');
    if (!out) { return; }
    out.textContent = describeCron(input.value);
  }
  function syncAllCronHuman() {
    var ins = document.querySelectorAll('.ur-cron-input');
    Array.prototype.forEach.call(ins, updateCronHuman);
  }

  /* Seed the Connection-required state on load for every existing card (the
   * server already set it for the initial transport, but a JS-cloned new card
   * starts as SSH and must reflect that). Re-run after a card is added. */
  function syncAllConnRequired() {
    var sels = document.querySelectorAll('.ur-transport-select');
    Array.prototype.forEach.call(sels, syncConnRequired);
  }

  /* Submit via fetch; show validation errors/warnings inline. Uses the shared
   * robust text->JSON parse (window.urAjax) so a non-JSON 403/500 from the front
   * controller becomes a VISIBLE error WITH its HTTP status instead of a silent
   * failure inside r.json(). */
  var form = document.getElementById('ur-jobs-form');
  if (form) {
    /* Before the browser validates on Apply, expand any COLLAPSED card that
     * holds an invalid (e.g. empty required) field. A required control inside a
     * display:none region is "not focusable", so native validation would block
     * the submit with no visible bubble. Clicking the submit button fires this
     * BEFORE validation, so the offending card is open and focusable in time. */
    var submitBtn = form.querySelector('input[type=submit], button[type=submit]');
    if (submitBtn) {
      submitBtn.addEventListener('click', function () {
        var cards = document.querySelectorAll('#ur-jobs-container .ur-job-card');
        Array.prototype.forEach.call(cards, function (card) {
          if (!card.classList.contains('ur-open')) {
            var body = card.querySelector('.ur-job-body');
            if (body && body.querySelector(':invalid')) { setCardOpen(card, true); }
          }
        });
      });
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var result = document.getElementById('ur-jobs-result');
      window.urAjax.show(result, true, 'Saving…');
      window.urAjax.postFormElement(form).then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          var msg = res.body.message || 'Saved.';
          if (res.body.warnings && res.body.warnings.length) {
            msg += ' (' + res.body.warnings.join('; ') + ')';
          }
          window.urAjax.show(result, true, msg);
          /* Reload so the summary table reflects the saved state; suppressing it
           * on a warning would leave the table stale on every save of a job
           * whose remote path legitimately warns (/data, /backup, ...). */
          window.urUtil.reloadSoon(!!(res.body.warnings && res.body.warnings.length));
        } else {
          window.urAjax.show(result, false, window.urAjax.errText(res, 'Save failed.'));
        }
      });
    });
  }

  /* ===================================================================
   * Live status polling + per-run log viewer.
   * =================================================================== */

  var U = window.urUtil;

  /* The Last-run / Next-run cells of one summary row, from a getStatus entry. */
  function renderTimes(jobId, s, now) {
    var row = document.querySelector('table.ur-job-list tr[data-jobid="' + U.cssEsc(jobId) + '"]');
    if (!row) { return; }
    var cell = row.querySelector('.ur-last-run-cell');
    if (cell) {
      /* The badge's run: a dry run is the neutral second line. */
      cell.textContent = U.lastRunLabel(s.lastReal, now);
      if (s.lastDryRun) {
        var dryLine = document.createElement('div');
        dryLine.className = 'ur-dry-run';
        dryLine.textContent = U.dryRunLine(s.lastDryRun, now);
        cell.appendChild(dryLine);
      }
      /* Snapshot jobs only: the label is built server-side in the system
       * timezone, with the UTC stamp on hover. */
      if (s.snapshot) {
        var snapLine = document.createElement('div');
        snapLine.className = 'ur-snap-status';
        snapLine.title = s.snapshot.stamp;
        snapLine.textContent = s.snapshot.label;
        cell.appendChild(snapLine);
      }
    }
    var nextCell = row.querySelector('.ur-next-run-cell');
    if (nextCell) { nextCell.textContent = U.nextRunLabel(s, now); }
  }

  function renderAllTimes(payload, now) {
    if (!payload || !payload.jobs) { return; }
    Object.keys(payload.jobs).forEach(function (jobId) {
      if (payload.jobs[jobId]) { renderTimes(jobId, payload.jobs[jobId], now); }
    });
  }

  /* Apply a getStatus payload: badge, time cells and the Run/Dry/Abort enable
   * state on each job's card. */
  function applyStatus(payload) {
    var now = window.urStatus.now();
    Object.keys(payload.jobs).forEach(function (jobId) {
      var s = payload.jobs[jobId];
      if (!s) { return; }
      var sel = '[data-jobid="' + U.cssEsc(jobId) + '"]';
      document.querySelectorAll('.ur-state-badge' + sel).forEach(function (b) { window.urBadge.apply(b, s.state); });
      renderTimes(jobId, s, now);
      var run   = document.querySelector('.ur-job-run' + sel);
      var dry   = document.querySelector('.ur-job-dry' + sel);
      var abort = document.querySelector('.ur-job-abort' + sel);
      if (run)   { run.disabled = !!s.running; }
      if (dry)   { dry.disabled = !!s.running; }
      if (abort) { abort.disabled = !s.running; }
    });
  }

  window.urStatus.subscribe(applyStatus);
  /* Relative times move every second from the last payload, without a request. */
  setInterval(function () {
    if (!document.hidden) { renderAllTimes(window.urStatus.last(), window.urStatus.now()); }
  }, 1000);

  /* ---- per-run log viewer ---- */
  var viewer = {
    jobId: '', run: '', running: false, timer: null
  };
  var modal      = document.getElementById('ur-log-modal');
  var modalTitle = document.getElementById('ur-log-title');
  var modalPre   = document.getElementById('ur-log-pre');
  var modalSel   = document.getElementById('ur-log-run-select');
  var modalLive  = document.getElementById('ur-log-live');
  var modalClose = document.getElementById('ur-log-close');
  /* Element focused before the dialog opened, so focus can be restored on close
   * (basic focus management for the role="dialog" log viewer). */
  var modalReturnFocus = null;

  function openLogViewer(jobId, jobName) {
    if (!jobId || !modal) { return; }
    viewer.jobId = jobId;
    viewer.run = '';
    if (modalTitle) { modalTitle.textContent = 'Run log — ' + jobName; }
    if (modalPre) { modalPre.textContent = 'Loading…'; }
    /* Remember what to restore focus to, then move focus into the dialog (the
     * Close button) so keyboard users land inside it. */
    modalReturnFocus = (document.activeElement && document.activeElement.focus)
      ? document.activeElement : null;
    modal.classList.add('ur-open');
    modal.setAttribute('aria-hidden', 'false');
    if (modalClose && modalClose.focus) { modalClose.focus(); }
    refreshRunList(function () {
      fetchJobLog();        // load the latest/selected run
      startLogTail();
    });
  }
  function closeLogViewer() {
    if (modal) {
      modal.classList.remove('ur-open');
      modal.setAttribute('aria-hidden', 'true');
    }
    stopLogTail();
    viewer.jobId = '';
    /* Restore focus to the control that opened the dialog. */
    if (modalReturnFocus && modalReturnFocus.focus) { modalReturnFocus.focus(); }
    modalReturnFocus = null;
  }

  function refreshRunList(cb) {
    fetch(HANDLER_URL + '?action=listRuns&id=' + encodeURIComponent(viewer.jobId) + '&limit=10',
      { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (body) {
        if (!modalSel) { if (cb) { cb(); } return; }
        var prev = viewer.run;
        modalSel.innerHTML = '';
        var runs = (body && body.runs) ? body.runs : [];
        runs.forEach(function (run, idx) {
          var opt = document.createElement('option');
          opt.value = run.id;            // run-<stamp>.log (server-whitelisted)
          /* Label from run.ts (a UTC epoch listRuns() already returns) rendered
           * in the server timezone. The run.id stamp is UTC by design, so
           * showing it raw put a third timezone in front of the user. */
          var lbl = run.ts ? window.urFmtLocal(run.ts, true)
                           : run.id.replace(/^run-/, '').replace(/\.log$/, '');
          if (run.state) { lbl += '  [' + run.state + ']'; }
          if (idx === 0) { lbl += '  (latest)'; }
          opt.textContent = lbl;          // textContent: never raw innerHTML
          opt.title = run.id;             // raw id still reachable for support
          modalSel.appendChild(opt);
        });
        /* Default to the latest run unless the user had picked one still listed. */
        if (prev && Array.prototype.some.call(modalSel.options, function (o) { return o.value === prev; })) {
          modalSel.value = prev;
        } else if (modalSel.options.length) {
          modalSel.selectedIndex = 0;
          viewer.run = '';              // '' => server serves latest
        }
        if (cb) { cb(); }
      })
      .catch(function () { if (cb) { cb(); } });
  }

  function fetchJobLog() {
    if (!viewer.jobId) { return Promise.resolve(); }
    var sel = (modalSel && modalSel.options.length && modalSel.selectedIndex > 0)
      ? modalSel.value : '';
    var url = HANDLER_URL + '?action=getJobLog&id=' + encodeURIComponent(viewer.jobId);
    if (sel) { url += '&run=' + encodeURIComponent(sel); }
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (body) {
        if (!body || !body.ok) { return; }
        viewer.running = !!body.running;
        if (modalLive) { modalLive.style.display = body.running ? '' : 'none'; }
        if (modalPre) {
          /* body.log is ALREADY HTML-escaped server-side (Logger::tail). Inject
           * it as escaped HTML so a <pre> renders it verbatim; we DO NOT build
           * innerHTML from unescaped bytes. */
          var atBottom = (modalPre.scrollTop + modalPre.clientHeight) >= (modalPre.scrollHeight - 20);
          modalPre.innerHTML = body.log || '';
          if (atBottom) { modalPre.scrollTop = modalPre.scrollHeight; }
        }
      })
      .catch(function () { /* transient */ });
  }

  function startLogTail() {
    stopLogTail();
    /* Auto-tail every 1s while THIS run is running. We always fetch once
     * (already done by openLogViewer) then poll; the poll stops itself when the
     * run is no longer running. */
    viewer.timer = setInterval(function () {
      if (!viewer.jobId) { stopLogTail(); return; }
      fetchJobLog().then(function () {
        if (!viewer.running) {
          /* The run finished: do one final refresh of the run list (state may
           * have landed) and stop tailing. */
          refreshRunList();
          stopLogTail();
        }
      });
    }, 1000);
  }
  function stopLogTail() {
    if (viewer.timer !== null) { clearInterval(viewer.timer); viewer.timer = null; }
  }

  if (modalSel) {
    modalSel.addEventListener('change', function () {
      viewer.run = (modalSel.selectedIndex > 0) ? modalSel.value : '';
      if (modalPre) { modalPre.textContent = 'Loading…'; }
      fetchJobLog().then(function () {
        /* Re-arm tailing only when viewing a running run (the latest). */
        if (viewer.running) { startLogTail(); } else { stopLogTail(); }
      });
    });
  }
  /* Close the modal on backdrop click (but not when clicking inside it). */
  if (modal) {
    modal.addEventListener('click', function (ev) {
      if (ev.target === modal) { closeLogViewer(); }
    });
  }
  /* Esc-to-close the dialog while it is open (basic dialog keyboard semantics). */
  document.addEventListener('keydown', function (ev) {
    if ((ev.key === 'Escape' || ev.key === 'Esc')
        && modal && modal.classList.contains('ur-open')) {
      ev.preventDefault();
      closeLogViewer();
    }
  });

  /* Seed the Connection-required + manual-only + cron-reading + hook-gutter
   * state for all rendered cards. (The hook gutter is already correct from
   * the server-side render; this just keeps JS and markup in lockstep.) */
  syncAllConnRequired();
  syncAllManualOnly();
  syncAllCronHuman();
  syncAllHookGutters();
  syncAllSnapshot();
})();
</script>

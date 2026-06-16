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
 *   jobs[<i>][rsyncOptions][excludes][]
 *
 * New jobs / pair rows are created client-side by cloning a hidden template and
 * rewriting the row index placeholders, so the indices stay contiguous and the
 * names round-trip. Every rendered value is htmlspecialchars-escaped.
 *
 * Phase 4 adds per-job Run / Dry-run / Abort buttons that POST to the handler
 * (CSRF-checked) to launch / stop the detached runner; Run/Dry-run are disabled
 * while a job is running (RunState::isRunning), Abort while it is idle. Live
 * status badges, the per-run log viewer, and 1s polling are Phase 6 - here the
 * buttons just fire-and-confirm and reload to refresh their enabled state.
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
$handlerUrl  = '/plugins/unraid.rsync/include/handler.php';

// Load the saved connections so the per-job Connection select can be populated.
// A credentials read error here is non-fatal for the Jobs tab: we just render
// an empty connection list (the Connections tab surfaces the real error).
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
    $pairs       = (isset($job['pairs']) && is_array($job['pairs'])) ? $job['pairs'] : [];
    $opts        = (isset($job['rsyncOptions']) && is_array($job['rsyncOptions']))
        ? $job['rsyncOptions'] : Config::defaultRsyncOptions();

    echo '<div class="ur-job-card" data-index="' . ur_h($index) . '">';

    // Collapsible header (COLLAPSED by default): a one-line summary so a page with
    // many jobs is not a wall of forms. Click / Enter / Space toggles the editable
    // body below (delegated in JS; works for server-rendered AND cloned cards).
    $summaryBits = [];
    $summaryBits[] = $enabled ? ur_t('enabled') : ur_t('disabled');
    if ($schedule !== '') {
        $summaryBits[] = $schedule;
    }
    echo '<div class="ur-job-head" role="button" tabindex="0" aria-expanded="false">';
    echo '<span class="ur-job-caret" aria-hidden="true">&#9654;</span>';
    echo '<span class="ur-job-title">' . ur_h($name !== '' ? $name : ur_t('(unnamed job)')) . '</span>';
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
    echo '<dt><label for="' . ur_h($idb . '_name') . '">' . ur_h(ur_t('Name')) . '</label>' . ur_required_mark() . ':</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_name') . '" name="' . ur_h($p . '[name]') . '" value="' . ur_h($name) . '" required></dd>';

    // enabled (checkbox; styled as a switch by webGui where available)
    echo '<dt>' . ur_h(ur_t('Enabled')) . ':</dt>';
    echo '<dd>';
    echo '<input type="hidden" name="' . ur_h($p . '[enabled]') . '" value="0">';
    echo '<input type="checkbox" class="ur-switch" name="' . ur_h($p . '[enabled]') . '" value="1"' . ($enabled ? ' checked' : '') . '>';
    echo '</dd>';

    // transport (drives whether Connection is required - see the JS toggle)
    echo '<dt><label for="' . ur_h($idb . '_transport') . '">' . ur_h(ur_t('Transport')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_transport') . '" class="ur-transport-select" data-conn="' . ur_h($idb . '_conn') . '" name="' . ur_h($p . '[transport]') . '">';
    foreach (['SSH' => 'SSH (remote host)', 'LOCAL' => 'Local (this server)'] as $val => $lbl) {
        $sel = ($transport === $val) ? ' selected' : '';
        echo '<option value="' . ur_h($val) . '"' . $sel . '>' . ur_h(ur_t($lbl)) . '</option>';
    }
    echo '</select></dd>';

    // direction
    echo '<dt><label for="' . ur_h($idb . '_direction') . '">' . ur_h(ur_t('Direction')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_direction') . '" name="' . ur_h($p . '[direction]') . '">';
    foreach (['PUSH' => 'Push (local -> remote)', 'PULL' => 'Pull (remote -> local)'] as $val => $lbl) {
        $sel = ($direction === $val) ? ' selected' : '';
        echo '<option value="' . ur_h($val) . '"' . $sel . '>' . ur_h(ur_t($lbl)) . '</option>';
    }
    echo '</select>';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('Direction only applies to SSH transport.')) . '</p></blockquote>';
    echo '</dd>';

    // connection (populated from the saved Credentials connections). REQUIRED
    // only for SSH transport (LOCAL jobs use no connection), so the `required`
    // attribute and the visual marker are toggled by JS off the transport select
    // (and seeded server-side here for the initial transport).
    global $urConnections;
    $conns = is_array($urConnections) ? $urConnections : [];
    $connRequired = ($transport === 'SSH');
    echo '<dt><label for="' . ur_h($idb . '_conn') . '">' . ur_h(ur_t('Connection')) . '</label>'
        . '<abbr class="ur-required ur-conn-required" title="' . ur_h(ur_t('Required')) . '"'
        . ($connRequired ? '' : ' style="display:none"') . '>*</abbr>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_conn') . '" class="ur-conn-select" data-transport="' . ur_h($idb . '_transport') . '" name="' . ur_h($p . '[connectionId]') . '"' . ($connRequired ? ' required' : '') . '>';
    echo '<option value=""' . ($connId === '' ? ' selected' : '') . '>' . ur_h(ur_t('(none)')) . '</option>';
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
        echo '<option value="' . ur_h($cId) . '"' . $sel . '>' . ur_h($cName) . '</option>';
    }
    // Preserve an existing connectionId that no longer matches a saved
    // connection (e.g. the connection was deleted) so editing doesn't silently
    // drop the reference; flag it as missing.
    if ($connId !== '' && !$connFound) {
        echo '<option value="' . ur_h($connId) . '" selected>' . ur_h($connId) . ' ' . ur_h(ur_t('(missing)')) . '</option>';
    }
    echo '</select>';
    if (empty($conns)) {
        echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('Add connections in the Connections tab.')) . '</p></blockquote>';
    } else {
        echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('Used for SSH transport. Manage connections in the Connections tab.')) . '</p></blockquote>';
    }
    echo '</dd>';

    // manual-only toggle: when ON the job is never scheduled (no cron line) and
    // the schedule field is hidden + not required; it runs only on demand.
    echo '<dt>' . ur_h(ur_t('Manual only (no schedule)')) . ':</dt>';
    echo '<dd>';
    echo '<input type="hidden" name="' . ur_h($p . '[manualOnly]') . '" value="0">';
    echo '<input type="checkbox" class="ur-switch ur-manual-only" name="' . ur_h($p . '[manualOnly]') . '" value="1"'
        . ($manualOnly ? ' checked' : '') . ' data-idb="' . ur_h($idb) . '">';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('When ON, this job is never scheduled — it runs only when you click Run or Dry-run, and the schedule below is ignored.')) . '</p></blockquote>';
    echo '</dd>';

    // schedule (5-field cron). Required UNLESS manual-only (then hidden); a hidden
    // required field is "not focusable" and would block submit, so the `required`
    // attribute + visual marker track the manual-only toggle (seeded here, kept in
    // sync by JS).
    $schedRowStyle = $manualOnly ? ' style="display:none"' : '';
    echo '<dt class="ur-sched-row" id="' . ur_h($idb . '_schedrow_dt') . '"' . $schedRowStyle . '><label for="' . ur_h($idb . '_schedule') . '">' . ur_h(ur_t('Schedule (cron)')) . '</label>'
        . '<abbr class="ur-required ur-sched-required" title="' . ur_h(ur_t('Required')) . '"' . ($manualOnly ? ' style="display:none"' : '') . '>*</abbr>:</dt>';
    echo '<dd class="ur-sched-row" id="' . ur_h($idb . '_schedrow_dd') . '"' . $schedRowStyle . '>';
    echo '<input type="text" class="ur-cron-input" id="' . ur_h($idb . '_schedule') . '" name="' . ur_h($p . '[schedule]') . '" value="' . ur_h($schedule) . '" placeholder="0 3 * * *"' . ($manualOnly ? '' : ' required') . '>';
    // Live, plain-English reading of the cron expression (filled by JS on input)
    // plus a field-order legend so the digit order is never guesswork.
    echo '<div class="ur-cron-human" id="' . ur_h($idb . '_cronhuman') . '" aria-live="polite"></div>';
    echo '<div class="ur-cron-legend"><code>* * * * *</code> = '
        . ur_h(ur_t('minute(0-59) hour(0-23) day-of-month(1-31) month(1-12) day-of-week(0-6, Sun=0)'))
        . ' &nbsp;·&nbsp; ' . ur_h(ur_t('e.g.')) . ' <code>0 3 * * *</code> = ' . ur_h(ur_t('03:00 daily'))
        . ', <code>*/15 * * * *</code> = ' . ur_h(ur_t('every 15 min')) . '</div>';
    echo '</dd>';

    // pairs
    $pairsRowsId = $idb . '_pairs';
    echo '<dt>' . ur_h(ur_t('Source -> destination pairs')) . ':</dt>';
    echo '<dd>';
    echo '<div class="ur-pairs" id="' . ur_h($pairsRowsId) . '" data-prefix="' . ur_h($p . '[pairs]') . '">';
    if (empty($pairs)) {
        ur_render_pair_row($p, 0, '', '');
    } else {
        foreach ($pairs as $k => $pair) {
            ur_render_pair_row($p, (int) $k, (string) ($pair['local'] ?? ''), (string) ($pair['remote'] ?? ''));
        }
    }
    echo '</div>';
    echo '<div><button type="button" class="ur-pair-add" data-rows="' . ur_h($pairsRowsId) . '">' . ur_h(ur_t('Add pair')) . '</button></div>';
    echo '</dd>';

    // use global config toggle
    echo '<dt>' . ur_h(ur_t('Use global config')) . ':</dt>';
    echo '<dd>';
    echo '<input type="hidden" name="' . ur_h($p . '[useGlobalDefaults]') . '" value="0">';
    echo '<input type="checkbox" class="ur-switch ur-use-global" name="' . ur_h($p . '[useGlobalDefaults]') . '" value="1"'
        . ($useGlobal ? ' checked' : '') . ' data-target="' . ur_h($idb . '_opts') . '">';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('When ON, this job uses the rsync options you set on the Global Settings tab (and follows any change you make there). Turn it OFF to give this job its own options below.')) . '</p></blockquote>';
    echo '</dd>';

    // log level
    echo '<dt><label for="' . ur_h($idb . '_log') . '">' . ur_h(ur_t('Log level')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_log') . '" name="' . ur_h($p . '[logLevel]') . '">';
    foreach (['quiet', 'normal', 'verbose', 'debug'] as $lvl) {
        $sel = ($logLevel === $lvl) ? ' selected' : '';
        echo '<option value="' . ur_h($lvl) . '"' . $sel . '>' . ur_h(ur_t(ucfirst($lvl))) . '</option>';
    }
    echo '</select></dd>';

    // notify mode
    echo '<dt><label for="' . ur_h($idb . '_notify') . '">' . ur_h(ur_t('Notify')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_notify') . '" name="' . ur_h($p . '[notifyMode]') . '">';
    foreach (['off' => 'Off', 'success-only' => 'Success only', 'failure-only' => 'Failure only', 'always' => 'Always'] as $val => $lbl) {
        $sel = ($notifyMode === $val) ? ' selected' : '';
        echo '<option value="' . ur_h($val) . '"' . $sel . '>' . ur_h(ur_t($lbl)) . '</option>';
    }
    echo '</select></dd>';

    // pre/post hooks. Both run as ROOT via `bash -c` and their stdout/stderr is
    // captured into the per-run log, which is rendered in the browser. Users paste
    // shell SCRIPTS here, so the textareas are styled as small code editors
    // (monospace, resizable, no wrap/spellcheck) and a VISIBLE helper explains
    // both hooks + the outcome env vars + the root/secret-leak warning. (The stock
    // blockquote.inline_help is hidden by default unless help mode is on, so the
    // explanation would otherwise be invisible.)
    $hookEnvVars = 'UR_JOB_ID, UR_JOB_NAME, UR_DRY_RUN, UR_TRIGGER, UR_JOB_STATUS, UR_EXIT_CODE';
    echo '<dt>' . ur_h(ur_t('Pre / post-run hooks')) . ':</dt>';
    echo '<dd><div class="ur-hook-help">';
    echo '<p>' . ur_h(ur_t('Optional shell commands run on THIS server as root (via bash -c) around the transfer: the pre-run hook just before rsync starts, the post-run hook just after it finishes. The post-run hook ALWAYS runs — even when the transfer fails or is aborted.')) . '</p>';
    echo '<p>' . ur_h(ur_t('Use them to prepare or clean up around a backup — e.g. mount/unmount a share, spin a disk up or down, or send a custom notification. Write it like a small bash script (multiple lines are fine; no shebang needed).')) . '</p>';
    echo '<p>' . ur_h(ur_t('Available to both hooks as environment variables')) . ': <code>' . ur_h($hookEnvVars) . '</code>. '
        . ur_h(ur_t('UR_DRY_RUN is 0/1, UR_TRIGGER is manual/schedule; UR_JOB_STATUS and UR_EXIT_CODE hold the run outcome (post-run hook).')) . '</p>';
    echo '<p class="ur-hook-warn">' . ur_h(ur_t('Hooks run with full root privileges — be careful what you put here. Their output is captured into the run log (visible in the UI), so do not echo passwords or keys.')) . '</p>';
    echo '</div></dd>';

    $preExample  = ur_t("# Runs before the transfer. Example:\n# mount /mnt/remotes/nas-backup");
    $postExample = ur_t("# Runs after the transfer (always). Example:\n# [ \"\$UR_JOB_STATUS\" = SUCCESS ] && logger \"backup finished ok\"");
    echo '<dt><label for="' . ur_h($idb . '_pre') . '">' . ur_h(ur_t('Pre-run hook')) . '</label>:</dt>';
    echo '<dd><textarea class="ur-hook-ta" id="' . ur_h($idb . '_pre') . '" name="' . ur_h($p . '[preHook]')
        . '" rows="6" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" placeholder="' . ur_h($preExample) . '">' . ur_h($preHook) . '</textarea></dd>';
    echo '<dt><label for="' . ur_h($idb . '_post') . '">' . ur_h(ur_t('Post-run hook')) . '</label>:</dt>';
    echo '<dd><textarea class="ur-hook-ta" id="' . ur_h($idb . '_post') . '" name="' . ur_h($p . '[postHook]')
        . '" rows="6" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" placeholder="' . ur_h($postExample) . '">' . ur_h($postHook) . '</textarea></dd>';

    echo '</dl>';

    // rsync options block (whitelist), gated visually by the use-global toggle
    echo '<div class="ur-job-opts" id="' . ur_h($idb . '_opts') . '"' . ($useGlobal ? ' style="display:none"' : '') . '>';
    ur_render_rsync_options($opts, $p . '[rsyncOptions]', $idb . '_opts_fields');
    echo '</div>';

    // Run / Dry-run / Abort actions. These are only meaningful for a saved job
    // (one with an id); the JS-cloned template card has the literal "__IDX__"
    // placeholder and no real id, so its run controls are rendered disabled and
    // the user must save first. A job currently running has Run/Dry-run disabled
    // and Abort enabled (and vice versa); state comes from RunState::isRunning,
    // PID-reuse-safe. Live status badges + polling are Phase 6.
    $hasId   = ($id !== '' && strpos($id, '__IDX__') === false);
    $running = $hasId && RunState::isRunning($id);
    $runDis  = (!$hasId || $running) ? ' disabled' : '';
    $abortDis = (!$hasId || !$running) ? ' disabled' : '';
    echo '<div class="ur-job-card-actions">';
    $jobNameAttr = ' data-jobname="' . ur_h($name !== '' ? $name : $id) . '"';
    echo '<button type="button" class="ur-job-run" data-jobid="' . ur_h($id) . '"' . $jobNameAttr . $runDis . '>' . ur_h(ur_t('Run')) . '</button> ';
    echo '<button type="button" class="ur-job-dry" data-jobid="' . ur_h($id) . '"' . $jobNameAttr . $runDis . '>' . ur_h(ur_t('Dry-run')) . '</button> ';
    echo '<button type="button" class="ur-job-abort" data-jobid="' . ur_h($id) . '"' . $abortDis . '>' . ur_h(ur_t('Abort')) . '</button> ';
    echo '<button type="button" class="ur-job-del">' . ur_h(ur_t('Remove job')) . '</button>';
    echo '</div>';
    if (!$hasId) {
        echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('Save the job before running it.')) . '</p></blockquote>';
    }
    echo '<div class="ur-job-run-result ur-result" data-jobid="' . ur_h($id) . '"></div>';

    echo '</div>'; // .ur-job-body
    echo '</div>'; // .ur-job-card
}

/**
 * Render a single source->dest pair row. $k is an int row index or "__PIDX__"
 * for the JS template.
 *
 * @param int|string $k
 */
function ur_render_pair_row(string $prefix, $k, string $local, string $remote): void
{
    // Both the source (local) and destination (remote) of a pair are mandatory;
    // mark them required so the browser blocks a half-filled pair before submit
    // (the server re-enforces this and the path guardrails).
    $base = $prefix . '[pairs][' . $k . ']';
    echo '<div class="ur-pair-row">';
    echo '<input type="text" name="' . ur_h($base . '[local]') . '" value="' . ur_h($local) . '" placeholder="/mnt/user/share/sub/" required>';
    echo ' &rarr; ';
    echo '<input type="text" name="' . ur_h($base . '[remote]') . '" value="' . ur_h($remote) . '" placeholder="/mnt/disk/backup/ or remote path" required>';
    echo ' <button type="button" class="ur-pair-del">&minus;</button>';
    echo '</div>';
}

/**
 * Build the "Next run" cell label for one job. Returns a plain string (the
 * caller escapes it). Phase 5 shows the computed next fire time; colored state
 * badges + last-run are Phase 6.
 *
 *   - disabled job                  -> "disabled"
 *   - enabled, schedule uncomputable-> "—"   (malformed expr; save validation
 *                                             normally blocks this, but a stored
 *                                             config could predate validation)
 *   - enabled, computable           -> absolute local time + relative ("in ...")
 *
 * @param array<string,mixed> $job
 * @param int                 $now reference timestamp (seconds)
 */
function ur_next_run_label(array $job, int $now): string
{
    if (!empty($job['manualOnly'])) {
        return ur_t('manual (on demand)');
    }
    if (empty($job['enabled'])) {
        return ur_t('disabled');
    }
    $schedule = trim((string) ($job['schedule'] ?? ''));
    if ($schedule === '') {
        return '—';
    }
    $next = Cron::nextRun($schedule, $now);
    if ($next === null) {
        return '—';
    }
    // Absolute local time (matches the timezone crond fires in) + a compact
    // relative hint so the list reads at a glance.
    $absolute = date('Y-m-d H:i', $next);
    $relative = ur_relative_time($next - $now);
    return $absolute . ' (' . $relative . ')';
}

/**
 * Render a small "in 3h 5m" / "in 2d 4h" style relative string for a forward
 * delta in seconds. Coarse on purpose (two largest non-zero units); used only
 * for the at-a-glance next-run hint.
 */
function ur_relative_time(int $deltaSec): string
{
    if ($deltaSec <= 0) {
        return ur_t('due now');
    }
    $units = [
        ['d', 86400],
        ['h', 3600],
        ['m', 60],
    ];
    $parts = [];
    $remaining = $deltaSec;
    foreach ($units as [$suffix, $size]) {
        $count = intdiv($remaining, $size);
        if ($count > 0) {
            $parts[] = $count . $suffix;
            $remaining -= $count * $size;
        }
        if (count($parts) === 2) {
            break;
        }
    }
    if (empty($parts)) {
        // Under a minute away.
        return ur_t('in less than a minute');
    }
    return ur_t('in') . ' ' . implode(' ', $parts);
}

/**
 * The CSS modifier class for a state badge. Kept in PHP so the initial
 * server-rendered badge and the JS-updated badge use the same vocabulary.
 * RUNNING is animated (blue); SUCCESS green; WARNING/PARTIAL/TIMEOUT orange;
 * FAILED red; ABORTED grey-red; PENDING grey. Unknown -> grey.
 */
function ur_state_badge_class(string $state): string
{
    switch (strtoupper($state)) {
        case 'RUNNING': return 'ur-badge-running';
        case 'SUCCESS': return 'ur-badge-success';
        case 'WARNING':
        case 'PARTIAL':
        case 'TIMEOUT': return 'ur-badge-warning';
        case 'FAILED':  return 'ur-badge-failed';
        case 'ABORTED': return 'ur-badge-aborted';
        case 'PENDING':
        default:        return 'ur-badge-pending';
    }
}

/**
 * Human label for a state badge (RUNNING / Success / ... ). Pending reads as
 * "Never run" which is friendlier than the vocabulary token.
 */
function ur_state_label(string $state): string
{
    switch (strtoupper($state)) {
        case 'RUNNING': return ur_t('Running');
        case 'SUCCESS': return ur_t('Success');
        case 'WARNING': return ur_t('Warning');
        case 'PARTIAL': return ur_t('Partial');
        case 'TIMEOUT': return ur_t('Timeout');
        case 'FAILED':  return ur_t('Failed');
        case 'ABORTED': return ur_t('Aborted');
        case 'PENDING':
        default:        return ur_t('Never run');
    }
}

/**
 * Derive a job's display state from its live running flag + last-run summary.
 * RUNNING (live) overrides the summary; otherwise the summary state; PENDING
 * when there is no summary (never run). Mirrors the handler's ur_derive_state.
 *
 * @param array<string,mixed>|null $summary
 */
function ur_job_state(bool $running, $summary): string
{
    if ($running) {
        return 'RUNNING';
    }
    if (is_array($summary) && !empty($summary['state'])) {
        return (string) $summary['state'];
    }
    return 'PENDING';
}

/**
 * The "Last run" cell label: a relative "x ago" using the summary finishedAt,
 * or an em-dash when there is no summary. Returns plain text (caller escapes).
 *
 * @param array<string,mixed>|null $summary
 */
function ur_last_run_label($summary, int $now): string
{
    if (!is_array($summary) || empty($summary['finishedAt'])) {
        return '—';
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', (string) $summary['finishedAt'], new DateTimeZone('UTC'));
    if ($dt === false) {
        return '—';
    }
    $ts    = (int) $dt->getTimestamp();
    $delta = $now - $ts;
    if ($delta < 0) {
        $delta = 0;
    }
    return date('Y-m-d H:i', $ts) . ' (' . ur_ago($delta) . ')';
}

/** "x ago" coarse relative string for a past delta in seconds. */
function ur_ago(int $deltaSec): string
{
    if ($deltaSec < 60) {
        return ur_t('just now');
    }
    $units = [['d', 86400], ['h', 3600], ['m', 60]];
    $parts = [];
    $remaining = $deltaSec;
    foreach ($units as [$suffix, $size]) {
        $count = intdiv($remaining, $size);
        if ($count > 0) {
            $parts[] = $count . $suffix;
            $remaining -= $count * $size;
        }
        if (count($parts) === 2) {
            break;
        }
    }
    return implode(' ', $parts) . ' ' . ur_t('ago');
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

/* The "required" field marker: a red asterisk paired with the HTML5 `required`
   attribute on the mandatory inputs. text-decoration:none drops the dotted
   <abbr> underline so it reads as a clean asterisk. */
.ur-required { color: var(--red-800, #b71c1c); font-weight: bold; text-decoration: none; cursor: help; }

/* Live cron reading + field-order legend under the schedule input. */
.ur-cron-human { margin-top: 4px; font-size: 0.9em; font-weight: bold; color: var(--blue-800, #1565c0); min-height: 1.1em; }
.ur-cron-human.ur-cron-bad { color: var(--red-800, #b71c1c); }
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

/* Colored state badges + the per-run log viewer. Colors pull from
   the inherited dynamix palette where available (--orange-500, --green-...),
   with safe fallbacks so the badges read correctly under any theme. */
.ur-badge {
  display: inline-block;
  min-width: 64px;
  padding: 2px 10px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: bold;
  text-align: center;
  color: #fff;
  line-height: 1.6;
  white-space: nowrap;
}
.ur-badge-success  { background: #1c7d3f; }                 /* green  */
/* Warning badge: dark text on a darkened orange. White-on-#ff8c2f was ~2.4:1,
   below WCAG AA for small bold text; #b15c00 with near-black text clears AA. */
.ur-badge-warning  { background: #b15c00; color: #1a1a1a; }
.ur-badge-failed   { background: var(--red-800, #b71c1c); } /* red    */
.ur-badge-aborted  { background: #6b6b6b; }                 /* grey   */
.ur-badge-pending  { background: #9aa0a6; }                 /* grey   */
.ur-badge-running  { background: #1565c0; animation: ur-pulse 1.3s ease-in-out infinite; }
@keyframes ur-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.55; } }

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
   as small code editors — monospace, resizable, no soft-wrap (horizontal scroll
   like a terminal), spellcheck off. The accompanying .ur-hook-help is a VISIBLE
   callout (the stock blockquote.inline_help is hidden unless help mode is on). */
.ur-hook-ta {
  width: 100%; box-sizing: border-box; min-height: 7em; resize: vertical;
  font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, Consolas, monospace);
  font-size: 12px; line-height: 1.5; tab-size: 2; -moz-tab-size: 2;
  white-space: pre; overflow: auto;
  background: var(--background-color, #1c1c1c); color: var(--font-color, #d0d0d0);
  border: 1px solid var(--border-color, #555); border-radius: 4px; padding: 8px 10px;
}
.ur-hook-ta:focus { outline: none; border-color: var(--blue-500, #2196f3); }
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
/* Emit the shared robust-fetch helpers (window.urAjax) so the run/dry/abort and
 * save AJAX surface a non-JSON 403/500 WITH its HTTP status instead of failing
 * silently in r.json() (the same fix the Credentials page already carries). */
ur_emit_ajax_helpers();
/* Re-enable the plugin's own Apply button once the form is edited (Unraid's
 * framework disables it on load and won't re-enable our custom forms). */
ur_emit_form_enable_assets();
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
  <?=_('The State column shows each job\'s live status (updated automatically while a job runs); use the Logs button to view per-run output. Per-job notifications are sent through Unraid\'s notification system — pick when to notify with each job\'s Notify setting')?>.
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
        $state   = ur_job_state($running, $summary);
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
        <td class="ur-last-run-cell"><?=htmlspecialchars(ur_last_run_label($summary, $urNow), ENT_QUOTES, 'UTF-8')?></td>
        <td class="ur-next-run-cell"><?=htmlspecialchars(ur_next_run_label($job, $urNow), ENT_QUOTES, 'UTF-8')?></td>
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
<form markdown="1" method="POST" action="<?=htmlspecialchars($handlerUrl, ENT_QUOTES, 'UTF-8')?>" id="ur-jobs-form">
  <input type="hidden" name="action" value="saveConfig">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8')?>">
  <!-- Sentinel: marks this as a Jobs-tab submission so the handler rebuilds the
       jobs list even when the user has deleted every card (an intentional
       "clear all jobs"), instead of treating it as "Jobs not submitted". -->
  <input type="hidden" name="jobs_present" value="1">

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
<?php ur_render_pair_row('jobs[__IDX__]', '__PIDX__', '', ''); ?>
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
  }

  function addOptRow(rowsEl) {
    var name = rowsEl.getAttribute('data-name');
    var row = document.createElement('div');
    row.className = 'ur-row';
    var input = document.createElement('input');
    input.type = 'text';
    input.name = name;
    input.value = '';
    var del = document.createElement('button');
    del.type = 'button';
    del.className = 'ur-row-del';
    del.innerHTML = '&minus;';
    row.appendChild(input);
    row.appendChild(document.createTextNode(' '));
    row.appendChild(del);
    rowsEl.appendChild(row);
  }

  var HANDLER_URL = <?=ur_js($handlerUrl)?>;
  var CSRF_TOKEN  = <?=ur_js($csrf)?>;

  /* Fire a run/dry-run/abort action for a job and show the result inline next
   * to its card. Fire-and-confirm: the response just acknowledges the launch.
   * Instead of a full page reload, we kick the 1s status poll so badges and the
   * Run/Dry/Abort enable state update live. */
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

  /* Optimistically paint a job's badge RUNNING the instant a launch is accepted,
   * reusing the exact same badge code as the 1s status poll (which then
   * confirms/corrects it). */
  function setBadgeRunning(jobId) {
    var badges = document.querySelectorAll('.ur-state-badge[data-jobid="' + cssEsc(jobId) + '"]');
    badges.forEach(function (b) {
      BADGE_CLASSES.forEach(function (c) { b.classList.remove(c); });
      b.classList.add(badgeClassFor('RUNNING'));
      b.textContent = badgeLabelFor('RUNNING');
    });
  }

  function postJobAction(action, jobId, btn) {
    if (!jobId) { return; }
    var isLaunch = (action === 'runJob' || action === 'dryRunJob');
    var card = btn && btn.closest ? btn.closest('.ur-job-card') : null;
    var result = card ? card.querySelector('.ur-job-run-result') : null;
    /* Immediate, obvious feedback that the click was picked up: spinner on the
     * clicked button (its label cached so we can restore it), button disabled. */
    var prevHtml = null;
    if (btn) {
      prevHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="ur-spin" aria-hidden="true"></span>' + btn.textContent;
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
          if (prevHtml !== null) { btn.innerHTML = prevHtml; }
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
        /* Resume polling so the badge + buttons reflect the new running state
         * immediately (poll re-derives the enable state from getStatus). */
        ensurePolling();
        pollStatusOnce();
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
    } else if (t.classList.contains('ur-row-add')) {
      var or = document.getElementById(t.getAttribute('data-rows'));
      if (or) { addOptRow(or); }
    } else if (t.classList.contains('ur-row-del')) {
      var orow = t.closest ? t.closest('.ur-row') : null;
      if (orow && orow.parentNode) { orow.parentNode.removeChild(orow); }
    }
  });

  /* Toggle the Connection select's `required` attribute + its visual marker to
   * match the chosen transport: SSH needs a connection, LOCAL does not. Keeping
   * the client `required` in lockstep with the server rule (Job::validate) means
   * a LOCAL job is never blocked and an SSH job without a connection is caught
   * client-side too. Driven off the transport select (which carries data-conn). */
  function syncConnRequired(transportSel) {
    if (!transportSel) { return; }
    var conn = document.getElementById(transportSel.getAttribute('data-conn'));
    if (!conn) { return; }
    var isSsh = (transportSel.value === 'SSH');
    conn.required = isSsh;
    /* The marker lives in the same card; find it relative to the connection. */
    var card = conn.closest ? conn.closest('.ur-job-card') : null;
    var mark = card ? card.querySelector('.ur-conn-required') : null;
    if (mark) { mark.style.display = isSsh ? '' : 'none'; }
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
    }
  });

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
    }
  });

  /* Translate a 5-field cron expression into a short plain-English phrase. Covers
   * the common shapes (numbers, asterisk, step values, ranges, lists) and falls back to a
   * field-by-field reading; a hint, not an authoritative scheduler. */
  function describeCron(expr) {
    expr = (expr || '').trim().replace(/\s+/g, ' ');
    if (expr === '') { return { ok: true, text: '' }; }
    var f = expr.split(' ');
    if (f.length !== 5) { return { ok: false, text: 'Needs 5 fields: minute hour day-of-month month day-of-week.' }; }
    var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var MON = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var min = f[0], hour = f[1], dom = f[2], mon = f[3], dow = f[4];
    var isNum = function (x) { return /^\d+$/.test(x); };
    var pad = function (n) { n = parseInt(n, 10); return (n < 10 ? '0' : '') + n; };

    function fieldOk(v, lo, hi) {
      return v.split(',').every(function (part) {
        var m;
        if (part === '*') { return true; }
        if ((m = part.match(/^\*\/(\d+)$/))) { return +m[1] >= 1; }
        if ((m = part.match(/^(\d+)-(\d+)(?:\/(\d+))?$/))) { return +m[1] >= lo && +m[2] <= hi && +m[1] <= +m[2]; }
        if ((m = part.match(/^(\d+)(?:\/(\d+))?$/))) { return +m[1] >= lo && +m[1] <= hi; }
        return false;
      });
    }
    if (!(fieldOk(min, 0, 59) && fieldOk(hour, 0, 23) && fieldOk(dom, 1, 31) && fieldOk(mon, 1, 12) && fieldOk(dow, 0, 7))) {
      return { ok: false, text: 'Not a valid 5-field cron expression.' };
    }

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
        if ((m = part.match(/^(\d+)-(\d+)$/))) { return arr[+m[1]] + '–' + arr[+m[2]]; }
        if (isNum(part)) { return arr[+part]; }
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
    return { ok: true, text: text.charAt(0).toUpperCase() + text.slice(1) + '.' };
  }

  function updateCronHuman(input) {
    var idb = input.id.replace(/_schedule$/, '');
    var out = document.getElementById(idb + '_cronhuman');
    if (!out) { return; }
    var r = describeCron(input.value);
    out.textContent = r.text;
    out.className = 'ur-cron-human' + ((!r.ok && r.text !== '') ? ' ur-cron-bad' : '');
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
          /* Reload so the summary table reflects the saved state. */
          setTimeout(function () { window.location.reload(); }, 600);
        } else {
          window.urAjax.show(result, false, window.urAjax.errText(res, 'Save failed.'));
        }
      });
    });
  }

  /* ===================================================================
   * Phase 6: live status polling + per-run log viewer.
   * =================================================================== */

  var STATUS_URL = HANDLER_URL + '?action=getStatus';

  /* Badge class + label vocabulary - kept in lockstep with jobs.php
   * (ur_state_badge_class / ur_state_label) so a JS-updated badge matches a
   * server-rendered one. */
  var BADGE_CLASSES = [
    'ur-badge-running', 'ur-badge-success', 'ur-badge-warning',
    'ur-badge-failed', 'ur-badge-aborted', 'ur-badge-pending'
  ];
  function badgeClassFor(state) {
    switch ((state || '').toUpperCase()) {
      case 'RUNNING': return 'ur-badge-running';
      case 'SUCCESS': return 'ur-badge-success';
      case 'WARNING':
      case 'PARTIAL':
      case 'TIMEOUT': return 'ur-badge-warning';
      case 'FAILED':  return 'ur-badge-failed';
      case 'ABORTED': return 'ur-badge-aborted';
      default:        return 'ur-badge-pending';
    }
  }
  function badgeLabelFor(state) {
    switch ((state || '').toUpperCase()) {
      case 'RUNNING': return 'Running';
      case 'SUCCESS': return 'Success';
      case 'WARNING': return 'Warning';
      case 'PARTIAL': return 'Partial';
      case 'TIMEOUT': return 'Timeout';
      case 'FAILED':  return 'Failed';
      case 'ABORTED': return 'Aborted';
      default:        return 'Never run';
    }
  }

  /* Coarse "x ago" relative label for a past epoch (seconds). */
  function agoLabel(finishedEpoch, nowEpoch) {
    if (!finishedEpoch) { return '—'; }
    var delta = Math.max(0, nowEpoch - finishedEpoch);
    if (delta < 60) { return 'just now'; }
    var units = [['d', 86400], ['h', 3600], ['m', 60]];
    var parts = [];
    var rem = delta;
    for (var i = 0; i < units.length && parts.length < 2; i++) {
      var c = Math.floor(rem / units[i][1]);
      if (c > 0) { parts.push(c + units[i][0]); rem -= c * units[i][1]; }
    }
    return parts.join(' ') + ' ago';
  }
  function fmtLocal(epoch) {
    var d = new Date(epoch * 1000);
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
      + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  /* Coarse forward "in 3h 5m" label for a future epoch (seconds) — mirrors
   * jobs.php ur_relative_time() so a JS-updated next-run cell reads like the
   * server-rendered one. */
  function inLabel(nextEpoch, nowEpoch) {
    var delta = nextEpoch - nowEpoch;
    if (delta <= 0) { return 'due now'; }
    var units = [['d', 86400], ['h', 3600], ['m', 60]];
    var parts = [];
    var rem = delta;
    for (var i = 0; i < units.length && parts.length < 2; i++) {
      var c = Math.floor(rem / units[i][1]);
      if (c > 0) { parts.push(c + units[i][0]); rem -= c * units[i][1]; }
    }
    return parts.length ? ('in ' + parts.join(' ')) : 'in less than a minute';
  }

  /* The next-run cell label from a getStatus entry, mirroring jobs.php
   * ur_next_run_label(): a disabled job reads "disabled"; an enabled job with no
   * computable next fire reads an em-dash; otherwise absolute local time + an
   * "in …" hint. getStatus always carries both `enabled` (bool) and `nextRun`
   * (epoch|null), so we read enabled directly to distinguish "disabled" from an
   * enabled-but-uncomputable schedule. */
  function nextRunLabel(s, nowEpoch) {
    if (s && s.enabled === false) { return 'disabled'; }
    if (!s || !s.nextRun) { return '—'; }
    return fmtLocal(s.nextRun) + ' (' + inLabel(s.nextRun, nowEpoch) + ')';
  }

  /* Apply a getStatus payload: update each job's badge, last-run cell, and the
   * Run/Dry/Abort enable state on its card. Returns true if ANY job is running
   * (so the caller can decide whether to keep polling). */
  function applyStatus(payload) {
    if (!payload || !payload.jobs) { return false; }
    var now = payload.now || Math.floor(Date.now() / 1000);
    var anyRunning = false;

    Object.keys(payload.jobs).forEach(function (jobId) {
      var s = payload.jobs[jobId];
      if (!s) { return; }
      if (s.running) { anyRunning = true; }

      /* Badge (there can be one in the table row + nowhere else; update all
       * badges carrying this job id). */
      var badges = document.querySelectorAll('.ur-state-badge[data-jobid="' + cssEsc(jobId) + '"]');
      badges.forEach(function (b) {
        BADGE_CLASSES.forEach(function (c) { b.classList.remove(c); });
        b.classList.add(badgeClassFor(s.state));
        b.textContent = badgeLabelFor(s.state);
      });

      /* Last-run + next-run cells in the summary row. */
      var row = document.querySelector('tr[data-jobid="' + cssEsc(jobId) + '"]');
      if (row) {
        var cell = row.querySelector('.ur-last-run-cell');
        if (cell) {
          if (s.lastRun && s.lastRun.finishedAt) {
            var fin = isoToEpoch(s.lastRun.finishedAt);
            cell.textContent = fin ? (fmtLocal(fin) + ' (' + agoLabel(fin, now) + ')') : '—';
          } else {
            cell.textContent = '—';
          }
        }
        /* Keep the Next-run cell live too (it would otherwise go stale after a
         * run); mirrors the server-rendered ur_next_run_label vocabulary. */
        var nextCell = row.querySelector('.ur-next-run-cell');
        if (nextCell) { nextCell.textContent = nextRunLabel(s, now); }
      }

      /* Run/Dry/Abort enable state on the edit card (if present). */
      var run   = document.querySelector('.ur-job-run[data-jobid="' + cssEsc(jobId) + '"]');
      var dry   = document.querySelector('.ur-job-dry[data-jobid="' + cssEsc(jobId) + '"]');
      var abort = document.querySelector('.ur-job-abort[data-jobid="' + cssEsc(jobId) + '"]');
      if (run)   { run.disabled = !!s.running; }
      if (dry)   { dry.disabled = !!s.running; }
      if (abort) { abort.disabled = !s.running; }
    });

    return anyRunning;
  }

  /* Minimal CSS attribute-value escaper for our slug-shaped ids. */
  function cssEsc(v) { return String(v).replace(/["\\]/g, '\\$&'); }

  /* Parse an ISO-8601 UTC "Y-m-dTH:i:sZ" string to an epoch (0 on failure). */
  function isoToEpoch(iso) {
    var t = Date.parse(iso);
    return isNaN(t) ? 0 : Math.floor(t / 1000);
  }

  var statusTimer = null;
  function pollStatusOnce() {
    return fetch(STATUS_URL, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (body) {
        var anyRunning = applyStatus(body);
        /* Stop polling once nothing is running (and the viewer isn't tailing a
         * running job); a user action re-arms it via ensurePolling(). */
        if (!anyRunning && !viewerTailing()) { stopPolling(); }
      })
      .catch(function () { /* transient; keep the timer */ });
  }
  function ensurePolling() {
    if (statusTimer === null) {
      statusTimer = setInterval(pollStatusOnce, 1000);
    }
  }
  function stopPolling() {
    if (statusTimer !== null) { clearInterval(statusTimer); statusTimer = null; }
  }

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

  function viewerTailing() { return viewer.timer !== null && viewer.running; }

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
          var lbl = run.id.replace(/^run-/, '').replace(/\.log$/, '');
          if (run.state) { lbl += '  [' + run.state + ']'; }
          if (idx === 0) { lbl += '  (latest)'; }
          opt.textContent = lbl;          // textContent: never raw innerHTML
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

  /* Seed the Connection-required + manual-only + cron-reading state for all
   * rendered cards. */
  syncAllConnRequired();
  syncAllManualOnly();
  syncAllCronHuman();

  /* Kick off polling on load only when something is already running (the
   * server-rendered badges tell us, but a cheap initial poll is simplest and
   * self-stops if everything is idle). */
  ensurePolling();
  pollStatusOnce();
})();
</script>

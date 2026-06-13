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
 * Phase 2 is config only: NO Run / Dry-run / Abort buttons, NO runner, NO cron
 * writing, NO credentials backend. Those land in later phases.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Config.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Job.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Credentials.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php';

$csrf = '';
if (isset($GLOBALS['var']) && is_array($GLOBALS['var']) && !empty($GLOBALS['var']['csrf_token'])) {
    $csrf = (string) $GLOBALS['var']['csrf_token'];
}

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
// an empty connection list (the Credentials tab surfaces the real error).
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

    // Hidden id (preserved across edits; blank for a new job - handler slugs it).
    echo '<input type="hidden" name="' . ur_h($p . '[id]') . '" value="' . ur_h($id) . '">';

    echo '<dl>';

    // name
    echo '<dt><label for="' . ur_h($idb . '_name') . '">' . ur_h(ur_t('Name')) . '</label>:</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_name') . '" name="' . ur_h($p . '[name]') . '" value="' . ur_h($name) . '"></dd>';

    // enabled (checkbox; styled as a switch by webGui where available)
    echo '<dt>' . ur_h(ur_t('Enabled')) . ':</dt>';
    echo '<dd>';
    echo '<input type="hidden" name="' . ur_h($p . '[enabled]') . '" value="0">';
    echo '<input type="checkbox" class="ur-switch" name="' . ur_h($p . '[enabled]') . '" value="1"' . ($enabled ? ' checked' : '') . '>';
    echo '</dd>';

    // transport
    echo '<dt><label for="' . ur_h($idb . '_transport') . '">' . ur_h(ur_t('Transport')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_transport') . '" name="' . ur_h($p . '[transport]') . '">';
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

    // connection (populated from the saved Credentials connections)
    global $urConnections;
    $conns = is_array($urConnections) ? $urConnections : [];
    echo '<dt><label for="' . ur_h($idb . '_conn') . '">' . ur_h(ur_t('Connection')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_conn') . '" name="' . ur_h($p . '[connectionId]') . '">';
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
        echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('Add connections in the Credentials tab.')) . '</p></blockquote>';
    } else {
        echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('Used for SSH transport. Manage connections in the Credentials tab.')) . '</p></blockquote>';
    }
    echo '</dd>';

    // schedule (5-field cron)
    echo '<dt><label for="' . ur_h($idb . '_schedule') . '">' . ur_h(ur_t('Schedule (cron)')) . '</label>:</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_schedule') . '" name="' . ur_h($p . '[schedule]') . '" value="' . ur_h($schedule) . '" placeholder="0 3 * * *"></dd>';

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
    echo '<button type="button" class="ur-pair-add" data-rows="' . ur_h($pairsRowsId) . '">' . ur_h(ur_t('Add pair')) . '</button>';
    echo '</dd>';

    // use global defaults toggle
    echo '<dt>' . ur_h(ur_t('Use global default rsync options')) . ':</dt>';
    echo '<dd>';
    echo '<input type="hidden" name="' . ur_h($p . '[useGlobalDefaults]') . '" value="0">';
    echo '<input type="checkbox" class="ur-switch ur-use-global" name="' . ur_h($p . '[useGlobalDefaults]') . '" value="1"'
        . ($useGlobal ? ' checked' : '') . ' data-target="' . ur_h($idb . '_opts') . '">';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('When on, this job ignores its own options and uses the Global Settings defaults.')) . '</p></blockquote>';
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

    // pre/post hooks
    echo '<dt><label for="' . ur_h($idb . '_pre') . '">' . ur_h(ur_t('Pre-run hook')) . '</label>:</dt>';
    echo '<dd><textarea id="' . ur_h($idb . '_pre') . '" name="' . ur_h($p . '[preHook]') . '" rows="2">' . ur_h($preHook) . '</textarea></dd>';
    echo '<dt><label for="' . ur_h($idb . '_post') . '">' . ur_h(ur_t('Post-run hook')) . '</label>:</dt>';
    echo '<dd><textarea id="' . ur_h($idb . '_post') . '" name="' . ur_h($p . '[postHook]') . '" rows="2">' . ur_h($postHook) . '</textarea></dd>';

    echo '</dl>';

    // rsync options block (whitelist), gated visually by the use-global toggle
    echo '<div class="ur-job-opts" id="' . ur_h($idb . '_opts') . '"' . ($useGlobal ? ' style="display:none"' : '') . '>';
    ur_render_rsync_options($opts, $p . '[rsyncOptions]', $idb . '_opts_fields');
    echo '</div>';

    echo '<div class="ur-job-card-actions"><button type="button" class="ur-job-del">' . ur_h(ur_t('Remove job')) . '</button></div>';

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
    $base = $prefix . '[pairs][' . $k . ']';
    echo '<div class="ur-pair-row">';
    echo '<input type="text" name="' . ur_h($base . '[local]') . '" value="' . ur_h($local) . '" placeholder="/mnt/user/share/sub/">';
    echo ' &rarr; ';
    echo '<input type="text" name="' . ur_h($base . '[remote]') . '" value="' . ur_h($remote) . '" placeholder="/mnt/disk/backup/ or remote path">';
    echo ' <button type="button" class="ur-pair-del">&minus;</button>';
    echo '</div>';
}
?>
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
  <?=_('Nothing runs yet — execution, scheduling and credentials arrive in later releases')?>.
</p>

<!-- Summary list ------------------------------------------------------------>
<table class="tablesorter ur-job-list">
  <thead>
    <tr>
      <th><?=_('Name')?></th>
      <th><?=_('Enabled')?></th>
      <th><?=_('Transport')?></th>
      <th><?=_('Schedule')?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($jobs)): ?>
      <tr><td colspan="4"><?=_('No jobs configured yet')?>.</td></tr>
    <?php else: foreach ($jobs as $job): ?>
      <tr>
        <td><?=htmlspecialchars((string)($job['name'] ?? ''), ENT_QUOTES, 'UTF-8')?></td>
        <td><?=!empty($job['enabled']) ? _('Yes') : _('No')?></td>
        <td><?=htmlspecialchars((string)($job['transport'] ?? ''), ENT_QUOTES, 'UTF-8')?></td>
        <td><?=htmlspecialchars((string)($job['schedule'] ?? ''), ENT_QUOTES, 'UTF-8')?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

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

<!-- Hidden templates for JS cloning. Index placeholders are rewritten on add.
     Rendered with htmlspecialchars too; only the literal placeholders are
     swapped client-side. The new-job template is seeded from the user's saved
     Global Settings (global.defaultRsyncOptions) so a new job starts from the
     configured defaults, not the built-in ones. -->
<script type="text/html" id="ur-job-template">
<?php
    $templateJob = Config::defaultJob();
    $templateJob['rsyncOptions'] = $globalOpts; // seed from Global Settings
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

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.classList) { return; }

    if (t.id === 'ur-add-job') {
      addJob();
    } else if (t.classList.contains('ur-pair-add')) {
      var pr = document.getElementById(t.getAttribute('data-rows'));
      if (pr) { addPair(pr); }
    } else if (t.classList.contains('ur-pair-del')) {
      var prow = t.closest ? t.closest('.ur-pair-row') : null;
      if (prow && prow.parentNode) { prow.parentNode.removeChild(prow); }
    } else if (t.classList.contains('ur-job-del')) {
      var card = t.closest ? t.closest('.ur-job-card') : null;
      if (card && card.parentNode) { card.parentNode.removeChild(card); }
    } else if (t.classList.contains('ur-row-add')) {
      var or = document.getElementById(t.getAttribute('data-rows'));
      if (or) { addOptRow(or); }
    } else if (t.classList.contains('ur-row-del')) {
      var orow = t.closest ? t.closest('.ur-row') : null;
      if (orow && orow.parentNode) { orow.parentNode.removeChild(orow); }
    }
  });

  /* Toggle a job's options block when "use global defaults" changes. */
  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t && t.classList && t.classList.contains('ur-use-global')) {
      var target = document.getElementById(t.getAttribute('data-target'));
      if (target) { target.style.display = t.checked ? 'none' : ''; }
    }
  });

  /* Submit via fetch; show validation errors/warnings inline. */
  var form = document.getElementById('ur-jobs-form');
  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var result = document.getElementById('ur-jobs-result');
      var fd = new FormData(form);
      fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
          if (res.ok && res.body && res.body.ok) {
            var msg = res.body.message || 'Saved.';
            if (res.body.warnings && res.body.warnings.length) {
              msg += ' (' + res.body.warnings.join('; ') + ')';
            }
            result.className = 'ur-result ur-ok';
            result.textContent = msg;
            /* Reload so the summary table reflects the saved state. */
            setTimeout(function () { window.location.reload(); }, 600);
          } else {
            var errs = (res.body && res.body.errors) ? res.body.errors.join('; ')
                     : ((res.body && res.body.error) ? res.body.error : 'Save failed.');
            result.className = 'ur-result ur-err';
            result.textContent = errs;
          }
        })
        .catch(function () {
          result.className = 'ur-result ur-err';
          result.textContent = 'Network error while saving.';
        });
    });
  }
})();
</script>

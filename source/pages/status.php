<?php
/**
 * status.php - the Status / Log tab body (Phase 6).
 *
 * Renders:
 *   - an overall running/idle indicator + a live list of currently-running jobs,
 *     each with an Abort control (CSRF-checked POST);
 *   - a viewer for the rolling cross-job plugin.log, auto-tailed every 1s.
 *
 * The plugin-log text is fetched from the read-only getPluginLog GET poller and
 * is ALREADY HTML-escaped server-side (Logger::tail). We inject it into a <pre>
 * as escaped HTML and never build raw innerHTML from unescaped log bytes (the
 * log-XSS guard). The running-jobs list and badges come from getStatus.
 *
 * Native look comes from the inherited dynamix CSS/JS; we reuse the badge styles
 * defined on the Jobs tab and add only a couple of local rules for this tab.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Config.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/RunState.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Rsync.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php'; // ur_render_csrf_token + ur_js

$csrf = ur_render_csrf_token();
$handlerUrl = '/plugins/unraid.rsync/include/handler.php';

// Initial running/idle snapshot, rendered server-side so the tab is meaningful
// before the first poll completes. A config read error is non-fatal here - the
// poller will surface it; we just render an empty list.
$initialRunning = [];
// Total configured jobs, so the tab can show an actionable empty state when none
// are defined yet (a config read error leaves this at 0, which only suppresses
// the empty-state hint — never a false "no jobs" when some exist).
$jobCount = 0;
try {
    $config = Config::load();
    foreach (($config['jobs'] ?? []) as $job) {
        if (!is_array($job)) {
            continue;
        }
        $jobCount++;
        $jid = (string) ($job['id'] ?? '');
        if ($jid !== '' && RunState::isRunning($jid)) {
            $initialRunning[$jid] = (string) ($job['name'] ?? $jid);
        }
    }
} catch (Throwable $e) {
    $initialRunning = [];
    $jobCount = 0;
}

// rsync presence snapshot (FIX 3): rsync ships in Unraid's base OS, so the
// expected state is "present". We never install it - this is a defensive check
// surfaced here so a misconfigured system is obvious. Rendered server-side and
// refreshed once via the getRsyncStatus poller.
$rsyncAvailable   = Rsync::rsyncAvailable();
$rsyncPath        = Rsync::rsyncPath();
$rsyncVersionLine = $rsyncAvailable ? Rsync::rsyncVersionLine() : '';
$rsyncMissingMsg  = $rsyncAvailable ? '' : Rsync::rsyncMissingMessage();
?>
<style>
/* Reuse the Jobs-tab badge palette; define it here too so the Status tab is
   self-contained (the tabs are separate page bodies). */
.ur-badge {
  display: inline-block; min-width: 64px; padding: 2px 10px; border-radius: 10px;
  font-size: 11px; font-weight: bold; text-align: center; color: #fff;
  line-height: 1.6; white-space: nowrap;
}
.ur-badge-running { background: #1565c0; animation: ur-pulse 1.3s ease-in-out infinite; }
.ur-badge-idle    { background: #1c7d3f; }
/* Warning badge: dark text on a darkened orange for WCAG AA contrast — kept in
   lockstep with the Jobs tab's .ur-badge-warning. */
.ur-badge-warning { background: #b15c00; color: #1a1a1a; }
.ur-badge-failed  { background: var(--red-800, #b71c1c); }
@keyframes ur-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.55; } }
.ur-rsync-status { margin: 8px 0; }
.ur-rsync-status #ur-rsync-detail { margin-top: 6px; }
.ur-rsync-status .ur-err { color: var(--red-800, #b71c1c); }
.ur-status-running-list { list-style: none; padding: 0; margin: 8px 0; }
.ur-status-running-list li { margin: 4px 0; }
.ur-status-running-list .ur-job-name { display: inline-block; min-width: 220px; }
.ur-plugin-log-pre {
  overflow: auto; margin: 0; background: #000; color: #d0d0d0;
  padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;
  white-space: pre-wrap; word-break: break-word; min-height: 280px; max-height: 60vh;
}
/* Placeholder shown when the plugin log is empty (no run yet). Muted, non-mono so
   it reads as a hint rather than log content. */
.ur-plugin-log-pre.ur-log-empty { color: #888; font-style: italic; }
/* Empty-state hint shown when there are zero jobs. A plain visible callout — NOT
   blockquote.inline_help, which the dynamix base CSS hides by default
   (.inline_help { display:none }), so it would be invisible without help mode. */
.ur-empty-state {
  margin: 10px 0; padding: 10px 12px; border-radius: 4px;
  background: var(--blue-100, #d9edf7);
  border: 1px solid var(--blue-200, #bce8f1);
  color: var(--blockquote-text-color, #31708f);
}
</style>

<div class="title">
  <span class="left">
    <i class="fa fa-refresh title"></i>&nbsp;<?=_('Status')?>
  </span>
</div>

<p>
  <?=_('Live status of all rsync jobs and the rolling plugin log. This page updates automatically while a job is running')?>.
</p>

<?php if ($jobCount === 0): ?>
<!-- Empty state: nothing to monitor until a job exists. Point the user at the
     Jobs tab so the tab is actionable rather than blank. A visible .ur-empty-state
     callout (NOT blockquote.inline_help, which the base CSS hides by default). -->
<div class="ur-empty-state"><?=_('No jobs configured yet — add one in the Jobs tab')?>.</div>
<?php endif; ?>

<!-- Overall running/idle indicator + currently-running jobs (with Abort) ------>
<div class="ur-status-overall">
  <span id="ur-overall-badge" class="ur-badge <?=empty($initialRunning) ? 'ur-badge-idle' : 'ur-badge-running'?>">
    <?=empty($initialRunning) ? _('Idle') : _('Running')?>
  </span>
  &nbsp;<span id="ur-overall-text"><?php
    echo empty($initialRunning)
      ? htmlspecialchars(_('No jobs are running.'), ENT_QUOTES, 'UTF-8')
      : htmlspecialchars(sprintf(_('%d job(s) running.'), count($initialRunning)), ENT_QUOTES, 'UTF-8');
  ?></span>
</div>

<ul id="ur-running-jobs" class="ur-status-running-list">
  <?php foreach ($initialRunning as $jid => $jname): ?>
    <li data-jobid="<?=htmlspecialchars($jid, ENT_QUOTES, 'UTF-8')?>">
      <span class="ur-job-name"><?=htmlspecialchars($jname, ENT_QUOTES, 'UTF-8')?></span>
      <span class="ur-badge ur-badge-running"><?=_('Running')?></span>
      <button type="button" class="ur-status-abort" data-jobid="<?=htmlspecialchars($jid, ENT_QUOTES, 'UTF-8')?>"><?=_('Abort')?></button>
    </li>
  <?php endforeach; ?>
</ul>
<div id="ur-status-result" class="ur-result"></div>

<!-- rsync binary presence (FIX 3: detect, never install) --------------------->
<div class="title">
  <span class="left"><i class="fa fa-terminal title"></i>&nbsp;<?=_('rsync binary')?></span>
</div>
<div id="ur-rsync-status" class="ur-rsync-status">
  <span id="ur-rsync-badge" class="ur-badge <?=$rsyncAvailable ? 'ur-badge-idle' : 'ur-badge-failed'?>">
    <?=$rsyncAvailable ? _('Present') : _('Missing')?>
  </span>
  &nbsp;<code id="ur-rsync-path"><?=htmlspecialchars($rsyncPath, ENT_QUOTES, 'UTF-8')?></code>
  <div id="ur-rsync-detail">
    <?php if ($rsyncAvailable): ?>
      <code id="ur-rsync-version"><?=htmlspecialchars($rsyncVersionLine !== '' ? $rsyncVersionLine : _('(version unavailable)'), ENT_QUOTES, 'UTF-8')?></code>
    <?php else: ?>
      <span id="ur-rsync-version" class="ur-err"><?=htmlspecialchars($rsyncMissingMsg, ENT_QUOTES, 'UTF-8')?></span>
    <?php endif; ?>
  </div>
  <blockquote class="inline_help"><p>
    <?=_('rsync is part of the Unraid base OS, so it is normally always present. This plugin does not install rsync; if it is missing your system may be misconfigured')?>.
  </p></blockquote>
</div>

<!-- Rolling plugin log viewer ------------------------------------------------>
<div class="title">
  <span class="left"><i class="fa fa-file-text-o title"></i>&nbsp;<?=_('Plugin log')?></span>
</div>
<pre id="ur-plugin-log-pre" class="ur-plugin-log-pre"><?=_('Loading…')?></pre>

<script type="text/javascript">
(function () {
  'use strict';

  var HANDLER_URL = <?=ur_js($handlerUrl)?>;
  var CSRF_TOKEN  = <?=ur_js($csrf)?>;
  var STATUS_URL  = HANDLER_URL + '?action=getStatus';
  var PLUGIN_LOG_URL = HANDLER_URL + '?action=getPluginLog';

  function cssEsc(v) { return String(v).replace(/["\\]/g, '\\$&'); }

  /* ---- running-jobs list + overall indicator (getStatus poll) ---- */
  function applyStatus(payload) {
    var jobs = (payload && payload.jobs) ? payload.jobs : {};
    var list = document.getElementById('ur-running-jobs');
    var badge = document.getElementById('ur-overall-badge');
    var text  = document.getElementById('ur-overall-text');
    if (!list) { return; }

    var running = [];
    Object.keys(jobs).forEach(function (jobId) {
      if (jobs[jobId] && jobs[jobId].running) { running.push(jobId); }
    });

    /* Rebuild the running list from scratch each poll (it is small). getStatus
     * carries the job name; fall back to a previously-rendered name, then the
     * id, so a newly-started job still shows a human-friendly label. */
    var existingNames = {};
    Array.prototype.forEach.call(list.querySelectorAll('li[data-jobid]'), function (li) {
      var nm = li.querySelector('.ur-job-name');
      existingNames[li.getAttribute('data-jobid')] = nm ? nm.textContent : li.getAttribute('data-jobid');
    });

    list.innerHTML = '';
    running.forEach(function (jobId) {
      var li = document.createElement('li');
      li.setAttribute('data-jobid', jobId);
      var name = document.createElement('span');
      name.className = 'ur-job-name';
      var label = (jobs[jobId] && jobs[jobId].name) || existingNames[jobId] || jobId;
      name.textContent = label;                           // textContent: no XSS
      var b = document.createElement('span');
      b.className = 'ur-badge ur-badge-running';
      b.textContent = 'Running';
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ur-status-abort';
      btn.setAttribute('data-jobid', jobId);
      btn.textContent = 'Abort';
      li.appendChild(name);
      li.appendChild(document.createTextNode(' '));
      li.appendChild(b);
      li.appendChild(document.createTextNode(' '));
      li.appendChild(btn);
      list.appendChild(li);
    });

    if (badge && text) {
      if (running.length) {
        badge.className = 'ur-badge ur-badge-running';
        badge.textContent = 'Running';
        text.textContent = running.length + ' job(s) running.';
      } else {
        badge.className = 'ur-badge ur-badge-idle';
        badge.textContent = 'Idle';
        text.textContent = 'No jobs are running.';
      }
    }
  }

  function pollStatus() {
    fetch(STATUS_URL, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(applyStatus)
      .catch(function () { /* transient */ });
  }

  /* ---- plugin log tail ---- */
  var pre = document.getElementById('ur-plugin-log-pre');
  function pollPluginLog() {
    fetch(PLUGIN_LOG_URL, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (body) {
        if (!pre || !body || !body.ok) { return; }
        /* Empty plugin log => nothing has been written yet (no job has run since
         * boot; the log lives in tmpfs and is cleared on reboot). Show an explicit
         * placeholder instead of a blank box that reads as broken. */
        if (!body.log) {
          pre.classList.add('ur-log-empty');
          pre.textContent = 'No log entries yet. The plugin log fills as jobs run; it lives in memory and is cleared on reboot.';
          return;
        }
        pre.classList.remove('ur-log-empty');
        /* body.log is ALREADY HTML-escaped server-side; inject as escaped HTML
         * so the <pre> shows it verbatim. Preserve the scroll-to-bottom UX. */
        var atBottom = (pre.scrollTop + pre.clientHeight) >= (pre.scrollHeight - 20);
        pre.innerHTML = body.log || '';
        if (atBottom) { pre.scrollTop = pre.scrollHeight; }
      })
      .catch(function () { /* transient */ });
  }

  /* ---- abort control ---- */
  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.classList || !t.classList.contains('ur-status-abort')) { return; }
    var jobId = t.getAttribute('data-jobid');
    if (!jobId) { return; }
    var result = document.getElementById('ur-status-result');
    t.disabled = true;
    /* urlencoded (URLSearchParams), NOT multipart (FormData): a
       multipart/form-data body stalls in php-fpm in the live Unraid environment,
       so the POST never returns. fetch() auto-sets the urlencoded Content-Type. */
    var params = new URLSearchParams();
    params.append('action', 'abortJob');
    params.append('csrf_token', CSRF_TOKEN);
    params.append('id', jobId);
    fetch(HANDLER_URL, { method: 'POST', body: params, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
      .then(function (res) {
        var ok = res.ok && res.body && res.body.ok;
        if (result) {
          if (ok) {
            result.className = 'ur-result ur-ok';
            result.textContent = res.body.message || 'Abort requested.';
          } else {
            result.className = 'ur-result ur-err';
            result.textContent = (res.body && res.body.error) ? res.body.error : 'Abort failed.';
          }
        }
        /* On an application-level failure the abort didn't take, so re-enable the
         * button. On success the next poll rebuilds the list and the button goes
         * away with its row. */
        if (!ok) { t.disabled = false; }
        pollStatus();
      })
      .catch(function () {
        if (result) {
          result.className = 'ur-result ur-err';
          result.textContent = 'Network error.';
        }
        t.disabled = false;
      });
  });

  /* ---- rsync presence (one-shot; it does not change while the page is open) ---- */
  var RSYNC_STATUS_URL = HANDLER_URL + '?action=getRsyncStatus';
  function refreshRsyncStatus() {
    fetch(RSYNC_STATUS_URL, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (body) {
        if (!body || !body.ok) { return; }
        var badge   = document.getElementById('ur-rsync-badge');
        var pathEl  = document.getElementById('ur-rsync-path');
        var verEl   = document.getElementById('ur-rsync-version');
        if (pathEl && body.path) { pathEl.textContent = body.path; }   // textContent: no XSS
        if (badge) {
          badge.className = 'ur-badge ' + (body.available ? 'ur-badge-idle' : 'ur-badge-failed');
          badge.textContent = body.available ? 'Present' : 'Missing';
        }
        if (verEl) {
          /* textContent only - never innerHTML - so the version/message string
           * (from the trusted local binary, but escaped defensively) is inert. */
          if (body.available) {
            verEl.className = '';
            verEl.textContent = body.version || '(version unavailable)';
          } else {
            verEl.className = 'ur-err';
            verEl.textContent = body.message || 'rsync not found.';
          }
        }
      })
      .catch(function () { /* keep the server-rendered snapshot */ });
  }

  /* Poll status + plugin log every 1s; check rsync presence once. */
  pollStatus();
  pollPluginLog();
  refreshRsyncStatus();
  setInterval(pollStatus, 1000);
  setInterval(pollPluginLog, 1000);
})();
</script>

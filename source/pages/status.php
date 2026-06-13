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

$csrf = '';
if (isset($GLOBALS['var']) && is_array($GLOBALS['var']) && !empty($GLOBALS['var']['csrf_token'])) {
    $csrf = (string) $GLOBALS['var']['csrf_token'];
}
$handlerUrl = '/plugins/unraid.rsync/include/handler.php';

// Initial running/idle snapshot, rendered server-side so the tab is meaningful
// before the first poll completes. A config read error is non-fatal here - the
// poller will surface it; we just render an empty list.
$initialRunning = [];
try {
    $config = Config::load();
    foreach (($config['jobs'] ?? []) as $job) {
        if (!is_array($job)) {
            continue;
        }
        $jid = (string) ($job['id'] ?? '');
        if ($jid !== '' && RunState::isRunning($jid)) {
            $initialRunning[$jid] = (string) ($job['name'] ?? $jid);
        }
    }
} catch (Throwable $e) {
    $initialRunning = [];
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
</style>

<div class="title">
  <span class="left">
    <i class="fa fa-refresh title"></i>&nbsp;<?=_('Status')?>
  </span>
</div>

<p>
  <?=_('Live status of all rsync jobs and the rolling plugin log. This page updates automatically while a job is running')?>.
</p>

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

  var HANDLER_URL = <?=json_encode($handlerUrl)?>;
  var CSRF_TOKEN  = <?=json_encode($csrf)?>;
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
    var fd = new FormData();
    fd.append('action', 'abortJob');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('id', jobId);
    fetch(HANDLER_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
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

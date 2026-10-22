<?php
/**
 * status.php - the Status / Log tab body.
 *
 * Renders:
 *   - an overall running/idle indicator + a live list of currently-running jobs,
 *     each with an Abort control (CSRF-checked POST);
 *   - a viewer for the rolling cross-job plugin.log, tailed incrementally
 *     (getPluginLog&since=) only while it is on screen.
 *
 * The plugin-log text is fetched from the read-only getPluginLog GET poller and
 * is ALREADY HTML-escaped server-side (Logger::readFrom). We inject it into a
 * <pre> as escaped HTML and never build raw innerHTML from unescaped log bytes
 * (the log-XSS guard). The running-jobs list comes from the shared getStatus
 * poller (window.urStatus).
 *
 * Native look comes from the inherited dynamix CSS/JS; we reuse the badge styles
 * defined on the Jobs tab and add only a couple of local rules for this tab.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Config.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/RunState.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Rsync.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php'; // ur_render_csrf_token + ur_js

$csrf = ur_render_csrf_token();
$handlerUrl = PageHelpers::HANDLER_URL;

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

/* The ONE badge palette + window.urBadge (shared with Jobs / History /
 * Overview), and the shared AJAX helpers the abort POST below uses. Both must be
 * emitted BEFORE the markup and script that follow. */
ur_emit_badge_assets();
ur_emit_ajax_helpers();
ur_emit_status_poller();
?>
<style>
.ur-rsync-status { margin: 8px 0; }
.ur-rsync-status #ur-rsync-detail { margin-top: 6px; }
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
.ur-plugin-log-pre.ur-nowrap { white-space: pre; word-break: normal; }
.ur-plugin-log-wrap { position: relative; }
.ur-plugin-log-tools { display: flex; gap: 8px 12px; align-items: center; flex-wrap: wrap; margin: 6px 0; }
.ur-plugin-log-tools select { width: auto; max-width: 260px; }
.ur-log-jump { position: absolute; right: 18px; bottom: 10px; }
</style>

<div class="title">
  <span class="left">
    <i class="fa fa-bars title"></i>&nbsp;<?=_('Status')?>
  </span>
</div>

<p>
  <?=_('Live status of all rsync jobs and the rolling plugin log. It refreshes on its own while the page is open: every 2 seconds while a job is running, every 15 seconds otherwise')?>.
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
      ? ur_h(ur_t_text('No jobs are running.'))
      : ur_h(sprintf(ur_t_text('%d job(s) running.'), count($initialRunning)));
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
  <span id="ur-rsync-badge" class="ur-badge <?=$rsyncAvailable ? 'ur-badge-success' : 'ur-badge-failed'?>">
    <?=$rsyncAvailable ? _('Present') : _('Missing')?>
  </span>
  &nbsp;<code id="ur-rsync-path"><?=htmlspecialchars($rsyncPath, ENT_QUOTES, 'UTF-8')?></code>
  <div id="ur-rsync-detail">
    <?php if ($rsyncAvailable): ?>
      <code id="ur-rsync-version"><?=ur_h($rsyncVersionLine !== '' ? $rsyncVersionLine : ur_t_text('(version unavailable)'))?></code>
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
<div class="ur-plugin-log-tools">
  <label for="ur-log-job"><?=_('Job')?>:</label>
  <select id="ur-log-job"><option value=""><?=_('All jobs')?></option></select>
  <label><input type="checkbox" id="ur-log-wrap" checked> <?=_('Wrap lines')?></label>
  <button type="button" id="ur-log-copy" class="ur-btn ur-btn-copy"><?=_('Copy')?></button>
  <a id="ur-log-download" class="ur-btn ur-btn-dl" href="<?=ur_h($handlerUrl . '?action=downloadPluginLog')?>" download>&#11015;&nbsp;<?=_('Download full log')?></a>
</div>
<div id="ur-log-result" class="ur-result"></div>
<div class="ur-plugin-log-wrap">
  <pre id="ur-plugin-log-pre" class="ur-plugin-log-pre"><?=_('Loading…')?></pre>
  <button type="button" id="ur-log-jump" class="ur-log-jump" style="display:none"><?=_('Jump to latest')?></button>
</div>

<script type="text/javascript">
(function () {
  'use strict';

  var HANDLER_URL = <?=ur_js($handlerUrl)?>;
  var CSRF_TOKEN  = <?=ur_js($csrf)?>;

  /* ---- running-jobs list + overall indicator (shared getStatus poller) ---- */
  var shownRunning = null;
  function applyStatus(payload) {
    var jobs = payload.jobs || {};
    var list = document.getElementById('ur-running-jobs');
    var badge = document.getElementById('ur-overall-badge');
    var text  = document.getElementById('ur-overall-text');
    if (!list) { return; }

    var running = Object.keys(jobs).filter(function (id) { return jobs[id] && jobs[id].running; });
    /* Rebuild only when the set changes, so an Abort button already clicked
     * (disabled) or focused is not replaced under the pointer every poll. */
    var sig = JSON.stringify(running.map(function (id) { return [id, jobs[id].name || '']; }));
    if (sig !== shownRunning) {
      shownRunning = sig;
      list.textContent = '';
      running.forEach(function (jobId) {
        var li = document.createElement('li');
        li.setAttribute('data-jobid', jobId);
        var name = document.createElement('span');
        name.className = 'ur-job-name';
        name.textContent = jobs[jobId].name || jobId;
        var b = document.createElement('span');
        b.className = 'ur-badge';
        window.urBadge.apply(b, 'RUNNING');
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
    }

    if (badge && text) {
      if (running.length) {
        badge.classList.remove('ur-badge-idle');
        window.urBadge.apply(badge, 'RUNNING');
        text.textContent = running.length + ' job(s) running.';
      } else {
        /* Idle is not a run state, so it is not in the badge vocabulary. */
        window.urBadge.CLASSES.forEach(function (c) { badge.classList.remove(c); });
        badge.classList.add('ur-badge-idle');
        badge.textContent = 'Idle';
        text.textContent = 'No jobs are running.';
      }
    }
  }
  window.urStatus.subscribe(applyStatus);

  /* ---- plugin log: incremental tail, only while on screen ----
   * Every tab body shares one document, so this would otherwise poll whichever
   * tab is open. It fetches only while the <pre> is actually visible - every 3 s
   * while a job runs, every 10 s otherwise, at once when it comes into view -
   * and only the bytes after `offset`, which it appends. */
  var pre  = document.getElementById('ur-plugin-log-pre');
  var jump = document.getElementById('ur-log-jump');
  var EMPTY_TEXT = 'No log entries yet. The plugin log fills as jobs run; it lives in memory and is cleared on reboot.';
  var MAX_CHARS = 262144;
  var offset = 0, wantReset = true, inflight = false, lastFetch = 0, wasVisible = false;
  /* Escaped HTML waiting to be written (null = nothing), and whether it
   * replaces the box or extends it. */
  var pending = null, replaceNext = false;
  /* Follow the newest lines until the user scrolls up; scrolling back to the
   * bottom (or Jump to latest) resumes following. */
  var follow = true;

  function onScreen() { return !document.hidden && pre.offsetParent !== null; }
  function atBottom() { return pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 20; }
  function toBottom() { pre.scrollTop = pre.scrollHeight; }
  function syncJump() { jump.style.display = follow ? 'none' : ''; }

  /* A drag-selection inside the log must survive the next poll. */
  function selecting() {
    var sel = window.getSelection ? window.getSelection() : null;
    if (!sel || sel.isCollapsed || !sel.rangeCount) { return false; }
    return pre.contains(sel.anchorNode) || pre.contains(sel.focusNode);
  }

  /* ---- job filter: one span per line, tagged with the job id that
   * Logger::event writes as the second bracket ("[<stamp>] [<job id>] ...").
   * The id is slug-shaped, so it is safe in the attribute; the line itself is
   * already escaped. Lines of other jobs are hidden, not dropped, so changing
   * the filter needs no refetch. */
  var jobSel = document.getElementById('ur-log-job');
  var LINE_JOB = /^\[[^\]]*\] \[([A-Za-z0-9._-]+)\]/;
  var seenJobs = {};
  function addJobOption(id) {
    var last = window.urStatus.last();
    var j = last && last.jobs ? last.jobs[id] : null;
    var o = document.createElement('option');
    o.value = id;
    o.textContent = j && j.name ? j.name + ' (' + id + ')' : id;
    jobSel.appendChild(o);
  }
  function linesHtml(escaped) {
    var lines = escaped.split('\n');
    if (lines[lines.length - 1] === '') { lines.pop(); }
    return lines.map(function (line) {
      var m = LINE_JOB.exec(line);
      var id = m ? m[1] : '';
      if (id && !seenJobs[id]) { seenJobs[id] = true; addJobOption(id); }
      var hide = jobSel.value !== '' && id !== jobSel.value;
      return '<span class="ur-pl-line" data-job="' + id + '"' + (hide ? ' hidden' : '') + '>' + line + '\n</span>';
    }).join('');
  }
  jobSel.addEventListener('change', function () {
    var want = jobSel.value;
    Array.prototype.forEach.call(pre.querySelectorAll('.ur-pl-line'), function (el) {
      el.hidden = want !== '' && el.getAttribute('data-job') !== want;
    });
    if (follow) { toBottom(); }
  });

  /* `pending` is ALREADY HTML-escaped server-side (Logger::readFrom). */
  function render() {
    if (pending === null || selecting()) { return; }
    if (replaceNext) {
      pre.classList.toggle('ur-log-empty', pending === '');
      if (pending === '') { pre.textContent = EMPTY_TEXT; } else { pre.innerHTML = linesHtml(pending); }
    } else {
      pre.insertAdjacentHTML('beforeend', linesHtml(pending));
    }
    pending = null;
    replaceNext = false;
    if (follow) { toBottom(); }
  }

  function fetchLog() {
    if (inflight) { return; }
    inflight = true;
    lastFetch = Date.now();
    if (pre.textContent.length > MAX_CHARS) { wantReset = true; }
    var since = wantReset ? 0 : offset;
    fetch(HANDLER_URL + '?action=getPluginLog&since=' + since, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (body) {
        if (!body || !body.ok || typeof body.offset !== 'number') { return; }
        var text = body.text || '';
        /* A truncated chunk opens with a marker line standing in for the gap,
         * so it is appended like any other unless a reset was asked for. */
        if (wantReset || body.reset
            || (text !== '' && pending === null && pre.classList.contains('ur-log-empty'))) {
          pending = text;
          replaceNext = true;
        } else if (text !== '') {
          pending = (pending === null ? '' : pending) + text;
        }
        wantReset = false;
        offset = body.offset;
        render();
      })
      .catch(function () { /* transient; the next tick retries */ })
      .then(function () { inflight = false; });
  }

  pre.addEventListener('scroll', function () {
    if (!pre.clientHeight) { return; }
    follow = atBottom();
    syncJump();
  });
  jump.addEventListener('click', function () {
    follow = true;
    toBottom();
    syncJump();
  });

  /* A 1 s local tick, requesting nothing unless a fetch is due: it notices the
   * tab coming into view (no event reports that) and writes output held back
   * while text was selected. */
  function tick() {
    var vis = onScreen();
    if (vis && !wasVisible) {
      if (follow) { toBottom(); }
      fetchLog();
    } else if (vis) {
      render();
      if (Date.now() - lastFetch >= (window.urStatus.anyRunning() ? 3000 : 10000)) { fetchLog(); }
    }
    wasVisible = vis;
  }
  tick();
  setInterval(tick, 1000);

  document.getElementById('ur-log-wrap').addEventListener('change', function (ev) {
    pre.classList.toggle('ur-nowrap', !ev.target.checked);
  });
  /* Copy what is shown (innerText skips the lines the job filter hides);
   * Download is the whole plugin.log, every job. */
  var copyBtn = document.getElementById('ur-log-copy');
  copyBtn.addEventListener('click', function () { window.urUtil.copyText(pre.innerText || '', copyBtn, pre); });
  var dlLink = document.getElementById('ur-log-download');
  dlLink.addEventListener('click', function (ev) {
    ev.preventDefault();
    window.urUtil.downloadFile(dlLink.href, function (msg) {
      window.urAjax.show(document.getElementById('ur-log-result'), false, msg);
    });
  });

  /* ---- abort control ---- */
  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.classList || !t.classList.contains('ur-status-abort')) { return; }
    var jobId = t.getAttribute('data-jobid');
    if (!jobId) { return; }
    var result = document.getElementById('ur-status-result');
    t.disabled = true;
    /* window.urAjax.postForm: urlencoded (never multipart - a multipart body
       stalls php-fpm on the live box) and it NEVER rejects, so a non-JSON 403/500
       becomes a visible error WITH its HTTP status instead of a silent
       "Network error". */
    window.urAjax.postForm(HANDLER_URL, { action: 'abortJob', id: jobId }, CSRF_TOKEN)
      .then(function (res) {
        var ok = res.ok && res.body && res.body.ok;
        if (result) {
          if (ok) {
            window.urAjax.show(result, true, res.body.message || 'Abort requested.');
          } else {
            window.urAjax.show(result, false, window.urAjax.errText(res, 'Abort failed.'));
          }
        }
        /* On an application-level failure the abort didn't take, so re-enable the
         * button. On success the next poll drops the row and its button. */
        if (!ok) { t.disabled = false; }
        window.urStatus.refresh();
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
          badge.className = 'ur-badge ' + (body.available ? 'ur-badge-success' : 'ur-badge-failed');
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
  refreshRsyncStatus();
})();
</script>

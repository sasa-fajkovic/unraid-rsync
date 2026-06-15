<?php
/**
 * history.php - the History tab body.
 *
 * A paginated table of every PAST execution (real + dry, manual + scheduled) for
 * a selected job, read from the persistent History records via the read-only
 * listHistory GET poller. Columns: when, trigger, type (real/dry), status badge,
 * exit code, duration, and a Logs action that opens that run's log (getJobLog
 * with the record's logRef as ?run=). Server-side paging (page size 25) so up to
 * the 9999-record retention cap never ships at once.
 *
 * History is NOT live data (it only changes when a run finishes), so it is
 * fetched on tab open + on job/page change - NO 1s poller. Every dynamic field
 * is written via textContent (never innerHTML) so a job name / log path can't
 * inject markup; the log body from getJobLog is already HTML-escaped server-side
 * (Logger::tail) and is the only thing injected as HTML, into a <pre>.
 *
 * Reuses ur_render_csrf_token + ur_js from the shared options partial; the badge
 * palette is redefined locally because each tab is a separate page body.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Config.php';
// ur_render_csrf_token + ur_js helpers:
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php';

$csrf       = ur_render_csrf_token();
$handlerUrl = '/plugins/unraid.rsync/include/handler.php';

// Job id => name for the filter select. A config read error just leaves the list
// empty (the empty state then points the user at the Jobs tab).
$jobs = [];
try {
    $config = Config::load();
    foreach (($config['jobs'] ?? []) as $job) {
        if (!is_array($job)) {
            continue;
        }
        $jid = (string) ($job['id'] ?? '');
        if ($jid !== '') {
            $jobs[$jid] = (string) ($job['name'] ?? $jid);
        }
    }
} catch (Throwable $e) {
    $jobs = [];
}
?>
<style>
/* Badge palette (mirrors the Status/Jobs tabs; each tab is its own page body). */
.ur-badge {
  display: inline-block; min-width: 64px; padding: 2px 10px; border-radius: 10px;
  font-size: 11px; font-weight: bold; text-align: center; color: #fff;
  line-height: 1.6; white-space: nowrap;
}
.ur-badge-idle    { background: #1c7d3f; }
.ur-badge-warning { background: #b15c00; color: #1a1a1a; }
.ur-badge-failed  { background: var(--red-800, #b71c1c); }
.ur-badge-aborted { background: #555; }
.ur-badge-running { background: #1565c0; animation: ur-badge-pulse 1.2s ease-in-out infinite; }
@keyframes ur-badge-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.55; } }
.ur-hist-controls { margin: 8px 0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.ur-hist-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
.ur-hist-table th, .ur-hist-table td { padding: 6px 10px; text-align: left; border-bottom: 1px solid var(--border-color, #444); white-space: nowrap; }
.ur-hist-table td.ur-hist-name { white-space: normal; word-break: break-word; }
.ur-hist-pager { margin: 10px 0; display: flex; gap: 12px; align-items: center; }
.ur-hist-pager button[disabled] { opacity: 0.5; cursor: default; }
.ur-trigger-icon { opacity: 0.8; margin-right: 4px; }
.ur-empty-state {
  margin: 10px 0; padding: 10px 12px; border-radius: 4px;
  background: var(--blue-100, #d9edf7); border: 1px solid var(--blue-200, #bce8f1);
  color: var(--blockquote-text-color, #31708f);
}
/* Log modal (self-contained; the Jobs tab has its own). */
.ur-hist-modal { position: fixed; inset: 0; z-index: 11000; background: rgba(0,0,0,0.5); display: none; }
.ur-hist-modal.ur-open { display: block; }
.ur-hist-modal-box {
  position: absolute; top: 5vh; left: 50%; transform: translateX(-50%);
  width: min(900px, 92vw); max-height: 86vh; overflow: hidden;
  background: var(--background-color, #1c1c1c); border-radius: 6px; padding: 14px;
  display: flex; flex-direction: column;
}
.ur-hist-modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; gap: 8px; }
.ur-hist-modal-actions { display: flex; align-items: center; gap: 6px; }
/* Modal action buttons. "Download full log" is a prominent green button (it was
   an easy-to-miss text link); Copy is blue. */
.ur-btn {
  display: inline-block; padding: 5px 12px; border-radius: 4px; cursor: pointer;
  font-size: 12px; font-weight: bold; text-decoration: none; border: none; line-height: 1.5;
}
.ur-btn-dl   { background: var(--green, #1c7d3f); color: #fff; }
.ur-btn-copy { background: var(--blue-500, #216feb); color: #fff; }
.ur-btn:hover { filter: brightness(1.12); }
/* Per-row "Download" link sits next to the View button as a small text button. */
.ur-hist-dl-row { margin-left: 8px; text-decoration: none; color: var(--blue-500, #2196f3); font-weight: bold; }
.ur-hist-dl-row:hover { text-decoration: underline; }
.ur-hist-log-pre {
  overflow: auto; margin: 0; background: #000; color: #d0d0d0; padding: 10px;
  border-radius: 4px; font-family: monospace; font-size: 12px;
  white-space: pre-wrap; word-break: break-word; min-height: 240px; max-height: 70vh;
}
</style>

<div class="title">
  <span class="left"><i class="fa fa-history title"></i>&nbsp;<?=_('History')?></span>
</div>

<p>
  <?=_('Past executions across all jobs — real runs and dry-runs, started manually or on schedule. Use the filter to narrow to one job. How many are kept per job is the "Keep last N executions" setting on the Global Settings tab')?>.
</p>

<?php if (empty($jobs)): ?>
<div class="ur-empty-state"><?=_('No jobs configured yet — add one in the Jobs tab')?>.</div>
<?php else: ?>

<div class="ur-hist-controls">
  <label for="ur-hist-job"><?=_('Filter by job')?>:</label>
  <select id="ur-hist-job">
    <option value="" selected><?=_('All jobs')?></option>
    <?php foreach ($jobs as $jid => $jname): ?>
      <option value="<?=htmlspecialchars($jid, ENT_QUOTES, 'UTF-8')?>"><?=htmlspecialchars($jname, ENT_QUOTES, 'UTF-8')?></option>
    <?php endforeach; ?>
  </select>
</div>

<table class="ur-hist-table">
  <thead>
    <tr>
      <th><?=_('Job')?></th>
      <th><?=_('When')?></th>
      <th><?=_('Trigger')?></th>
      <th><?=_('Type')?></th>
      <th><?=_('Status')?></th>
      <th><?=_('Exit')?></th>
      <th><?=_('Duration')?></th>
      <th><?=_('Logs')?></th>
    </tr>
  </thead>
  <tbody id="ur-hist-rows">
    <tr><td colspan="8"><?=_('Loading…')?></td></tr>
  </tbody>
</table>

<div class="ur-hist-pager">
  <button type="button" id="ur-hist-prev"><?=_('‹ Newer')?></button>
  <span id="ur-hist-pageinfo"></span>
  <button type="button" id="ur-hist-next"><?=_('Older ›')?></button>
</div>

<!-- Run-log modal ----------------------------------------------------------->
<div id="ur-hist-modal" class="ur-hist-modal" role="dialog" aria-modal="true"
     aria-labelledby="ur-hist-modal-title" aria-hidden="true">
  <div class="ur-hist-modal-box">
    <div class="ur-hist-modal-head">
      <strong id="ur-hist-modal-title"><?=_('Run log')?></strong>
      <span class="ur-hist-modal-actions">
        <a id="ur-hist-log-download" class="ur-btn ur-btn-dl" href="#" download>&#11015;&nbsp;<?=_('Download full log')?></a>
        <button type="button" id="ur-hist-log-copy" class="ur-btn ur-btn-copy"><?=_('Copy')?></button>
        <button type="button" id="ur-hist-modal-close"><?=_('Close')?></button>
      </span>
    </div>
    <blockquote class="inline_help" style="display:block;margin:0 0 8px"><p><?=_('The view below shows the most recent part of the log (the tail). Use Download full log for the complete file; Copy copies what is shown')?>.</p></blockquote>
    <pre id="ur-hist-log-pre" class="ur-hist-log-pre"></pre>
  </div>
</div>

<script type="text/javascript">
(function () {
  'use strict';

  var HANDLER_URL = <?=ur_js($handlerUrl)?>;
  var PAGE_SIZE = 25;

  var jobSel   = document.getElementById('ur-hist-job');
  var rowsEl   = document.getElementById('ur-hist-rows');
  var prevBtn  = document.getElementById('ur-hist-prev');
  var nextBtn  = document.getElementById('ur-hist-next');
  var pageInfo = document.getElementById('ur-hist-pageinfo');
  var modal    = document.getElementById('ur-hist-modal');
  var modalPre = document.getElementById('ur-hist-log-pre');
  var modalTit = document.getElementById('ur-hist-modal-title');
  var modalDl  = document.getElementById('ur-hist-log-download');

  var offset = 0;
  var total  = 0;
  var pollTimer = null; // set only while a RUNNING row is visible

  /* History is normally static (it changes only when a run finishes), so there
   * is no constant poller. But while an in-flight RUNNING row is on screen we
   * reload every few seconds so it flips to its final status on its own. */
  function clearPoll() { if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; } }

  function badgeFor(state) {
    switch (state) {
      case 'RUNNING':  return 'ur-badge-running';
      case 'SUCCESS':  return 'ur-badge-idle';
      case 'WARNING':
      case 'PARTIAL':  return 'ur-badge-warning';
      case 'ABORTED':  return 'ur-badge-aborted';
      default:         return 'ur-badge-failed'; // FAILED, TIMEOUT, unknown
    }
  }

  function fmtWhen(iso) {
    if (!iso) { return '—'; }
    var d = new Date(iso);
    if (isNaN(d.getTime())) { return iso; }
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
           ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function fmtDuration(sec) {
    sec = parseInt(sec, 10) || 0;
    if (sec < 60) { return sec + 's'; }
    var m = Math.floor(sec / 60), s = sec % 60;
    if (m < 60) { return m + 'm ' + s + 's'; }
    var h = Math.floor(m / 60); m = m % 60;
    return h + 'h ' + m + 'm';
  }

  /* Build one table cell with plain text (textContent => no XSS). */
  function td(text) { var c = document.createElement('td'); c.textContent = text; return c; }

  function render(runs) {
    rowsEl.innerHTML = '';
    if (!runs || !runs.length) {
      var tr0 = document.createElement('tr');
      var c0 = document.createElement('td');
      c0.setAttribute('colspan', '8');
      c0.textContent = (total === 0) ? 'No executions yet.' : 'No executions on this page.';
      tr0.appendChild(c0);
      rowsEl.appendChild(tr0);
      return;
    }
    runs.forEach(function (r) {
      var tr = document.createElement('tr');

      // Job name (resolved server-side; falls back to the id).
      var jobCell = td(r.jobName || r.jobId || '—');
      jobCell.className = 'ur-hist-name';
      tr.appendChild(jobCell);

      tr.appendChild(td(fmtWhen(r.startedAt)));

      // Trigger (icon + label)
      var trg = document.createElement('td');
      var ic = document.createElement('i');
      ic.className = 'fa ur-trigger-icon ' + (r.trigger === 'schedule' ? 'fa-clock-o' : 'fa-hand-pointer-o');
      ic.setAttribute('aria-hidden', 'true');
      trg.appendChild(ic);
      trg.appendChild(document.createTextNode(r.trigger === 'schedule' ? 'Schedule' : 'Manual'));
      tr.appendChild(trg);

      tr.appendChild(td(r.dryRun ? 'Dry-run' : 'Real'));

      // Status badge
      var stTd = document.createElement('td');
      var b = document.createElement('span');
      b.className = 'ur-badge ' + badgeFor(r.state);
      b.textContent = r.state || '—';
      stTd.appendChild(b);
      tr.appendChild(stTd);

      // A still-running row has no final exit code or duration yet.
      var isRunning = (r.state === 'RUNNING');
      tr.appendChild(td(isRunning ? '—' : String(r.exitCode)));
      tr.appendChild(td(isRunning ? '—' : fmtDuration(r.durationSec)));

      // Logs: View (modal) + Download (full file) when a log ref was recorded.
      var logTd = document.createElement('td');
      if (r.logRef) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ur-hist-viewlog';
        btn.textContent = 'View';
        btn.setAttribute('data-run', r.logRef);
        btn.setAttribute('data-jobid', r.jobId || '');
        logTd.appendChild(btn);

        var dl = document.createElement('a');
        dl.className = 'ur-hist-dl-row';
        dl.textContent = 'Download';
        dl.setAttribute('download', '');
        dl.href = HANDLER_URL + '?action=downloadJobLog&id=' + encodeURIComponent(r.jobId || '') +
                  '&run=' + encodeURIComponent(r.logRef);
        logTd.appendChild(dl);
      } else {
        logTd.textContent = '—';
      }
      tr.appendChild(logTd);

      rowsEl.appendChild(tr);
    });
  }

  function currentJob() { return jobSel ? jobSel.value : ''; }

  function updatePager() {
    var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    var page  = Math.floor(offset / PAGE_SIZE) + 1;
    pageInfo.textContent = total === 0 ? '0 executions' : ('Page ' + page + ' of ' + pages + ' (' + total + ' total)');
    prevBtn.disabled = (offset <= 0);
    nextBtn.disabled = (offset + PAGE_SIZE >= total);
  }

  /* Render a single full-width message row (used for empty + error states). */
  function messageRow(text) {
    rowsEl.innerHTML = '';
    var tr = document.createElement('tr');
    var c = td(text);
    c.setAttribute('colspan', '8');
    tr.appendChild(c);
    rowsEl.appendChild(tr);
  }

  function load() {
    clearPoll(); // cancel any pending refresh; the success path reschedules if still running
    /* An empty job filter means "all jobs" (the default); the server treats an
       empty id that way. cache-buster + no-store so a just-finished run shows
       without a hard refresh. */
    var job = currentJob();
    var url = HANDLER_URL + '?action=listHistory&id=' + encodeURIComponent(job) +
              '&offset=' + offset + '&limit=' + PAGE_SIZE + '&_=' + Date.now();
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (body) {
        if (!body || !body.ok) {
          // Reset paging so the pager can't stay stale/enabled over an error.
          total = 0; offset = 0;
          messageRow((body && body.error) || 'Could not load history.');
          updatePager();
          return;
        }
        total = parseInt(body.total, 10) || 0;
        offset = parseInt(body.offset, 10) || 0;
        render(body.runs || []);
        updatePager();
        // Keep refreshing while the server reports a run in flight, so a RUNNING
        // row resolves to SUCCESS/FAILED/etc. without a manual reload.
        clearPoll();
        if (body.running) { pollTimer = setTimeout(load, 3000); }
      })
      .catch(function () {
        // Network failure: show feedback instead of leaving "Loading…" stuck,
        // and reset the pager (History does not auto-retry).
        total = 0; offset = 0;
        messageRow('Could not load history (network error).');
        updatePager();
      });
  }

  /* ---- log modal ---- */
  function openLog(runRef, job) {
    if (!job || !runRef) { return; }
    modalTit.textContent = 'Run log — ' + runRef;
    modalPre.textContent = 'Loading…';
    modal.classList.add('ur-open');
    modal.setAttribute('aria-hidden', 'false');
    /* Move focus into the dialog so keyboard/SR users don't stay on background
     * controls (mirrors the Jobs-tab log viewer). */
    var closeBtn = document.getElementById('ur-hist-modal-close');
    if (closeBtn && closeBtn.focus) { closeBtn.focus(); }
    /* Point the "Download full log" link at the full-file endpoint for this run. */
    if (modalDl) {
      modalDl.href = HANDLER_URL + '?action=downloadJobLog&id=' + encodeURIComponent(job) + '&run=' + encodeURIComponent(runRef);
    }
    var url = HANDLER_URL + '?action=getJobLog&id=' + encodeURIComponent(job) + '&run=' + encodeURIComponent(runRef) + '&_=' + Date.now();
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (body) {
        if (body && body.ok && body.log) {
          /* body.log is already HTML-escaped server-side (Logger::tail). */
          modalPre.innerHTML = body.log;
          modalPre.scrollTop = modalPre.scrollHeight;
        } else {
          /* The record persists across reboots but its tmpfs log does not. */
          modalPre.textContent = 'Log not retained (run logs live in RAM and are cleared on reboot).';
        }
      })
      .catch(function () { modalPre.textContent = 'Could not load the log.'; });
  }
  function closeLog() {
    modal.classList.remove('ur-open');
    modal.setAttribute('aria-hidden', 'true');
    modalPre.textContent = '';
  }

  /* ---- events ---- */
  if (jobSel) { jobSel.addEventListener('change', function () { offset = 0; load(); }); }
  prevBtn.addEventListener('click', function () { if (offset > 0) { offset = Math.max(0, offset - PAGE_SIZE); load(); } });
  nextBtn.addEventListener('click', function () { if (offset + PAGE_SIZE < total) { offset += PAGE_SIZE; load(); } });
  rowsEl.addEventListener('click', function (ev) {
    var t = ev.target;
    if (t && t.classList && t.classList.contains('ur-hist-viewlog')) { openLog(t.getAttribute('data-run'), t.getAttribute('data-jobid')); }
  });
  document.getElementById('ur-hist-modal-close').addEventListener('click', closeLog);

  /* Copy the shown log to the clipboard (the visible tail; Download gives the
   * full file). Falls back to selecting the <pre> when the Clipboard API is
   * unavailable (non-secure context). */
  var copyBtn = document.getElementById('ur-hist-log-copy');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var txt = modalPre.textContent || '';
      var flash = function () { var o = copyBtn.textContent; copyBtn.textContent = 'Copied!'; setTimeout(function () { copyBtn.textContent = o; }, 1200); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(flash).catch(function () { selectPre(); });
      } else {
        selectPre();
      }
    });
  }
  function selectPre() {
    var rng = document.createRange(); rng.selectNodeContents(modalPre);
    var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(rng);
    try { document.execCommand('copy'); } catch (e) { /* leave it selected for manual copy */ }
  }

  /* Close on a genuine backdrop click only. Selecting text in the log and
   * releasing the drag over the backdrop fires a click with target===modal too,
   * which used to close the window mid-selection. Require the mousedown to have
   * STARTED on the backdrop, so a selection drag that began inside the box never
   * closes it. */
  var downOnBackdrop = false;
  modal.addEventListener('mousedown', function (ev) { downOnBackdrop = (ev.target === modal); });
  modal.addEventListener('click', function (ev) {
    if (ev.target === modal && downOnBackdrop) { closeLog(); }
    downOnBackdrop = false;
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && modal.classList.contains('ur-open')) { closeLog(); } });

  /* Initial load (fetch on open; no polling - history only changes on run end). */
  load();
})();
</script>
<?php endif; ?>

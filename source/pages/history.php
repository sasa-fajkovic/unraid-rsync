<?php
/**
 * history.php - the History tab body.
 *
 * A paginated table of every PAST execution (real + dry, manual + scheduled),
 * read from the persistent History records via the read-only listHistory GET
 * poller, filterable by job (configured ones plus ids that only survive in
 * History), status group and type. Columns: when, trigger, type (real/dry),
 * status badge, exit code, duration, and View / Download for the run's log
 * (getJobLog / downloadJobLog with the record's logRef as ?run=). Server-side
 * paging (page size 25) so up to the 9999-record retention cap never ships at
 * once.
 *
 * History only changes when a run starts or finishes, so there is no poller of
 * its own: it subscribes to the shared getStatus poller (window.urStatus) and
 * reloads when the set of running jobs or a last-run time changes - and only
 * while this tab body is on screen. Every dynamic field is written via
 * textContent (never innerHTML) so a job name / log path can't inject markup;
 * the log body from getJobLog is already HTML-escaped server-side
 * (Logger::tail) and is the only thing injected as HTML, into a <pre>.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Config.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/History.php';
// ur_render_csrf_token + ur_js helpers:
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php';

$handlerUrl = PageHelpers::HANDLER_URL;

// Job id => name for the filter select. A config read error just leaves the list
// empty (the empty state then points the user at the Jobs tab).
$jobs = [];
try {
    $config = PageHelpers::config();
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
// Jobs that only survive in History (deleted since), labelled the way their
// rows are: the name stored in the newest record, else the id without "j-".
$deleted = [];
foreach (array_diff(History::jobIds(), array_keys($jobs)) as $jid) {
    $stored = (string) (History::list($jid, 0, 1)['runs'][0]['jobName'] ?? '');
    $deleted[$jid] = $stored !== '' ? $stored : (string) preg_replace('/^j-/', '', $jid);
}
asort($deleted);

ur_emit_badge_assets();  /* the ONE badge palette + window.urBadge */
ur_emit_status_poller(); /* window.urStatus + window.urUtil + UR_TZ */
?>
<style>
.ur-hist-controls { margin: 8px 0; display: flex; gap: 8px 12px; align-items: center; flex-wrap: wrap; }
.ur-hist-controls select { width: auto; max-width: 260px; }
.ur-hist-scroll { overflow-x: auto; }
.ur-hist-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
.ur-hist-table th, .ur-hist-table td { padding: 4px 8px; text-align: left; border-bottom: 1px solid var(--border-color, #444); white-space: nowrap; }
/* Names stay whole; a narrow screen scrolls the table inside .ur-hist-scroll. */
.ur-hist-table td.ur-hist-name { white-space: nowrap; min-width: 9em; }
/* View is a <button>, Download an <a>: Unraid's global button rules (min-width,
   margins, orange border) beat the shared .ur-btn on the button alone, so pin
   the pair here with selectors that out-rank them. */
.ur-hist-table button.ur-btn,
.ur-hist-table a.ur-btn {
  display: inline-block; box-sizing: border-box; height: auto; min-width: 0; margin: 0; padding: 2px 8px;
  font-size: 12px; font-weight: bold; line-height: 1.4; letter-spacing: normal; text-transform: none; text-decoration: none;
  border: 1px solid rgba(127,127,127,0.4); border-radius: 4px; background: rgba(127,127,127,0.15); color: inherit;
  vertical-align: middle; cursor: pointer;
}
.ur-hist-table button.ur-btn:hover,
.ur-hist-table a.ur-btn:hover { background: rgba(127,127,127,0.28); border-color: rgba(127,127,127,0.6); color: inherit; }
.ur-hist-table button.ur-btn:focus-visible,
.ur-hist-table a.ur-btn:focus-visible { outline: 2px solid var(--blue-500, #216feb); outline-offset: 1px; }
.ur-hist-exit[title] { text-decoration: underline dotted; cursor: help; }
.ur-hist-logs .ur-btn + .ur-btn { margin-left: 6px; }
.ur-hist-when-sub { display: none; font-size: 0.85em; opacity: 0.75; }
/* Narrow screens: Trigger and Type fold into the When cell. */
@media (max-width: 700px) {
  .ur-hist-col-trg, .ur-hist-col-type { display: none; }
  .ur-hist-when-sub { display: block; }
}
.ur-hist-pager { margin: 10px 0; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.ur-hist-pager button[disabled] { opacity: 0.5; cursor: default; }
.ur-hist-pager input[type="number"] { width: 5em; min-width: 0; margin: 0 4px; }
.ur-trigger-icon { opacity: 0.8; margin-right: 4px; }
.ur-hist-snap { margin-top: 3px; font-size: 0.85em; color: var(--color-text-secondary, #888); }
/* Log modal (self-contained; the Jobs tab has its own, with a run picker and a
   live tail this one does not need, so the two are deliberately not merged). */
.ur-hist-modal { position: fixed; inset: 0; z-index: 11000; background: rgba(0,0,0,0.5); display: none; }
.ur-hist-modal.ur-open { display: block; }
.ur-hist-modal-box {
  position: absolute; top: 5vh; left: 50%; transform: translateX(-50%);
  width: min(900px, 92vw); max-height: 86vh; overflow: hidden;
  background: var(--background-color, #1c1c1c); border-radius: 6px; padding: 14px;
  display: flex; flex-direction: column;
}
.ur-hist-modal-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; gap: 8px; flex-wrap: wrap; }
.ur-hist-modal-head .ur-badge { min-width: 0; }
.ur-hist-modal-actions { display: flex; align-items: center; gap: 6px; }
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
  <?=_('Past executions across all jobs — real runs and dry-runs, started manually or on schedule. Use the filters to narrow the list. How many are kept per job is the "Keep last N executions" setting on the Global Settings tab')?>. <?=ur_tz_note()?>
</p>

<?php if (empty($jobs) && empty($deleted)): ?>
<div class="ur-empty-state"><?=_('No jobs configured yet — add one in the Jobs tab')?>.</div>
<?php else: ?>

<div class="ur-hist-controls">
  <label for="ur-hist-job"><?=_('Job')?>:</label>
  <select id="ur-hist-job">
    <option value="" selected><?=_('All jobs')?></option>
    <?php foreach ($jobs as $jid => $jname): ?>
      <option value="<?=ur_h($jid)?>"><?=ur_h($jname)?></option>
    <?php endforeach; ?>
    <?php foreach ($deleted as $jid => $jname): ?>
      <option value="<?=ur_h($jid)?>"><?=ur_h($jname . ' ' . ur_t_text('(deleted)'))?></option>
    <?php endforeach; ?>
  </select>
  <label for="ur-hist-state"><?=_('Status')?>:</label>
  <select id="ur-hist-state">
    <option value="" selected><?=_('All')?></option>
    <option value="problems"><?=_('Problems only')?></option>
    <option value="success"><?=_('Success only')?></option>
  </select>
  <label for="ur-hist-type"><?=_('Type')?>:</label>
  <select id="ur-hist-type">
    <option value="" selected><?=_('All')?></option>
    <option value="0"><?=_('Real')?></option>
    <option value="1"><?=_('Dry run')?></option>
  </select>
</div>

<div class="ur-hist-scroll">
<table class="ur-hist-table" id="ur-hist-table">
  <thead>
    <tr>
      <th><?=_('Job')?></th>
      <th><?=_('When')?></th>
      <th class="ur-hist-col-trg"><?=_('Trigger')?></th>
      <th class="ur-hist-col-type"><?=_('Type')?></th>
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
</div>

<div class="ur-hist-pager">
  <button type="button" id="ur-hist-prev"><?=_('‹ Newer')?></button>
  <span>
    <label for="ur-hist-pageno"><?=_('Page')?></label>
    <input type="number" id="ur-hist-pageno" min="1" step="1" value="1" inputmode="numeric">
    <span id="ur-hist-pageinfo"></span>
  </span>
  <button type="button" id="ur-hist-next"><?=_('Older ›')?></button>
</div>
<!-- Where a failed row download reports (the modal's <pre> takes over while the
     modal is open). No popups anywhere in this plugin. -->
<div id="ur-hist-dl-result" class="ur-result"></div>

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
    <div id="ur-hist-trunc" class="ur-notice" hidden><?=_('This shows the most recent part of a long log (the tail). Use Download full log for the complete file; Copy copies what is shown')?>.</div>
    <pre id="ur-hist-log-pre" class="ur-hist-log-pre"></pre>
  </div>
</div>

<?php
ur_emit_ajax_helpers();  /* window.urAjax.show, for the inline download error */
?>
<script type="text/javascript">
(function () {
  'use strict';

  var HANDLER_URL = <?=ur_js($handlerUrl)?>;
  /* rsync(1) EXIT VALUES, for the Exit cell's tooltip. */
  var EXIT_MEANINGS = <?=ur_js(Rsync::EXIT_MEANINGS)?>;
  var PAGE_SIZE = 25;

  var jobSel   = document.getElementById('ur-hist-job');
  var stateSel = document.getElementById('ur-hist-state');
  var typeSel  = document.getElementById('ur-hist-type');
  var tableEl  = document.getElementById('ur-hist-table');
  var rowsEl   = document.getElementById('ur-hist-rows');
  var prevBtn  = document.getElementById('ur-hist-prev');
  var nextBtn  = document.getElementById('ur-hist-next');
  var pageNo   = document.getElementById('ur-hist-pageno');
  var pageInfo = document.getElementById('ur-hist-pageinfo');
  var modal    = document.getElementById('ur-hist-modal');
  var modalPre = document.getElementById('ur-hist-log-pre');
  var modalTit = document.getElementById('ur-hist-modal-title');
  var modalDl  = document.getElementById('ur-hist-log-download');
  var truncEl  = document.getElementById('ur-hist-trunc');
  var dlResult = document.getElementById('ur-hist-dl-result');

  var offset = 0;
  var total  = 0;

  /* Records store startedAt as a UTC "...Z" string (Runner.php); render it in
   * the SERVER's timezone, not the browser's - see ur_emit_time_helpers(). */
  function fmtWhen(iso) {
    if (!iso) { return '—'; }
    var e = window.urUtil.isoToEpoch(iso);
    return e ? window.urFmtLocal(e, true) : iso;
  }

  function typeLabel(r) { return r.dryRun ? urI18n('hist.dryRun', 'Dry run') : urI18n('hist.real', 'Real'); }
  function triggerLabel(r) { return r.trigger === 'schedule' ? urI18n('hist.schedule', 'Schedule') : urI18n('hist.manual', 'Manual'); }
  /* A still-running row has no final exit code yet; a skipped one never ran. */
  function hasExit(r) { return r.state !== 'RUNNING' && r.state !== 'SKIPPED'; }

  /* A snapshot record's line under the status badge: its stamp in the SERVER's
   * timezone (the UTC stamp on hover), committed, suspect and pruned count. */
  function snapLine(r) {
    var d = document.createElement('div');
    d.className = 'ur-hist-snap';
    var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2})(\d{2})(\d{2})Z$/.exec(r.snapshot || '');
    var bits = [m ? urI18n('hist.snapshot', 'Snapshot %s', window.urFmtLocal(Date.UTC(+m[1], m[2] - 1, +m[3], +m[4], +m[5], +m[6]) / 1000, true)) : urI18n('hist.noSnapshot', 'No snapshot')];
    if (m) { d.title = r.snapshot; }
    bits.push(urI18n('hist.committed', 'committed: %s', r.committed ? urI18n('hist.yes', 'yes') : urI18n('hist.no', 'no')));
    if (r.suspect) { bits.push(urI18n('hist.suspect', 'SUSPECT')); }
    bits.push(urI18n('hist.pruned', 'pruned: %s', parseInt(r.pruned, 10) || 0));
    d.textContent = bits.join(' · ');
    return d;
  }

  /* Build one table cell with plain text (textContent => no XSS). */
  function td(text, cls) { var c = document.createElement('td'); c.textContent = text; if (cls) { c.className = cls; } return c; }

  function logHref(action, r) {
    return HANDLER_URL + '?action=' + action + '&id=' + encodeURIComponent(r.jobId || '') + '&run=' + encodeURIComponent(r.logRef);
  }

  function render(runs) {
    rowsEl.innerHTML = '';
    if (!runs || !runs.length) {
      messageRow(total === 0 ? urI18n('hist.noMatch', 'No executions match.') : urI18n('hist.noneOnPage', 'No executions on this page.'));
      return;
    }
    runs.forEach(function (r) {
      var tr = document.createElement('tr');

      // Job name (resolved server-side; falls back to the id).
      tr.appendChild(td(r.jobName || r.jobId || '—', 'ur-hist-name'));

      // When, with Trigger + Type folded underneath on narrow screens.
      var when = td(fmtWhen(r.startedAt));
      var sub = document.createElement('div');
      sub.className = 'ur-hist-when-sub';
      sub.textContent = triggerLabel(r) + ' · ' + typeLabel(r);
      when.appendChild(sub);
      tr.appendChild(when);

      // Trigger (icon + label)
      var trg = td('', 'ur-hist-col-trg');
      var ic = document.createElement('i');
      ic.className = 'fa ur-trigger-icon ' + (r.trigger === 'schedule' ? 'fa-clock-o' : 'fa-hand-pointer-o');
      ic.setAttribute('aria-hidden', 'true');
      trg.appendChild(ic);
      trg.appendChild(document.createTextNode(triggerLabel(r)));
      tr.appendChild(trg);

      tr.appendChild(td(typeLabel(r), 'ur-hist-col-type'));

      // Status badge; its tooltip is the recorded reason, else rsync's own
      // last "rsync error:" line.
      var stTd = document.createElement('td');
      var b = document.createElement('span');
      b.className = 'ur-badge';
      window.urBadge.apply(b, r.state);
      if (r.reason || r.lastError) { b.title = r.reason || r.lastError; }
      stTd.appendChild(b);
      /* Snapshot jobs only: their records carry `snapshot`, mirror ones do not. */
      if ('snapshot' in r) { stTd.appendChild(snapLine(r)); }
      tr.appendChild(stTd);

      var exitTd = td(hasExit(r) ? String(r.exitCode) : '—', 'ur-hist-exit');
      if (hasExit(r) && EXIT_MEANINGS[r.exitCode]) { exitTd.title = urI18n('hist.exitTitle', 'rsync exit %s: %s', r.exitCode, EXIT_MEANINGS[r.exitCode]); }
      tr.appendChild(exitTd);
      tr.appendChild(td(r.state === 'RUNNING' ? '—' : window.urUtil.formatDuration(r.durationSec)));

      // Logs: View (modal) + Download (full file) when a log ref was recorded.
      var logTd = td('', 'ur-hist-logs');
      if (r.logRef) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ur-btn ur-btn-plain ur-btn-sm';
        btn.textContent = urI18n('hist.view', 'View');
        btn.addEventListener('click', function () { openLog(r); });
        logTd.appendChild(btn);

        var dl = document.createElement('a');
        dl.className = 'ur-btn ur-btn-plain ur-btn-sm ur-hist-dl';
        dl.textContent = urI18n('hist.download', 'Download');
        dl.setAttribute('download', '');
        dl.href = logHref('downloadJobLog', r);
        logTd.appendChild(dl);
      } else {
        logTd.textContent = '—';
      }
      tr.appendChild(logTd);

      rowsEl.appendChild(tr);
    });
  }

  function pages() { return Math.max(1, Math.ceil(total / PAGE_SIZE)); }

  function updatePager() {
    pageNo.value = Math.floor(offset / PAGE_SIZE) + 1;
    pageNo.max = pages();
    pageInfo.textContent = urI18n('hist.pageInfo', 'of %s (%s total)', pages(), total);
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
    /* An empty job filter means "all jobs" (the default); the server treats an
       empty id that way, and empty state/dryRun filters as "all". cache-buster
       + no-store so a just-finished run shows without a hard refresh. */
    var url = HANDLER_URL + '?action=listHistory&id=' + encodeURIComponent(jobSel.value) +
              '&state=' + encodeURIComponent(stateSel.value) + '&dryRun=' + encodeURIComponent(typeSel.value) +
              '&offset=' + offset + '&limit=' + PAGE_SIZE + '&_=' + Date.now();
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (body) {
        if (!body || !body.ok) {
          // Reset paging so the pager can't stay stale/enabled over an error.
          total = 0; offset = 0;
          messageRow((body && body.error) || urI18n('hist.loadFailed', 'Could not load history.'));
          updatePager();
          return;
        }
        total = parseInt(body.total, 10) || 0;
        offset = parseInt(body.offset, 10) || 0;
        render(body.runs || []);
        updatePager();
      })
      .catch(function () {
        total = 0; offset = 0;
        messageRow(urI18n('hist.loadNetwork', 'Could not load history (network error).'));
        updatePager();
      });
  }

  /* ---- refresh: driven by the shared getStatus poller, gated on visibility ----
   * The list goes stale when a job starts or stops running, its last run time
   * moves (a run too short to be seen running) or its history file changes
   * (historyRev: a SKIPPED run, or a failure that never registered a run). It is reloaded only
   * while this tab body is on screen; the IntersectionObserver catches the tab
   * being shown, which no event reports. */
  var statusSig = null, stale = true;
  function onScreen() { return !document.hidden && tableEl.offsetParent !== null; }
  function refreshIfStale() { if (stale && onScreen()) { stale = false; load(); } }
  window.urStatus.subscribe(function (payload) {
    var jobs = payload.jobs || {};
    var sig = Object.keys(jobs).sort().map(function (id) {
      var j = jobs[id] || {};
      return id + (j.running ? ':r:' : ':i:') + ((j.lastRun && j.lastRun.finishedAt) || '') + ':' + (j.historyRev || '');
    }).join('|');
    if (statusSig !== null && sig !== statusSig) { stale = true; }
    statusSig = sig;
    refreshIfStale();
  });
  if (window.IntersectionObserver) {
    new IntersectionObserver(function (entries) {
      if (entries.some(function (e) { return e.isIntersecting; })) { refreshIfStale(); }
    }).observe(tableEl);
  } else {
    refreshIfStale();
  }

  /* ---- log modal ---- */
  function openLog(r) {
    if (!r.jobId || !r.logRef) { return; }
    /* "<job> · <badge> (exit N) · Real|Dry run · <server-local time>". The raw
     * run id (the UTC-stamped filename the download is saved under) stays on
     * hover so a support thread can match the two. */
    modalTit.textContent = (r.jobName || r.jobId) + ' · ';
    var b = document.createElement('span');
    b.className = 'ur-badge';
    window.urBadge.apply(b, r.state);
    modalTit.appendChild(b);
    modalTit.appendChild(document.createTextNode(
      (hasExit(r) ? ' ' + urI18n('hist.exitParen', '(exit %s)', r.exitCode) : '') + ' · ' + typeLabel(r) + ' · ' + fmtWhen(r.startedAt)
    ));
    modalTit.title = r.logRef;
    modalPre.textContent = urI18n('loading', 'Loading…');
    truncEl.hidden = true;
    modal.classList.add('ur-open');
    modal.setAttribute('aria-hidden', 'false');
    /* Move focus into the dialog so keyboard/SR users don't stay on background
     * controls (mirrors the Jobs-tab log viewer). */
    var closeBtn = document.getElementById('ur-hist-modal-close');
    if (closeBtn && closeBtn.focus) { closeBtn.focus(); }
    modalDl.href = logHref('downloadJobLog', r);
    fetch(logHref('getJobLog', r) + '&_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) { return res.json(); })
      .then(function (body) {
        if (body && body.ok && body.log) {
          /* body.log is already HTML-escaped server-side (Logger::tail). */
          modalPre.innerHTML = body.log;
          modalPre.scrollTop = modalPre.scrollHeight;
          truncEl.hidden = !body.truncated;
        } else {
          /* The record persists across reboots but its tmpfs log does not. */
          modalPre.textContent = urI18n('hist.logGone', 'Log not retained (run logs live in RAM and are cleared on reboot).');
        }
      })
      .catch(function () { modalPre.textContent = urI18n('hist.logFailed', 'Could not load the log.'); });
  }
  function closeLog() {
    modal.classList.remove('ur-open');
    modal.setAttribute('aria-hidden', 'true');
    modalPre.textContent = '';
    truncEl.hidden = true;
  }

  /* ---- events ---- */
  /* Downloads go through fetch so a log that is gone reports why instead of
   * saving a JSON error as a file. The modal's <pre> reports while it is open
   * (that is where the user is looking); a row download next to the pager. */
  modalDl.addEventListener('click', function (ev) {
    ev.preventDefault();
    window.urUtil.downloadFile(modalDl.href, function (msg) { modalPre.textContent = msg; });
  });
  rowsEl.addEventListener('click', function (ev) {
    var a = ev.target && ev.target.closest ? ev.target.closest('a.ur-hist-dl') : null;
    if (!a) { return; }
    ev.preventDefault();
    window.urUtil.downloadFile(a.href, function (msg) { window.urAjax.show(dlResult, false, msg); });
  });
  [jobSel, stateSel, typeSel].forEach(function (sel) {
    sel.addEventListener('change', function () { offset = 0; load(); });
  });
  prevBtn.addEventListener('click', function () { if (offset > 0) { offset = Math.max(0, offset - PAGE_SIZE); load(); } });
  nextBtn.addEventListener('click', function () { if (offset + PAGE_SIZE < total) { offset += PAGE_SIZE; load(); } });
  pageNo.addEventListener('change', function () {
    var p = Math.min(pages(), Math.max(1, parseInt(pageNo.value, 10) || 1));
    offset = (p - 1) * PAGE_SIZE;
    load();
  });
  document.getElementById('ur-hist-modal-close').addEventListener('click', closeLog);

  /* Copy the shown log (the visible tail; Download gives the full file). */
  var copyBtn = document.getElementById('ur-hist-log-copy');
  copyBtn.addEventListener('click', function () { window.urUtil.copyText(modalPre.textContent || '', copyBtn, modalPre); });

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
})();
</script>
<?php endif; ?>

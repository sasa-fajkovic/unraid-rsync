<?php
/**
 * overview.php - the Overview tab body (first tab).
 *
 * A read-only status board: one row per configured job showing its last
 * execution state (colored badge), when it last ran, and when it next runs — a
 * single place to see the health of every job at a glance.
 *
 * The page renders a skeleton row per job (id + name from config) and then polls
 * the read-only getStatus GET endpoint to fill in state/last-run/next-run live
 * (the same data the Jobs and Status tabs use), so the badge vocabulary and
 * formatting stay in lockstep with those tabs without duplicating the PHP state
 * helpers here. No state-changing actions live on this tab.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Config.php';
// ur_js helper:
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php';

$handlerUrl = '/plugins/unraid.rsync/include/handler.php';

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
.ur-badge {
  display: inline-block; min-width: 70px; padding: 2px 10px; border-radius: 10px;
  font-size: 11px; font-weight: bold; text-align: center; color: #fff;
  line-height: 1.6; white-space: nowrap;
}
.ur-badge-success { background: #1c7d3f; }
.ur-badge-warning { background: #b15c00; color: #1a1a1a; }
.ur-badge-failed  { background: var(--red-800, #b71c1c); }
.ur-badge-aborted { background: #555; }
.ur-badge-pending { background: #777; }
.ur-badge-running { background: #1565c0; animation: ur-ov-pulse 1.3s ease-in-out infinite; }
@keyframes ur-ov-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.55; } }
.ur-ov-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.ur-ov-table th, .ur-ov-table td { padding: 7px 10px; text-align: left; border-bottom: 1px solid var(--border-color, #444); }
.ur-ov-table td.ur-ov-name { font-weight: bold; word-break: break-word; }
.ur-ov-muted { color: var(--color-text-secondary, #888); }
.ur-empty-state {
  margin: 10px 0; padding: 10px 12px; border-radius: 4px;
  background: var(--blue-100, #d9edf7); border: 1px solid var(--blue-200, #bce8f1);
  color: var(--blockquote-text-color, #31708f);
}
</style>

<div class="title">
  <span class="left"><i class="fa fa-dashboard title"></i>&nbsp;<?=_('Overview')?></span>
</div>

<p><?=_('Last-execution status of every job at a glance. Updated automatically; open the Jobs or History tab for details and actions')?>.</p>

<?php if (empty($jobs)): ?>
<div class="ur-empty-state"><?=_('No jobs configured yet — add one in the Jobs tab')?>.</div>
<?php else: ?>

<table class="ur-ov-table">
  <thead>
    <tr>
      <th><?=_('Job')?></th>
      <th><?=_('State')?></th>
      <th><?=_('Last run')?></th>
      <th><?=_('Next run')?></th>
    </tr>
  </thead>
  <tbody id="ur-ov-rows">
    <?php foreach ($jobs as $jid => $jname): ?>
      <tr data-jobid="<?=htmlspecialchars($jid, ENT_QUOTES, 'UTF-8')?>">
        <td class="ur-ov-name"><?=htmlspecialchars($jname, ENT_QUOTES, 'UTF-8')?></td>
        <td class="ur-ov-state"><span class="ur-badge ur-badge-pending"><?=_('…')?></span></td>
        <td class="ur-ov-last ur-ov-muted"><?=_('…')?></td>
        <td class="ur-ov-next ur-ov-muted"><?=_('…')?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<script type="text/javascript">
(function () {
  'use strict';
  var STATUS_URL = <?=ur_js($handlerUrl)?> + '?action=getStatus';

  var BADGE_CLASSES = ['ur-badge-running','ur-badge-success','ur-badge-warning','ur-badge-failed','ur-badge-aborted','ur-badge-pending'];
  function badgeClassFor(state) {
    switch ((state || '').toUpperCase()) {
      case 'RUNNING': return 'ur-badge-running';
      case 'SUCCESS': return 'ur-badge-success';
      case 'WARNING':
      case 'PARTIAL': return 'ur-badge-warning';
      case 'FAILED':
      case 'TIMEOUT': return 'ur-badge-failed';
      case 'ABORTED': return 'ur-badge-aborted';
      default:        return 'ur-badge-pending';
    }
  }
  function labelFor(state) {
    var s = (state || '').toUpperCase();
    return s ? s.charAt(0) + s.slice(1).toLowerCase() : 'Pending';
  }

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function fmtLocal(epoch) {
    if (!epoch) { return '—'; }
    var d = new Date(epoch * 1000);
    if (isNaN(d.getTime())) { return '—'; }
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  function isoToEpoch(iso) {
    if (!iso) { return 0; }
    var t = Date.parse(iso);
    return isNaN(t) ? 0 : Math.floor(t / 1000);
  }
  function rel(deltaSec) {
    var s = Math.abs(deltaSec), suffix = deltaSec < 0 ? ' ago' : '';
    var pre = deltaSec < 0 ? '' : 'in ';
    if (s < 60) { return pre + s + 's' + suffix; }
    var m = Math.floor(s / 60); if (m < 60) { return pre + m + 'm' + suffix; }
    var h = Math.floor(m / 60); if (h < 24) { return pre + h + 'h' + suffix; }
    var dd = Math.floor(h / 24); return pre + dd + 'd' + suffix;
  }

  var rowsEl = document.getElementById('ur-ov-rows');
  var anyRunning = false;
  var timer = null;

  function applyStatus(body) {
    if (!body || !body.ok || !body.jobs) { return; }
    var now = parseInt(body.now, 10) || Math.floor(Date.now() / 1000);
    anyRunning = false;
    Array.prototype.forEach.call(rowsEl.querySelectorAll('tr[data-jobid]'), function (tr) {
      var s = body.jobs[tr.getAttribute('data-jobid')];
      if (!s) { return; }
      if (s.running) { anyRunning = true; }
      // state badge
      var b = tr.querySelector('.ur-ov-state .ur-badge');
      if (b) {
        BADGE_CLASSES.forEach(function (c) { b.classList.remove(c); });
        b.classList.add(badgeClassFor(s.state));
        b.textContent = s.running ? 'Running' : labelFor(s.state);
      }
      // last run
      var lc = tr.querySelector('.ur-ov-last');
      if (lc) {
        if (s.lastRun && s.lastRun.finishedAt) {
          var fin = isoToEpoch(s.lastRun.finishedAt);
          lc.textContent = fin ? (fmtLocal(fin) + ' (' + rel(fin - now) + ')' + (s.lastRun.dryRun ? ' · dry-run' : '')) : '—';
        } else {
          lc.textContent = '—';
        }
      }
      // next run
      var nc = tr.querySelector('.ur-ov-next');
      if (nc) {
        if (!s.enabled) { nc.textContent = 'disabled'; }
        else if (s.nextRun) { nc.textContent = fmtLocal(s.nextRun) + ' (' + rel(s.nextRun - now) + ')'; }
        else { nc.textContent = 'manual'; }
      }
    });
  }

  function poll() {
    fetch(STATUS_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(applyStatus)
      .catch(function () { /* transient; next tick retries */ })
      .then(function () {
        // Poll fast (2s) while something runs, slow (15s) when idle.
        if (timer) { clearTimeout(timer); }
        timer = setTimeout(poll, anyRunning ? 2000 : 15000);
      });
  }
  poll();
})();
</script>
<?php endif; ?>

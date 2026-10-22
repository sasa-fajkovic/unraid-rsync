<?php
/**
 * overview.php - the Overview tab body (first tab).
 *
 * A read-only status board: one row per configured job showing its last
 * execution state (colored badge), when it last ran, and when it next runs — a
 * single place to see the health of every job at a glance.
 *
 * The page renders a skeleton row per job (id + name from config) and then polls
 * the shared getStatus poller (window.urStatus, also feeding the Jobs and Status
 * tabs) to fill in state/last-run/next-run live, rendered through the same
 * window.urUtil / urBadge helpers as the Jobs tab. No state-changing actions
 * live on this tab.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Config.php';
// ur_js helper:
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php';

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
ur_emit_badge_assets(); /* the ONE badge palette + window.urBadge */
ur_emit_page_assets();  /* shared CSS (.ur-empty-state) + window.urUtil */
?>
<style>
.ur-ov-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.ur-ov-table th, .ur-ov-table td { padding: 7px 10px; text-align: left; border-bottom: 1px solid var(--border-color, #444); }
.ur-ov-table td.ur-ov-name { font-weight: bold; word-break: break-word; }
.ur-ov-muted { color: var(--color-text-secondary, #888); }
</style>

<div class="title">
  <span class="left"><i class="fa fa-dashboard title"></i>&nbsp;<?=_('Overview')?></span>
</div>

<p><?=_('Last-execution status of every job at a glance. Updated automatically; open the Jobs or History tab for details and actions')?>. <?=ur_tz_note()?></p>

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

<?php ur_emit_status_poller(); /* window.urStatus, shared with Jobs + Status */ ?>
<script type="text/javascript">
(function () {
  'use strict';
  var U = window.urUtil;
  var rowsEl = document.getElementById('ur-ov-rows');

  /* Same labels as the Jobs tab: both render through window.urUtil, the mirror
   * of PageHelpers. Times are in the SERVER's timezone (urFmtLocal). */
  function applyStatus(body) {
    if (!body || !body.jobs) { return; }
    var now = window.urStatus.now();
    Array.prototype.forEach.call(rowsEl.querySelectorAll('tr[data-jobid]'), function (tr) {
      var s = body.jobs[tr.getAttribute('data-jobid')];
      if (!s) { return; }
      var b = tr.querySelector('.ur-ov-state .ur-badge');
      if (b) { window.urBadge.apply(b, s.running ? 'RUNNING' : s.state); }
      var lc = tr.querySelector('.ur-ov-last');
      if (lc) {
        lc.textContent = U.lastRunLabel(s.lastRun, now) + (s.lastRun && s.lastRun.dryRun ? ' · dry-run' : '');
        /* Snapshot jobs only; label in the system timezone, UTC stamp on hover. */
        if (s.snapshot) {
          var sl = document.createElement('div');
          sl.className = 'ur-ov-muted';
          sl.title = s.snapshot.stamp;
          sl.textContent = s.snapshot.label;
          lc.appendChild(sl);
        }
      }
      var nc = tr.querySelector('.ur-ov-next');
      if (nc) { nc.textContent = U.nextRunLabel(s, now); }
    });
  }

  window.urStatus.subscribe(applyStatus);
  setInterval(function () { if (!document.hidden) { applyStatus(window.urStatus.last()); } }, 1000);
})();
</script>
<?php endif; ?>

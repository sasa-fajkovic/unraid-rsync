<?php
/**
 * overview.php - the Overview tab body (first tab).
 *
 * A read-only status board: one row per configured job showing its last real
 * run's state (colored badge) and time, a later dry run as a second line, and
 * when it next runs — a single place to see the health of every job at a glance.
 *
 * The first paint is rendered server-side through PageHelpers (the same rules
 * as the Jobs tab); the shared getStatus poller (window.urStatus, also feeding
 * the Jobs and Status tabs) then keeps state/last-run/next-run live through
 * the window.urUtil / urBadge mirrors. A job name opens its editor on the Jobs
 * tab. No state-changing actions live on this tab.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Config.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/RunState.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Runner.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Cron.php';
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
            $jobs[$jid] = $job;
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
.ur-ov-muted { color: var(--color-text-secondary, #666); }
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
    <?php $urNow = time();
    foreach ($jobs as $jid => $job):
        $jid      = (string) $jid;
        $summary  = Runner::readSummary($jid);
        $lastReal = Runner::lastRealFor($jid, $summary);
        $state    = PageHelpers::deriveState(RunState::isRunning($jid), $lastReal);
        $dryLine  = PageHelpers::dryRunLine(PageHelpers::lastDryRun($summary), $urNow);
        $snapLast = Config::normalizeSnapshot($job['snapshot'] ?? null) !== null ? Snapshot::lastSnapshot($summary) : null;
    ?>
      <tr data-jobid="<?=ur_h($jid)?>">
        <td class="ur-ov-name"><a href="#<?=ur_h('ur-jobcard-' . $jid)?>" class="ur-goto ur-job-link localURL" aria-controls="<?=ur_h('ur-jobcard-' . $jid)?>" title="<?=ur_h(ur_t_text('Edit this job'))?>"><?=ur_h((string) ($job['name'] ?? $jid))?></a></td>
        <td class="ur-ov-state"><span class="ur-badge <?=ur_h(ur_state_badge_class($state))?>"><?=ur_h(ur_state_label($state))?></span></td>
        <td class="ur-ov-last ur-ov-muted"><?=ur_h(PageHelpers::lastRunLabel($lastReal, $urNow))?><?php if ($dryLine !== ''): ?><div class="ur-dry-run"><?=ur_h($dryLine)?></div><?php endif; ?><?php if ($snapLast !== null): ?><div class="ur-ov-muted" title="<?=ur_h($snapLast['stamp'])?>"><?=ur_h($snapLast['label'])?></div><?php endif; ?></td>
        <td class="ur-ov-next ur-ov-muted"><?=ur_h(PageHelpers::nextRunLabel($job, $urNow))?></td>
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
        lc.textContent = U.lastRunLabel(s.lastReal, now);
        if (s.lastDryRun) {
          var dl = document.createElement('div');
          dl.className = 'ur-dry-run';
          dl.textContent = U.dryRunLine(s.lastDryRun, now);
          lc.appendChild(dl);
        }
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

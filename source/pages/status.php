<?php
/* Placeholder body for the Status tab.
 *
 * Phase 1 ships an empty, cleanly-loading tabbed page under Settings. Real
 * status/state UI, log viewer and last-run reporting land in a later phase
 * (see the project plan, Phase 6). For now this just renders a heading and a
 * short note so the tab loads without error.
 *
 * Uses the standard dynamix ".title" idiom for the section heading and wraps
 * the user-facing string in _(...)_  for translation, matching webGui style.
 */
?>
<div class="title">
  <span class="left">
    <i class="fa fa-refresh title"></i>&nbsp;<?=_('Unraid Rsync')?>
  </span>
</div>

<p>
  <?=_('Unraid Rsync - coming soon')?>.
  <?=_('Scheduling, credentials and per-job rsync backups will appear here in a future release')?>.
</p>

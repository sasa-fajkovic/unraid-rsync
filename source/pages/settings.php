<?php
/**
 * settings.php - the Global Settings tab body.
 *
 * Renders global.defaultRsyncOptions using the shared whitelist control set
 * (the same controls a job uses). These defaults seed new jobs and are applied
 * to any job that has "use global defaults" enabled.
 *
 * Phase 2: render + persist only. The form POSTs to the plugin handler with the
 * webGui csrf_token; the handler validates and atomically saves config.json.
 * Nothing runs rsync yet.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Config.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php';

// On a live webGui $var is global (state); ur_render_csrf_token reads it defensively.
$csrf = ur_render_csrf_token();

// If the on-disk config can't be read (unreadable, corrupt, or from a newer
// schema), render defaults for DISPLAY only but surface a visible warning -
// otherwise it looks like the settings reset, and the handler will refuse the
// save (409) anyway. We never persist on load.
$loadError = '';
try {
    $config = Config::load();
} catch (Throwable $e) {
    $config = Config::defaults();
    $loadError = $e->getMessage();
}
$defaultOpts = $config['global']['defaultRsyncOptions'] ?? Config::defaultRsyncOptions();
$retention   = Config::clampRetention($config['global']['retention'] ?? Config::DEFAULT_RETENTION);
$handlerUrl  = '/plugins/unraid.rsync/include/handler.php';

/* Emit the option help CSS/JS once, in LIVE page-body context, before the form
 * (which is the only place this page renders the shared options block). On the
 * combined tabbed page the Jobs tab emits these first; the static guard inside
 * the function makes this a no-op then. But when this body is rendered alone (or
 * the Jobs tab's only option block sat inside the hidden template on a fresh
 * install), this guarantees the assets are present in live DOM so the "?" help
 * actually works. */
ur_emit_option_help_assets();
/* Emit the shared robust-fetch helpers (window.urAjax) so this tab's save uses the
 * text->JSON parse that surfaces a non-JSON 403/500 WITH its status instead of
 * failing silently in r.json(). */
ur_emit_ajax_helpers();
/* Re-enable the plugin's own Apply button once the form is edited (Unraid's
 * framework disables it on load and won't re-enable our custom forms). */
ur_emit_form_enable_assets();
?>
<style>
/* Clear Unraid's fixed bottom status bar (#footer, "Array Started", ~30-40px tall
   + z-index:10000) so the Apply button at the foot of the form is never hidden
   behind it. A bottom buffer on the page wrapper is enough; the footer overlays
   the viewport, so the content must reserve space below it. */
.ur-settings-page { padding-bottom: 90px; }
</style>
<div class="ur-settings-page">
<div class="title">
  <span class="left">
    <i class="fa fa-cog title"></i>&nbsp;<?=_('Global Settings')?>
  </span>
</div>

<?php if ($loadError !== ''): ?>
<div class="ur-result ur-err">
  <?=_('The saved configuration could not be read, so defaults are shown below. Saving is blocked until this is resolved')?>:
  <?=htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8')?>
</div>
<?php endif; ?>

<p>
  <?=_('These default rsync options seed every new job and are applied to any job set to "use global defaults". Changing them here does not retroactively change jobs that keep their own options')?>.
</p>

<form markdown="1" method="POST" action="<?=htmlspecialchars($handlerUrl, ENT_QUOTES, 'UTF-8')?>" id="ur-settings-form">
  <input type="hidden" name="action" value="saveConfig">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8')?>">

  <?php ur_render_rsync_options($defaultOpts, 'global[defaultRsyncOptions]', 'ur_global'); ?>

  <dl>
    <dt class="ur-dt"><label for="ur_global_retention"><?=_('Keep last N executions')?></label></dt>
    <dd>
      <input type="number" id="ur_global_retention" name="global[retention]"
             min="1" max="9999" step="1" inputmode="numeric"
             value="<?=htmlspecialchars((string)$retention, ENT_QUOTES, 'UTF-8')?>">
      <blockquote class="inline_help ur-help-text">
        <?=_('How many past executions to keep per job — bounds both the in-RAM run logs and the persistent run history shown on the History tab. Oldest executions beyond this are pruned. 1–9999 (default 100).')?>
      </blockquote>
    </dd>
  </dl>

  <div class="ur-actions">
    <input type="submit" value="<?=_('Apply')?>">
  </div>
</form>

<div id="ur-settings-result" class="ur-result"></div>
</div><!-- .ur-settings-page -->

<script type="text/javascript">
/* Repeatable --exclude/--include rows: add/remove client-side. The template is
 * cloned from the first row's input name so the nested POST name round-trips. */
(function () {
  'use strict';

  function urAddRow(rowsEl) {
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
    if (t && t.classList && t.classList.contains('ur-row-add')) {
      var rowsId = t.getAttribute('data-rows');
      var rowsEl = document.getElementById(rowsId);
      if (rowsEl) { urAddRow(rowsEl); }
    } else if (t && t.classList && t.classList.contains('ur-row-del')) {
      var row = t.closest ? t.closest('.ur-row') : null;
      if (row && row.parentNode) { row.parentNode.removeChild(row); }
    }
  });

  /* Submit via fetch so we can show validation errors/warnings inline rather
   * than navigating away from the tab. Uses the shared robust text->JSON parse
   * (window.urAjax) so a non-JSON 403/500 from the front controller becomes a
   * VISIBLE error WITH its HTTP status instead of a silent failure inside
   * r.json(). */
  var form = document.getElementById('ur-settings-form');
  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var result = document.getElementById('ur-settings-result');
      window.urAjax.show(result, true, 'Saving…');
      window.urAjax.postFormElement(form).then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          var msg = res.body.message || 'Saved.';
          if (res.body.warnings && res.body.warnings.length) {
            msg += ' (' + res.body.warnings.join('; ') + ')';
          }
          window.urAjax.show(result, true, msg);
        } else {
          window.urAjax.show(result, false, window.urAjax.errText(res, 'Save failed.'));
        }
      });
    });
  }
})();
</script>

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

// On a live webGui $var is global (state). Read the CSRF token defensively.
$csrf = '';
if (isset($GLOBALS['var']) && is_array($GLOBALS['var']) && !empty($GLOBALS['var']['csrf_token'])) {
    $csrf = (string) $GLOBALS['var']['csrf_token'];
}

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
$handlerUrl  = '/plugins/unraid.rsync/include/handler.php';
?>
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

  <div class="ur-actions">
    <input type="submit" value="<?=_('Apply')?>">
  </div>
</form>

<div id="ur-settings-result" class="ur-result"></div>

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
   * than navigating away from the tab. */
  var form = document.getElementById('ur-settings-form');
  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var result = document.getElementById('ur-settings-result');
      var fd = new FormData(form);
      fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
          if (res.ok && res.body && res.body.ok) {
            var msg = res.body.message || 'Saved.';
            if (res.body.warnings && res.body.warnings.length) {
              msg += ' (' + res.body.warnings.join('; ') + ')';
            }
            result.className = 'ur-result ur-ok';
            result.textContent = msg;
          } else {
            var errs = (res.body && res.body.errors) ? res.body.errors.join('; ')
                     : ((res.body && res.body.error) ? res.body.error : 'Save failed.');
            result.className = 'ur-result ur-err';
            result.textContent = errs;
          }
        })
        .catch(function () {
          result.className = 'ur-result ur-err';
          result.textContent = 'Network error while saving.';
        });
    });
  }
})();
</script>

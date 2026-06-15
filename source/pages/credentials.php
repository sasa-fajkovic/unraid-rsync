<?php
/**
 * credentials.php - the Credentials tab body (managed SSH key keychain).
 *
 * Lists the managed keys (name, type/fingerprint), and lets the user generate or
 * import a key, rename keys, copy a public key, and delete a key. The stored
 * PRIVATE key is NEVER rendered back to the browser - only the fingerprint + the
 * public key (copyable) and a "set / not set" indicator. Generate/import return
 * only non-secret material.
 *
 * A connection that wants to authenticate with one of these keys references it
 * by id via the KEY auth method on the SEPARATE Connections tab
 * (pages/connections.php). Most setups do NOT need this keychain at all: if a key
 * already lives on this server, a connection's "Existing key file on this server"
 * option is simpler.
 *
 * Native webGui styling: dl/dt/dd forms, orange buttons, csrf_token on every
 * POST, _(...) for strings, htmlspecialchars on every rendered value. AJAX via
 * fetch to the plugin handler.
 *
 * SECURITY (documented for the user, enforced server-side):
 *   - credentials.json lives on /boot (FAT32, world-readable). Key auth is the
 *     primary, tested path.
 *   - Private keys are materialised to tmpfs at mode 600 only at run time; they
 *     are never sent to the browser after being saved.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Credentials.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Ssh.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php'; // ur_h / ur_t

$csrf = ur_render_csrf_token();

// Load credentials for DISPLAY only; on a read error show defaults + a warning
// (the handler will refuse to save, 409, until it's resolved). Never persist
// on load.
$loadError = '';
try {
    $creds = Credentials::load();
} catch (Throwable $e) {
    $creds = Credentials::defaults();
    $loadError = $e->getMessage();
}
$keys       = (isset($creds['keys']) && is_array($creds['keys'])) ? $creds['keys'] : [];
$handlerUrl = '/plugins/unraid.rsync/include/handler.php';
?>
<style>
/* The "required" field marker: a red asterisk paired with the HTML5 `required`
   attribute on the mandatory inputs. text-decoration:none drops the dotted
   <abbr> underline so it reads as a clean asterisk. */
.ur-required { color: var(--red-800, #b71c1c); font-weight: bold; text-decoration: none; cursor: help; }

/* Inline two-step delete confirm: an "armed" Delete button reuses the
   required-asterisk red so the destructive intent reads clearly, and the
   consequence warning is shown inline next to it (never in a popup). */
.ur-armed-delete { color: var(--red-800, #b71c1c); border-color: var(--red-800, #b71c1c); font-weight: bold; }

/* Inline copy-public-key fallback box (shown only when navigator.clipboard is
   unavailable): a readonly, selectable rendering of the key the user can copy
   by hand. */
.ur-copy-pub-fallback { display: block; width: 100%; margin-top: 6px; font-family: monospace; font-size: 0.85em; }

/* Keep the keychain action buttons compact (their natural width) instead of
   letting the webGui base stylesheet stretch a <button> to the full row width;
   this matches the inline look of the "Discover host key" button. The ID
   selectors out-specify the base button rule without needing !important. */
#ur-key-generate, #ur-key-import { width: auto; display: inline-block; }
</style>
<div class="title">
  <span class="left">
    <i class="fa fa-key title"></i>&nbsp;<?=_('Credentials')?>
  </span>
</div>

<?php if ($loadError !== ''): ?>
<div class="ur-result ur-err">
  <?=_('The saved credentials could not be read, so defaults are shown below. Saving is blocked until this is resolved')?>:
  <?=htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8')?>
</div>
<?php endif; ?>

<p>
  <?=_('A managed keychain of SSH keys the plugin stores for you. A connection on the Connections tab can reference a key here via its "Managed key" auth method')?>.
</p>

<!-- ============================== SSH KEYS ============================== -->
<div class="title"><span class="left"><?=_('SSH Keys (managed keychain)')?></span></div>

<blockquote class="inline_help">
  <p>
    <strong><?=_('Only needed if you do NOT already have an SSH key on this server')?>.</strong>
    <?=_('This generates or imports a key that the plugin manages and stores in credentials.json on the USB flash. '
        . 'If you already have /root/.ssh/id_ed25519 (or any key on this server), DO NOT generate one here — instead, '
        . 'on a connection (Connections tab) choose "Existing key file on this server" and point it at your key')?>.
  </p>
  <p>
    <?=_('How SSH keys work: the PRIVATE key is what authenticates you and stays on this server (it is never sent to the remote). '
        . 'The PUBLIC key is what you put in the remote\'s ~/.ssh/authorized_keys so it will accept your private key. '
        . 'These keys identify YOU to the remote. The remote SERVER\'s own identity is verified separately — pin it per '
        . 'connection with "Discover host key" on the Connections tab')?>.
  </p>
</blockquote>

<table class="tablesorter ur-key-list">
  <thead>
    <tr>
      <th><?=_('Name')?></th>
      <th><?=_('Fingerprint')?></th>
      <th><?=_('Private key')?></th>
      <th><?=_('Actions')?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($keys)): ?>
      <tr><td colspan="4"><?=_('No keys yet')?>.</td></tr>
    <?php else: foreach ($keys as $k):
        $kid = (string)($k['id'] ?? '');
        $kn  = (string)($k['name'] ?? '');
        $kfp = (string)($k['fingerprint'] ?? '');
        $kpub = (string)($k['publicKey'] ?? '');
        $hasPriv = trim((string)($k['privateKey'] ?? '')) !== '';
    ?>
      <tr data-key-id="<?=htmlspecialchars($kid, ENT_QUOTES, 'UTF-8')?>">
        <td><?=htmlspecialchars($kn, ENT_QUOTES, 'UTF-8')?></td>
        <td><code><?=htmlspecialchars($kfp !== '' ? $kfp : '—', ENT_QUOTES, 'UTF-8')?></code></td>
        <td><?=$hasPriv ? _('set') : _('not set')?></td>
        <td>
          <?php if ($kpub !== ''): ?>
            <button type="button" class="ur-copy-pub" data-pub="<?=htmlspecialchars($kpub, ENT_QUOTES, 'UTF-8')?>"><?=_('Copy public key')?></button>
          <?php endif; ?>
          <button type="button" class="ur-key-del-saved" data-key-id="<?=htmlspecialchars($kid, ENT_QUOTES, 'UTF-8')?>" data-key-name="<?=htmlspecialchars($kn, ENT_QUOTES, 'UTF-8')?>"><?=_('Delete')?></button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<!-- Generate / import a key. These POST immediately (a key has secret material
     that must be produced server-side); the saveCredentials form below only
     renames/reorders existing keys. -->
<dl>
  <dt><label for="ur_key_name"><?=_('New key name')?></label><?=ur_required_mark()?>:</dt>
  <dd><input type="text" id="ur_key_name" placeholder="<?=_('e.g. backup-ed25519')?>" required></dd>
  <dt><label for="ur_key_type"><?=_('Type (generate)')?></label>:</dt>
  <dd>
    <select id="ur_key_type">
      <option value="ed25519">ed25519</option>
      <option value="rsa">rsa (4096)</option>
    </select>
    <button type="button" id="ur-key-generate"><?=_('Generate key')?></button>
  </dd>
  <dt><label for="ur_key_import_priv"><?=_('Import private key')?></label>:</dt>
  <dd>
    <textarea id="ur_key_import_priv" rows="4" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" autocomplete="off"></textarea>
    <blockquote class="inline_help"><p><?=_('Importing is secondary — prefer "Generate key", or skip this section entirely and use a connection\'s "Existing key file on this server" option if your key already lives on this box. '
        . 'A private key must have an EMPTY passphrase (jobs run unattended); its public key and fingerprint are derived automatically. '
        . 'Provide at least ONE of the private or public key fields — the server enforces this either/or rule')?>.</p></blockquote>
  </dd>
  <dt><label for="ur_key_import_pub"><?=_('Import public key (optional)')?></label>:</dt>
  <dd>
    <textarea id="ur_key_import_pub" rows="2" placeholder="ssh-ed25519 AAAA..."></textarea>
    <button type="button" id="ur-key-import"><?=_('Import key')?></button>
  </dd>
</dl>
<div id="ur-key-result" class="ur-result"></div>
<?php
/* Re-enable the plugin's own submit buttons once a form is edited. Unraid's
 * settings framework disables them on load and does NOT re-enable our custom
 * (non-markdown) forms, which would otherwise leave Apply permanently greyed
 * out. */
ur_emit_form_enable_assets();
?>

<!-- Rename/reorder existing keys (no secret material in this form). -->
<form method="POST" action="<?=htmlspecialchars($handlerUrl, ENT_QUOTES, 'UTF-8')?>" id="ur-keys-form">
  <input type="hidden" name="action" value="saveCredentials">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8')?>">
  <input type="hidden" name="keys_present" value="1">
  <div id="ur-keys-container">
    <?php foreach ($keys as $i => $k):
        $kid = (string)($k['id'] ?? '');
        $kn  = (string)($k['name'] ?? '');
    ?>
      <div class="ur-key-row" data-index="<?=htmlspecialchars((string)$i, ENT_QUOTES, 'UTF-8')?>">
        <input type="hidden" name="keys[<?=htmlspecialchars((string)$i, ENT_QUOTES, 'UTF-8')?>][id]" value="<?=htmlspecialchars($kid, ENT_QUOTES, 'UTF-8')?>">
        <label><?=_('Name')?>: <input type="text" name="keys[<?=htmlspecialchars((string)$i, ENT_QUOTES, 'UTF-8')?>][name]" value="<?=htmlspecialchars($kn, ENT_QUOTES, 'UTF-8')?>"></label>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="ur-actions">
    <input type="submit" value="<?=_('Save key names')?>">
  </div>
</form>
<div id="ur-keys-save-result" class="ur-result"></div>

<script type="text/javascript">
(function () {
  'use strict';

  var HANDLER = <?=ur_js($handlerUrl)?>;
  var CSRF = <?=ur_js($csrf)?>;

  /* POST a form and ALWAYS resolve to { ok, status, body, parseError }; never
   * rejects (a network failure resolves with status 0) so the UI is ALWAYS
   * updated and an action can never leave a stuck "Generating…". See the
   * Connections tab for the full multipart-vs-urlencoded rationale; in short we
   * send urlencoded because a multipart body stalls php-fpm on the live box. */
  function postForm(fields) {
    var params = new URLSearchParams();
    params.append('csrf_token', CSRF);
    Object.keys(fields).forEach(function (k) { params.append(k, fields[k]); });
    return fetch(HANDLER, { method: 'POST', body: params, credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (text) {
          var body = null, parseError = false;
          try { body = JSON.parse(text); } catch (e) { parseError = (text !== ''); }
          return { ok: r.ok, status: r.status, body: body, parseError: parseError };
        });
      })
      .catch(function () {
        return { ok: false, status: 0, body: null, parseError: false, networkError: true };
      });
  }

  function show(el, ok, msg) {
    if (!el) { return; }
    el.className = 'ur-result ' + (ok ? 'ur-ok' : 'ur-err');
    el.textContent = msg;
  }

  /* Build a clear failure message from a postForm result, ALWAYS including the
   * HTTP status (or a network/parse hint), so a failure is never silent. */
  function errText(res, fallback) {
    if (res.networkError || res.status === 0) {
      return (fallback || 'Request failed') + ': could not reach the server (network error).';
    }
    if (res.body && res.body.errors && res.body.errors.length) {
      return res.body.errors.join('; ') + ' (HTTP ' + res.status + ')';
    }
    if (res.body && res.body.error) {
      return res.body.error + ' (HTTP ' + res.status + ')';
    }
    if (res.parseError) {
      return (fallback || 'Request failed')
        + ': the server returned a non-JSON response (HTTP ' + res.status + ').';
    }
    return (fallback || 'Request failed') + ' (HTTP ' + res.status + ').';
  }

  /* ---- two-step inline delete confirm (replaces window.confirm) ----
   * First click ARMS the button (label -> "Confirm delete?", red, inline
   * warning); a second click within ARM_WINDOW_MS runs the delete; otherwise it
   * auto-reverts. Non-blocking — no popup. */
  var ARM_WINDOW_MS = 4000;
  var armedDeleteBtn = null;

  function disarmDelete(btn) {
    if (!btn || !btn._urArm) { return; }
    clearTimeout(btn._urArm.timer);
    btn.textContent = btn._urArm.label;
    btn.classList.remove('ur-armed-delete');
    var resultEl = btn._urArm.resultEl;
    var armedMsg = btn._urArm.message;
    if (resultEl && resultEl.getAttribute('data-ur-armed') === '1'
        && resultEl.textContent === armedMsg) {
      resultEl.className = 'ur-result';
      resultEl.textContent = '';
      resultEl.removeAttribute('data-ur-armed');
    } else if (resultEl && resultEl.getAttribute('data-ur-armed') === '1') {
      resultEl.removeAttribute('data-ur-armed');
    }
    btn._urArm = null;
    if (armedDeleteBtn === btn) { armedDeleteBtn = null; }
  }

  function armOrConfirmDelete(btn, opts) {
    if (btn._urArm) {            // second click within the window -> do it
      var run = btn._urArm.run;
      disarmDelete(btn);
      run();
      return;
    }
    if (armedDeleteBtn && armedDeleteBtn !== btn) { disarmDelete(armedDeleteBtn); }
    var message = (opts.warning || '') + ' Click "Confirm delete?" again to proceed.';
    btn._urArm = {
      label: btn.textContent,
      resultEl: opts.resultEl || null,
      message: message,
      run: opts.run,
      timer: setTimeout(function () { disarmDelete(btn); }, ARM_WINDOW_MS)
    };
    armedDeleteBtn = btn;
    btn.textContent = 'Confirm delete?';
    btn.classList.add('ur-armed-delete');
    if (opts.resultEl && opts.warning) {
      opts.resultEl.className = 'ur-result ur-err';
      opts.resultEl.textContent = message;
      opts.resultEl.setAttribute('data-ur-armed', '1');
    }
  }

  /* ---- inline copy-public-key fallback (replaces window.prompt) ----
   * When the Clipboard API is unavailable, reveal the key in a readonly,
   * pre-selected text field next to the button so the user can copy it manually.
   * Non-blocking; one reusable field per button. */
  function revealPublicKeyInline(btn, pub) {
    var field = btn._urPubField;
    if (!field) {
      field = document.createElement('input');
      field.type = 'text';
      field.readOnly = true;
      field.className = 'ur-copy-pub-fallback';
      btn._urPubField = field;
      if (btn.parentNode) {
        if (btn.nextSibling) { btn.parentNode.insertBefore(field, btn.nextSibling); }
        else { btn.parentNode.appendChild(field); }
      }
    }
    field.value = pub;
    field.title = 'Copy manually: select all and copy';
    field.focus();
    field.select();
    var old = btn.textContent;
    btn.textContent = 'Copy manually:';
    setTimeout(function () { btn.textContent = old; }, 1600);
  }

  /* ---- key generate / import ---- */
  var keyResult = document.getElementById('ur-key-result');

  var genBtn = document.getElementById('ur-key-generate');
  if (genBtn) {
    genBtn.addEventListener('click', function () {
      var name = (document.getElementById('ur_key_name').value || '').trim();
      var type = document.getElementById('ur_key_type').value;
      if (!name) { show(keyResult, false, 'Enter a key name first.'); return; }
      show(keyResult, true, 'Generating…');
      postForm({ action: 'generateKey', name: name, type: type }).then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          show(keyResult, true, 'Key "' + res.body.name + '" generated. Fingerprint: ' + (res.body.fingerprint || '?'));
          setTimeout(function () { window.location.reload(); }, 800);
        } else {
          show(keyResult, false, errText(res, 'Key generation failed.'));
        }
      }).catch(function (e) { show(keyResult, false, 'Unexpected error: ' + (e && e.message ? e.message : e)); });
    });
  }

  var impBtn = document.getElementById('ur-key-import');
  if (impBtn) {
    impBtn.addEventListener('click', function () {
      var name = (document.getElementById('ur_key_name').value || '').trim();
      var priv = document.getElementById('ur_key_import_priv').value || '';
      var pub  = document.getElementById('ur_key_import_pub').value || '';
      if (!name) { show(keyResult, false, 'Enter a key name first.'); return; }
      if (!priv.trim() && !pub.trim()) { show(keyResult, false, 'Paste a private and/or public key.'); return; }
      show(keyResult, true, 'Importing…');
      postForm({ action: 'importKey', name: name, privateKey: priv, publicKey: pub }).then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          show(keyResult, true, 'Key "' + res.body.name + '" imported. Fingerprint: ' + (res.body.fingerprint || '?'));
          setTimeout(function () { window.location.reload(); }, 800);
        } else {
          show(keyResult, false, errText(res, 'Key import failed.'));
        }
      }).catch(function (e) { show(keyResult, false, 'Unexpected error: ' + (e && e.message ? e.message : e)); });
    });
  }

  /* ---- copy public key / delete key (delegated) ---- */
  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.classList) { return; }

    if (t.classList.contains('ur-copy-pub')) {
      var pub = t.getAttribute('data-pub') || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(pub).then(function () {
          var old = t.textContent; t.textContent = 'Copied!';
          setTimeout(function () { t.textContent = old; }, 1200);
        });
      } else {
        // No Clipboard API (e.g. non-secure context): reveal the key inline in a
        // readonly, pre-selected field so the user can copy it by hand.
        revealPublicKeyInline(t, pub);
      }
    } else if (t.classList.contains('ur-key-del-saved')) {
      // Two-step inline confirm (no popup).
      armOrConfirmDelete(t, {
        warning: 'Delete key "' + (t.getAttribute('data-key-name') || t.getAttribute('data-key-id')) + '"? '
          + 'Connections that use it must be repointed first.',
        resultEl: keyResult,
        run: function () {
          var kid = t.getAttribute('data-key-id');
          postForm({ action: 'deleteKey', id: kid }).then(function (res) {
            if (res.ok && res.body && res.body.ok) {
              window.location.reload();
            } else {
              show(keyResult, false, errText(res, 'Delete failed.'));
            }
          }).catch(function (e) { show(keyResult, false, 'Unexpected error: ' + (e && e.message ? e.message : e)); });
        }
      });
    }
  });

  /* ---- key rename form submit ----
   * Uses the SAME robust text->JSON parse as postForm so a non-JSON 403/500
   * becomes a VISIBLE error WITH the HTTP status, and a success always renders a
   * clear "Saved" line. */
  function wireForm(formId, resultId) {
    var form = document.getElementById(formId);
    if (!form) { return; }
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var result = document.getElementById(resultId);
      /* urlencoded (URLSearchParams over the form's FormData), NOT multipart:
         multipart bodies stall in php-fpm in the live environment. Nested field
         names (keys[0][id], …) round-trip unchanged into $_POST. */
      var params = new URLSearchParams(new FormData(form));
      show(result, true, 'Saving…');
      fetch(form.getAttribute('action'), { method: 'POST', body: params, credentials: 'same-origin' })
        .then(function (r) {
          return r.text().then(function (text) {
            var body = null, parseError = false;
            try { body = JSON.parse(text); } catch (e) { parseError = (text !== ''); }
            return { ok: r.ok, status: r.status, body: body, parseError: parseError };
          });
        })
        .catch(function () {
          return { ok: false, status: 0, body: null, parseError: false, networkError: true };
        })
        .then(function (res) {
          if (res.ok && res.body && res.body.ok) {
            show(result, true, res.body.message || 'Saved.');
            setTimeout(function () { window.location.reload(); }, 600);
          } else {
            show(result, false, errText(res, 'Save failed.'));
          }
        });
    });
  }
  wireForm('ur-keys-form', 'ur-keys-save-result');
})();
</script>

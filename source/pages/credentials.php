<?php
/**
 * credentials.php - the Credentials tab body (two-tier TrueNAS-style keychain).
 *
 * Two sections:
 *   1. SSH Keys     - list (name, type/fingerprint), generate, import, delete.
 *                     The stored PRIVATE key is NEVER rendered back to the
 *                     browser - only the fingerprint + public key (copyable) and
 *                     a "set / not set" indicator. Generate/import return only
 *                     non-secret material.
 *   2. Connections  - list + add/edit/delete cards. Each has host/port/user, an
 *                     auth-method select (KEY -> key picker; PASSWORD -> a
 *                     write-only password field with a clear recoverable-secret
 *                     warning), a strict-host-key select, a "Discover host key"
 *                     button (ssh-keyscan), a connect timeout, and a "Test
 *                     connection" button. A stored password is shown only as
 *                     "set / not set"; the field is write-only.
 *
 * Native webGui styling: dl/dt/dd forms, switchbutton-friendly checkboxes,
 * orange buttons, csrf_token on every POST, _(...) for strings, htmlspecialchars
 * on every rendered value. AJAX via fetch to the plugin handler.
 *
 * SECURITY (documented for the user, enforced server-side):
 *   - credentials.json lives on /boot (FAT32, world-readable). Passwords are
 *     OBFUSCATION-ONLY (reversible) - anyone with flash access can recover them.
 *     A dedicated low-privilege remote account is recommended. Key auth is the
 *     primary, tested path.
 *   - Private keys are materialised to tmpfs at mode 600 only at run time; they
 *     are never sent to the browser after being saved.
 */

require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Credentials.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/include/Ssh.php';
require_once '/usr/local/emhttp/plugins/unraid.rsync/pages/_options_form.php'; // ur_h / ur_t

$csrf = '';
if (isset($GLOBALS['var']) && is_array($GLOBALS['var']) && !empty($GLOBALS['var']['csrf_token'])) {
    $csrf = (string) $GLOBALS['var']['csrf_token'];
}

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
$keys        = (isset($creds['keys']) && is_array($creds['keys'])) ? $creds['keys'] : [];
$connections = (isset($creds['connections']) && is_array($creds['connections'])) ? $creds['connections'] : [];
$handlerUrl  = '/plugins/unraid.rsync/include/handler.php';

// Whether password auth is usable on this box right now (detect-and-degrade).
$sshpassOk      = Ssh::sshpassAvailable();
$sshpassMissing = Ssh::sshpassMissingMessage();

/**
 * Render one connection card. $index is an int row index or "__CIDX__" for the
 * JS template. A blank password field never reveals the stored secret; the
 * "password is set" hint is rendered from server state only.
 *
 * @param array<string,mixed> $conn
 * @param int|string          $index
 * @param array<int,array<string,mixed>> $keys
 */
function ur_render_connection_card($conn, $index, array $keys, bool $sshpassOk): void
{
    $conn = is_array($conn) ? Credentials::mergeConnection($conn) : Credentials::defaultConnection();
    $p    = 'connections[' . $index . ']';
    $idb  = 'ur_conn_' . $index;

    $id        = (string) $conn['id'];
    $name      = (string) $conn['name'];
    $host      = (string) $conn['host'];
    $port      = (string) $conn['port'];
    $username  = (string) $conn['username'];
    $auth      = (string) $conn['authMethod'];
    $keyId     = (string) $conn['keyId'];
    $strict    = (string) $conn['strictHostKey'];
    $timeout   = (string) $conn['connectTimeout'];
    $hostKey   = (string) $conn['remoteHostKey'];
    $hasPass   = ((string) $conn['password']) !== '';

    echo '<div class="ur-conn-card" data-index="' . ur_h($index) . '" data-conn-id="' . ur_h($id) . '">';
    echo '<input type="hidden" name="' . ur_h($p . '[id]') . '" value="' . ur_h($id) . '">';
    echo '<dl>';

    // name
    echo '<dt><label for="' . ur_h($idb . '_name') . '">' . ur_h(ur_t('Name')) . '</label>:</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_name') . '" name="' . ur_h($p . '[name]') . '" value="' . ur_h($name) . '"></dd>';

    // host
    echo '<dt><label for="' . ur_h($idb . '_host') . '">' . ur_h(ur_t('Host')) . '</label>:</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_host') . '" name="' . ur_h($p . '[host]') . '" value="' . ur_h($host) . '" placeholder="host.example or 10.0.0.5"></dd>';

    // port
    echo '<dt><label for="' . ur_h($idb . '_port') . '">' . ur_h(ur_t('Port')) . '</label>:</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_port') . '" name="' . ur_h($p . '[port]') . '" value="' . ur_h($port) . '" placeholder="22"></dd>';

    // username
    echo '<dt><label for="' . ur_h($idb . '_user') . '">' . ur_h(ur_t('Username')) . '</label>:</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_user') . '" name="' . ur_h($p . '[username]') . '" value="' . ur_h($username) . '"></dd>';

    // auth method
    echo '<dt><label for="' . ur_h($idb . '_auth') . '">' . ur_h(ur_t('Auth method')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_auth') . '" class="ur-conn-auth" name="' . ur_h($p . '[authMethod]') . '" data-idb="' . ur_h($idb) . '">';
    foreach (['KEY' => 'SSH key', 'PASSWORD' => 'Password'] as $val => $lbl) {
        $sel = ($auth === $val) ? ' selected' : '';
        echo '<option value="' . ur_h($val) . '"' . $sel . '>' . ur_h(ur_t($lbl)) . '</option>';
    }
    echo '</select></dd>';

    // KEY: key picker (shown when auth=KEY)
    $keyRowStyle = ($auth === 'KEY') ? '' : ' style="display:none"';
    echo '<dt class="ur-auth-key" id="' . ur_h($idb . '_keyrow_dt') . '"' . $keyRowStyle . '><label for="' . ur_h($idb . '_key') . '">' . ur_h(ur_t('SSH key')) . '</label>:</dt>';
    echo '<dd class="ur-auth-key" id="' . ur_h($idb . '_keyrow_dd') . '"' . $keyRowStyle . '>';
    echo '<select id="' . ur_h($idb . '_key') . '" name="' . ur_h($p . '[keyId]') . '">';
    echo '<option value="">' . ur_h(ur_t('(select a key)')) . '</option>';
    $found = false;
    foreach ($keys as $k) {
        $kid = (string) ($k['id'] ?? '');
        $kn  = (string) ($k['name'] ?? $kid);
        $sel = ($kid === $keyId) ? ' selected' : '';
        if ($kid === $keyId) {
            $found = true;
        }
        echo '<option value="' . ur_h($kid) . '"' . $sel . '>' . ur_h($kn) . '</option>';
    }
    // Preserve an unknown existing keyId as an option so editing doesn't drop it.
    if ($keyId !== '' && !$found) {
        echo '<option value="' . ur_h($keyId) . '" selected>' . ur_h($keyId) . ' ' . ur_h(ur_t('(missing)')) . '</option>';
    }
    echo '</select>';
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('Add or generate keys in the SSH Keys section above.')) . '</p></blockquote>';
    echo '</dd>';

    // PASSWORD: write-only field + recoverable-secret warning (shown when auth=PASSWORD)
    $passRowStyle = ($auth === 'PASSWORD') ? '' : ' style="display:none"';
    echo '<dt class="ur-auth-pass" id="' . ur_h($idb . '_passrow_dt') . '"' . $passRowStyle . '><label for="' . ur_h($idb . '_pass') . '">' . ur_h(ur_t('Password')) . '</label>:</dt>';
    echo '<dd class="ur-auth-pass" id="' . ur_h($idb . '_passrow_dd') . '"' . $passRowStyle . '>';
    echo '<input type="password" id="' . ur_h($idb . '_pass') . '" name="' . ur_h($p . '[password]') . '" value="" autocomplete="new-password" placeholder="' . ur_h($hasPass ? ur_t('(unchanged - leave blank to keep)') : ur_t('(not set)')) . '">';
    echo ' <span class="ur-pass-state">' . ur_h($hasPass ? ur_t('Password is set') : ur_t('No password set')) . '</span>';
    echo '<blockquote class="inline_help"><p><strong>' . ur_h(ur_t('Warning:')) . '</strong> '
        . ur_h(ur_t('Passwords are stored OBFUSCATED (reversible), not encrypted, on the world-readable USB flash. '
            . 'Anyone with flash access can recover them. Prefer key auth, and use a dedicated low-privilege remote account.'))
        . '</p></blockquote>';
    if (!$sshpassOk) {
        echo '<blockquote class="inline_help"><p>' . ur_h(Ssh::sshpassMissingMessage()) . '</p></blockquote>';
    }
    echo '</dd>';

    // strict host key
    echo '<dt><label for="' . ur_h($idb . '_strict') . '">' . ur_h(ur_t('Strict host key checking')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_strict') . '" name="' . ur_h($p . '[strictHostKey]') . '">';
    foreach (['accept-new' => 'accept-new (accept an unknown host key on connect)', 'yes' => 'yes (require a pinned host key)', 'no' => 'no (do not verify - insecure)'] as $val => $lbl) {
        $sel = ($strict === $val) ? ' selected' : '';
        echo '<option value="' . ur_h($val) . '"' . $sel . '>' . ur_h(ur_t($lbl)) . '</option>';
    }
    echo '</select></dd>';

    // connect timeout
    echo '<dt><label for="' . ur_h($idb . '_timeout') . '">' . ur_h(ur_t('Connect timeout (s)')) . '</label>:</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_timeout') . '" name="' . ur_h($p . '[connectTimeout]') . '" value="' . ur_h($timeout) . '" placeholder="10"></dd>';

    // host key (discover) - textarea holds the pinned key; button fills it
    echo '<dt><label for="' . ur_h($idb . '_hostkey') . '">' . ur_h(ur_t('Pinned host key')) . '</label>:</dt>';
    echo '<dd>';
    echo '<textarea id="' . ur_h($idb . '_hostkey') . '" name="' . ur_h($p . '[remoteHostKey]') . '" rows="2" placeholder="' . ur_h(ur_t('Use "Discover host key" to fetch this from the host')) . '">' . ur_h($hostKey) . '</textarea>';
    echo '<div><button type="button" class="ur-discover-hostkey" data-idb="' . ur_h($idb) . '">' . ur_h(ur_t('Discover host key')) . '</button></div>';
    echo '</dd>';

    echo '</dl>';

    // per-card actions: test connection (saved connections only) + remove
    echo '<div class="ur-conn-card-actions">';
    if ($id !== '') {
        echo '<button type="button" class="ur-test-conn" data-conn-id="' . ur_h($id) . '">' . ur_h(ur_t('Test connection')) . '</button> ';
        echo '<span class="ur-test-result" id="' . ur_h($idb . '_test') . '"></span> ';
        echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('Test uses the last SAVED settings - apply your changes first.')) . '</p></blockquote>';
    }
    echo '<button type="button" class="ur-conn-del">' . ur_h(ur_t('Remove connection')) . '</button>';
    echo '</div>';

    echo '</div>'; // .ur-conn-card
}
?>
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
  <?=_('Reusable SSH keys and connections (TrueNAS-style keychain). Jobs reference a connection by its id (shown here by name); define a connection once and point any number of jobs at it')?>.
</p>

<blockquote class="inline_help">
  <p>
    <strong><?=_('Storage note')?>:</strong>
    <?=_('Credentials are stored on the USB flash (FAT32, world-readable). Private keys are materialised to RAM with restrictive permissions only at run time, and are never shown again after they are saved. Passwords are obfuscated (reversible), not encrypted — prefer key auth and a dedicated low-privilege remote account')?>.
  </p>
</blockquote>

<?php if (!$sshpassOk): ?>
<blockquote class="inline_help">
  <p><?=htmlspecialchars($sshpassMissing, ENT_QUOTES, 'UTF-8')?></p>
</blockquote>
<?php endif; ?>

<!-- ============================== SSH KEYS ============================== -->
<div class="title"><span class="left"><?=_('SSH Keys')?></span></div>

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
  <dt><label for="ur_key_name"><?=_('New key name')?></label>:</dt>
  <dd><input type="text" id="ur_key_name" placeholder="<?=_('e.g. backup-ed25519')?>"></dd>
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
    <blockquote class="inline_help"><p><?=_('The private key must have an EMPTY passphrase (jobs run unattended). The public key and fingerprint are derived automatically')?>.</p></blockquote>
  </dd>
  <dt><label for="ur_key_import_pub"><?=_('Import public key (optional)')?></label>:</dt>
  <dd>
    <textarea id="ur_key_import_pub" rows="2" placeholder="ssh-ed25519 AAAA..."></textarea>
    <button type="button" id="ur-key-import"><?=_('Import key')?></button>
  </dd>
</dl>
<div id="ur-key-result" class="ur-result"></div>

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

<!-- ============================ CONNECTIONS ============================ -->
<div class="title"><span class="left"><?=_('Connections')?></span></div>

<table class="tablesorter ur-conn-list">
  <thead>
    <tr>
      <th><?=_('Name')?></th>
      <th><?=_('Host')?></th>
      <th><?=_('User')?></th>
      <th><?=_('Auth')?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (empty($connections)): ?>
      <tr><td colspan="4"><?=_('No connections yet')?>.</td></tr>
    <?php else: foreach ($connections as $c):
        $cc = Credentials::mergeConnection($c);
    ?>
      <tr>
        <td><?=htmlspecialchars((string)$cc['name'], ENT_QUOTES, 'UTF-8')?></td>
        <td><?=htmlspecialchars((string)$cc['host'] . ':' . (string)$cc['port'], ENT_QUOTES, 'UTF-8')?></td>
        <td><?=htmlspecialchars((string)$cc['username'], ENT_QUOTES, 'UTF-8')?></td>
        <td><?=htmlspecialchars((string)$cc['authMethod'], ENT_QUOTES, 'UTF-8')?></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<form method="POST" action="<?=htmlspecialchars($handlerUrl, ENT_QUOTES, 'UTF-8')?>" id="ur-conns-form">
  <input type="hidden" name="action" value="saveCredentials">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8')?>">
  <input type="hidden" name="connections_present" value="1">

  <div id="ur-conns-container">
    <?php foreach ($connections as $i => $c) {
        ur_render_connection_card($c, (int) $i, $keys, $sshpassOk);
    } ?>
  </div>

  <div class="ur-actions">
    <button type="button" id="ur-add-conn"><?=_('Add connection')?></button>
    <input type="submit" value="<?=_('Apply')?>">
  </div>
</form>

<div id="ur-conns-result" class="ur-result"></div>

<!-- Hidden connection-card template for client-side cloning. -->
<script type="text/html" id="ur-conn-template">
<?php ur_render_connection_card(Credentials::defaultConnection(), '__CIDX__', $keys, $sshpassOk); ?>
</script>

<script type="text/javascript">
(function () {
  'use strict';

  var HANDLER = <?=json_encode($handlerUrl)?>;
  var CSRF = <?=json_encode($csrf)?>;

  function postForm(fields) {
    var fd = new FormData();
    fd.append('csrf_token', CSRF);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(HANDLER, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); });
  }

  function show(el, ok, msg) {
    if (!el) { return; }
    el.className = 'ur-result ' + (ok ? 'ur-ok' : 'ur-err');
    el.textContent = msg;
  }

  function errText(res, fallback) {
    if (res.body && res.body.errors && res.body.errors.length) { return res.body.errors.join('; '); }
    if (res.body && res.body.error) { return res.body.error; }
    return fallback;
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
      }).catch(function () { show(keyResult, false, 'Network error.'); });
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
      }).catch(function () { show(keyResult, false, 'Network error.'); });
    });
  }

  /* ---- copy public key / delete key ---- */
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
        window.prompt('Public key:', pub);
      }
    } else if (t.classList.contains('ur-key-del-saved')) {
      var kid = t.getAttribute('data-key-id');
      var kname = t.getAttribute('data-key-name') || kid;
      if (!window.confirm('Delete key "' + kname + '"? Connections that use it must be repointed first.')) { return; }
      postForm({ action: 'deleteKey', id: kid }).then(function (res) {
        if (res.ok && res.body && res.body.ok) {
          window.location.reload();
        } else {
          show(keyResult, false, errText(res, 'Delete failed.'));
        }
      }).catch(function () { show(keyResult, false, 'Network error.'); });
    } else if (t.classList.contains('ur-conn-del')) {
      var card = t.closest ? t.closest('.ur-conn-card') : null;
      if (!card) { return; }
      var savedId = card.getAttribute('data-conn-id') || '';
      if (savedId) {
        if (!window.confirm('Delete this saved connection? Jobs that use it will be DISABLED.')) { return; }
        postForm({ action: 'deleteConnection', id: savedId }).then(function (res) {
          if (res.ok && res.body && res.body.ok) {
            show(document.getElementById('ur-conns-result'), true, res.body.message || 'Deleted.');
            setTimeout(function () { window.location.reload(); }, 800);
          } else {
            show(document.getElementById('ur-conns-result'), false, errText(res, 'Delete failed.'));
          }
        }).catch(function () { show(document.getElementById('ur-conns-result'), false, 'Network error.'); });
      } else if (card.parentNode) {
        card.parentNode.removeChild(card); // unsaved card: just remove from DOM
      }
    } else if (t.classList.contains('ur-discover-hostkey')) {
      discoverHostKey(t);
    } else if (t.classList.contains('ur-test-conn')) {
      testConnection(t);
    }
  });

  /* ---- auth-method conditional fields ---- */
  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t && t.classList && t.classList.contains('ur-conn-auth')) {
      var idb = t.getAttribute('data-idb');
      var isKey = (t.value === 'KEY');
      ['_keyrow_dt', '_keyrow_dd'].forEach(function (s) {
        var el = document.getElementById(idb + s); if (el) { el.style.display = isKey ? '' : 'none'; }
      });
      ['_passrow_dt', '_passrow_dd'].forEach(function (s) {
        var el = document.getElementById(idb + s); if (el) { el.style.display = isKey ? 'none' : ''; }
      });
    }
  });

  /* ---- discover host key (fills the textarea) ---- */
  function discoverHostKey(btn) {
    var idb = btn.getAttribute('data-idb');
    var host = (document.getElementById(idb + '_host').value || '').trim();
    var port = (document.getElementById(idb + '_port').value || '22').trim();
    var ta = document.getElementById(idb + '_hostkey');
    if (!host) { window.alert('Enter a host first.'); return; }
    var old = btn.textContent; btn.textContent = 'Discovering…'; btn.disabled = true;
    postForm({ action: 'discoverHostKey', host: host, port: port }).then(function (res) {
      btn.textContent = old; btn.disabled = false;
      if (res.ok && res.body && res.body.ok) {
        if (ta) { ta.value = res.body.hostKey || ''; }
      } else {
        window.alert(errText(res, 'Host key discovery failed.'));
      }
    }).catch(function () { btn.textContent = old; btn.disabled = false; window.alert('Network error.'); });
  }

  /* ---- test connection (uses last SAVED settings) ---- */
  function testConnection(btn) {
    var connId = btn.getAttribute('data-conn-id');
    var card = btn.closest ? btn.closest('.ur-conn-card') : null;
    var resultEl = card ? card.querySelector('.ur-test-result') : null;
    if (resultEl) { resultEl.className = 'ur-test-result'; resultEl.textContent = 'Testing…'; }
    postForm({ action: 'testConnection', id: connId }).then(function (res) {
      if (!resultEl) { return; }
      var b = res.body || {};
      var ok = res.ok && b.ok;
      resultEl.className = 'ur-test-result ' + (ok ? 'ur-ok' : 'ur-err');
      resultEl.textContent = (b.message || (ok ? 'OK' : 'Failed')) + (b.reason && !ok ? ' [' + b.reason + ']' : '');
    }).catch(function () {
      if (resultEl) { resultEl.className = 'ur-test-result ur-err'; resultEl.textContent = 'Network error.'; }
    });
  }

  /* ---- add connection card from template ---- */
  function nextConnIndex() {
    var cards = document.querySelectorAll('#ur-conns-container .ur-conn-card');
    var max = -1;
    cards.forEach(function (c) {
      var n = parseInt(c.getAttribute('data-index'), 10);
      if (!isNaN(n) && n > max) { max = n; }
    });
    return max + 1;
  }
  var addConnBtn = document.getElementById('ur-add-conn');
  if (addConnBtn) {
    addConnBtn.addEventListener('click', function () {
      var tpl = document.getElementById('ur-conn-template').innerHTML;
      var idx = nextConnIndex();
      var html = tpl.split('__CIDX__').join(String(idx));
      var wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      var card = wrap.firstElementChild;
      document.getElementById('ur-conns-container').appendChild(card);
    });
  }

  /* ---- form submits (keys rename, connections) ---- */
  function wireForm(formId, resultId) {
    var form = document.getElementById(formId);
    if (!form) { return; }
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var result = document.getElementById(resultId);
      var fd = new FormData(form);
      fetch(form.getAttribute('action'), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
          if (res.ok && res.body && res.body.ok) {
            show(result, true, res.body.message || 'Saved.');
            setTimeout(function () { window.location.reload(); }, 600);
          } else {
            show(result, false, errText(res, 'Save failed.'));
          }
        })
        .catch(function () { show(result, false, 'Network error while saving.'); });
    });
  }
  wireForm('ur-keys-form', 'ur-keys-save-result');
  wireForm('ur-conns-form', 'ur-conns-result');
})();
</script>

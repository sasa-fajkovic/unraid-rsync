<?php
/**
 * credentials.php - the Credentials tab body (two-tier keychain).
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

    $id          = (string) $conn['id'];
    $name        = (string) $conn['name'];
    $host        = (string) $conn['host'];
    $port        = (string) $conn['port'];
    $username    = (string) $conn['username'];
    $auth        = (string) $conn['authMethod'];
    $keyId       = (string) $conn['keyId'];
    $keyFilePath = (string) $conn['keyFilePath'];
    $strict      = (string) $conn['strictHostKey'];
    $timeout     = (string) $conn['connectTimeout'];
    $hostKey     = (string) $conn['remoteHostKey'];
    $hasPass     = ((string) $conn['password']) !== '';
    // A KEYFILE connection always has SOME keyFilePath after merge; offer the
    // conventional default when it's somehow empty so the field is never blank.
    if ($keyFilePath === '') {
        $keyFilePath = Credentials::DEFAULT_KEY_FILE_PATH;
    }

    echo '<div class="ur-conn-card" data-index="' . ur_h($index) . '" data-conn-id="' . ur_h($id) . '">';
    echo '<input type="hidden" name="' . ur_h($p . '[id]') . '" value="' . ur_h($id) . '">';
    echo '<dl>';

    // name (required)
    echo '<dt><label for="' . ur_h($idb . '_name') . '">' . ur_h(ur_t('Name')) . '</label>' . ur_required_mark() . ':</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_name') . '" name="' . ur_h($p . '[name]') . '" value="' . ur_h($name) . '" required></dd>';

    // host (required)
    echo '<dt><label for="' . ur_h($idb . '_host') . '">' . ur_h(ur_t('Host')) . '</label>' . ur_required_mark() . ':</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_host') . '" name="' . ur_h($p . '[host]') . '" value="' . ur_h($host) . '" placeholder="host.example or 10.0.0.5" required></dd>';

    // port (required - has a sensible default of 22, but must not be blank)
    echo '<dt><label for="' . ur_h($idb . '_port') . '">' . ur_h(ur_t('Port')) . '</label>' . ur_required_mark() . ':</dt>';
    echo '<dd><input type="number" min="1" max="65535" id="' . ur_h($idb . '_port') . '" name="' . ur_h($p . '[port]') . '" value="' . ur_h($port) . '" placeholder="22" required></dd>';

    // username (required)
    echo '<dt><label for="' . ur_h($idb . '_user') . '">' . ur_h(ur_t('Username')) . '</label>' . ur_required_mark() . ':</dt>';
    echo '<dd><input type="text" id="' . ur_h($idb . '_user') . '" name="' . ur_h($p . '[username]') . '" value="' . ur_h($username) . '" required></dd>';

    // auth method. KEYFILE is FIRST (the default + common Unraid case): point at
    // an existing key file already on this server. KEY = the managed keychain
    // key created/imported above. PASSWORD = obfuscated stored password.
    echo '<dt><label for="' . ur_h($idb . '_auth') . '">' . ur_h(ur_t('Auth method')) . '</label>:</dt>';
    echo '<dd><select id="' . ur_h($idb . '_auth') . '" class="ur-conn-auth" name="' . ur_h($p . '[authMethod]') . '" data-idb="' . ur_h($idb) . '">';
    foreach ([
        'KEYFILE'  => 'Existing key file on this server',
        'KEY'      => 'Managed key (generated/imported here)',
        'PASSWORD' => 'Password',
    ] as $val => $lbl) {
        $sel = ($auth === $val) ? ' selected' : '';
        echo '<option value="' . ur_h($val) . '"' . $sel . '>' . ur_h(ur_t($lbl)) . '</option>';
    }
    echo '</select></dd>';

    // KEYFILE: path to an existing private key file on this server (shown +
    // required only when auth=KEYFILE). Nothing is uploaded/read/stored by the
    // plugin - OpenSSH reads the file in place via `ssh -i`.
    $isKeyFile        = ($auth === 'KEYFILE');
    $keyFileRowStyle  = $isKeyFile ? '' : ' style="display:none"';
    echo '<dt class="ur-auth-keyfile" id="' . ur_h($idb . '_keyfilerow_dt') . '"' . $keyFileRowStyle . '><label for="' . ur_h($idb . '_keyfile') . '">' . ur_h(ur_t('Key file path')) . '</label>' . ur_required_mark() . ':</dt>';
    echo '<dd class="ur-auth-keyfile" id="' . ur_h($idb . '_keyfilerow_dd') . '"' . $keyFileRowStyle . '>';
    echo '<input type="text" id="' . ur_h($idb . '_keyfile') . '" name="' . ur_h($p . '[keyFilePath]') . '" value="' . ur_h($keyFilePath) . '"' . ($isKeyFile ? ' required' : '') . ' placeholder="' . ur_h(Credentials::DEFAULT_KEY_FILE_PATH) . '">';
    echo '<blockquote class="inline_help"><p>'
        . ur_h(ur_t('Recommended if you already have an SSH key on this server. Your private key stays in ~/.ssh — '
            . 'nothing is uploaded or stored by the plugin. Make sure the remote already has the matching public key '
            . 'in its authorized_keys. The path must be absolute (e.g. /root/.ssh/id_ed25519); it is checked at run time, '
            . 'so the key only needs to exist when a job runs.'))
        . '</p></blockquote>';
    echo '</dd>';

    // KEY: managed-key picker (shown + required only when auth=KEY)
    $isKey       = ($auth === 'KEY');
    $keyRowStyle = $isKey ? '' : ' style="display:none"';
    echo '<dt class="ur-auth-key" id="' . ur_h($idb . '_keyrow_dt') . '"' . $keyRowStyle . '><label for="' . ur_h($idb . '_key') . '">' . ur_h(ur_t('SSH key')) . '</label>' . ur_required_mark() . ':</dt>';
    echo '<dd class="ur-auth-key" id="' . ur_h($idb . '_keyrow_dd') . '"' . $keyRowStyle . '>';
    echo '<select id="' . ur_h($idb . '_key') . '" name="' . ur_h($p . '[keyId]') . '"' . ($isKey ? ' required' : '') . '>';
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
    echo '<blockquote class="inline_help"><p>' . ur_h(ur_t('Uses a key the plugin manages (generated or imported in the SSH Keys section above). '
        . 'If you already have a key on this server, prefer the "Existing key file on this server" option instead.')) . '</p></blockquote>';
    echo '</dd>';

    // PASSWORD: write-only field + recoverable-secret warning (shown when
    // auth=PASSWORD). REQUIRED only when PASSWORD auth AND no password is stored
    // yet: on an edit where a password already exists, leaving the field blank
    // KEEPS the stored one, so we must NOT force a value there (it would block a
    // legitimate "edit other fields, keep password" save). The JS toggle mirrors
    // this exact rule, and the server (Credentials::validateConnection) is the
    // source of truth: a PASSWORD connection with no password is rejected.
    $isPass        = ($auth === 'PASSWORD');
    $passRequired  = $isPass && !$hasPass;
    $passRowStyle  = $isPass ? '' : ' style="display:none"';
    echo '<dt class="ur-auth-pass" id="' . ur_h($idb . '_passrow_dt') . '"' . $passRowStyle . '><label for="' . ur_h($idb . '_pass') . '">' . ur_h(ur_t('Password')) . '</label>'
        . '<abbr class="ur-required ur-pass-required" title="' . ur_h(ur_t('Required')) . '"' . ($passRequired ? '' : ' style="display:none"') . '>*</abbr>:</dt>';
    echo '<dd class="ur-auth-pass" id="' . ur_h($idb . '_passrow_dd') . '"' . $passRowStyle . '>';
    echo '<input type="password" id="' . ur_h($idb . '_pass') . '" data-haspass="' . ($hasPass ? '1' : '0') . '" name="' . ur_h($p . '[password]') . '" value="" autocomplete="new-password"' . ($passRequired ? ' required' : '') . ' placeholder="' . ur_h($hasPass ? ur_t('(unchanged - leave blank to keep)') : ur_t('(not set)')) . '">';
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
<style>
/* The "required" field marker: a red asterisk paired with the HTML5 `required`
   attribute on the mandatory inputs. text-decoration:none drops the dotted
   <abbr> underline so it reads as a clean asterisk. */
.ur-required { color: var(--red-800, #b71c1c); font-weight: bold; text-decoration: none; cursor: help; }

/* "Discover host key" progress: a thin determinate bar that fills toward the
   30s server cap plus a live countdown label, so the user sees it working and
   how long it can take. Hidden until a discovery is in flight (set inline by JS).
   Colours fall back to sane defaults when the webGui theme vars are absent. */
.ur-discover-progress { display: none; align-items: center; gap: 8px; margin-top: 6px; font-size: 0.85em; }
.ur-discover-bar { flex: 0 0 160px; height: 8px; border-radius: 4px; overflow: hidden;
  background: var(--color-tablebody, #e0e0e0); }
.ur-discover-bar-fill { width: 0%; height: 100%; border-radius: 4px;
  background: var(--blue-500, #2196f3); transition: width 0.1s linear; }
.ur-discover-label { color: var(--color-text-secondary, #777); white-space: nowrap; }
/* Terminal states for the discover progress: the bar must visibly resolve to
   success (green) or fail (red) rather than just vanishing, so the user always
   sees the outcome. Colours reuse the dynamix palette vars already used in this
   file for the required-asterisk red, with sane fallbacks. */
.ur-discover-progress.is-success .ur-discover-bar-fill { background: var(--green, #1c7d3f); }
.ur-discover-progress.is-success .ur-discover-label { color: var(--green, #1c7d3f); }
.ur-discover-progress.is-fail .ur-discover-bar-fill { background: var(--red-800, #b71c1c); }
.ur-discover-progress.is-fail .ur-discover-label { color: var(--red-800, #b71c1c); white-space: normal; }

/* Inline two-step delete confirm: an "armed" Delete/Remove button reuses the
   required-asterisk red so the destructive intent reads clearly, and the
   consequence warning is shown inline next to it (never in a popup). */
.ur-armed-delete { color: var(--red-800, #b71c1c); border-color: var(--red-800, #b71c1c); font-weight: bold; }

/* Inline copy-public-key fallback box (shown only when navigator.clipboard is
   unavailable): a readonly, selectable rendering of the key the user can copy
   by hand. */
.ur-copy-pub-fallback { display: block; width: 100%; margin-top: 6px; font-family: monospace; font-size: 0.85em; }
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
  <?=_('Reusable connections (and an optional managed key keychain). Jobs reference a connection by its id (shown here by name); define a connection once and point any number of jobs at it')?>.
</p>

<blockquote class="inline_help">
  <p>
    <strong><?=_('Most common setup')?>:</strong>
    <?=_('if you already have an SSH key on this server (e.g. /root/.ssh/id_ed25519) and have copied its public key to the '
        . 'remote\'s authorized_keys, you do NOT need the SSH Keys keychain below. Just add a Connection and choose '
        . '"Existing key file on this server" — the plugin runs ssh -i with your key, nothing is uploaded or stored')?>.
  </p>
</blockquote>

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
<div class="title"><span class="left"><?=_('SSH Keys (managed keychain)')?></span></div>

<blockquote class="inline_help">
  <p>
    <strong><?=_('Only needed if you do NOT already have an SSH key on this server')?>.</strong>
    <?=_('This generates or imports a key that the plugin manages and stores in credentials.json on the USB flash. '
        . 'If you already have /root/.ssh/id_ed25519 (or any key on this server), DO NOT generate one here — instead, '
        . 'on a Connection below choose "Existing key file on this server" and point it at your key')?>.
  </p>
  <p>
    <?=_('How SSH keys work: the PRIVATE key is what authenticates you and stays on this server (it is never sent to the remote). '
        . 'The PUBLIC key is what you put in the remote\'s ~/.ssh/authorized_keys so it will accept your private key. '
        . 'These keys identify YOU to the remote. The remote SERVER\'s own identity is verified separately — pin it per '
        . 'Connection with "Discover host key"')?>.
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
    <blockquote class="inline_help"><p><?=_('Importing is secondary — prefer "Generate key", or skip this section entirely and use a Connection\'s "Existing key file on this server" option if your key already lives on this box. '
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
 * (non-markdown) Connection form, which would otherwise leave Apply permanently
 * greyed out and a connection impossible to save through the UI. */
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

  var HANDLER = <?=ur_js($handlerUrl)?>;
  var CSRF = <?=ur_js($csrf)?>;

  /* POST a form and ALWAYS resolve to { ok, status, body, parseError }:
   *   - ok         the HTTP response was 2xx;
   *   - status     the numeric HTTP status (0 if the request never reached the
   *                server - a true network error);
   *   - body       the parsed JSON object, or null when the body wasn't JSON;
   *   - parseError true when a body was returned but was NOT valid JSON (e.g. an
   *                HTML 403/500 from the front controller) - so callers can show
   *                a clear "server returned a non-JSON response (HTTP <status>)"
   *                message instead of silently doing nothing.
   * This never rejects: a genuine network failure resolves with status 0 so the
   * UI is ALWAYS updated and an action can never leave a stuck "Generating…".
   *
   * opts.timeoutMs (optional): abort the fetch after this many ms via an
   * AbortController, so the browser never waits forever if the backend stalls.
   * An abort resolves with { aborted: true, status: 0 } so the caller can show a
   * distinct "timed out" message rather than a generic network error. */
  function postForm(fields, opts) {
    opts = opts || {};
    /* Send urlencoded (URLSearchParams), NOT multipart (FormData): a
       multipart/form-data body STALLS in php-fpm in the live Unraid environment
       (the worker blocks forever receiving the body over the FastCGI socket), so
       every plugin POST hung; urlencoded returns in ~13ms. No file inputs exist
       (keys are pasted into textareas). fetch() auto-sets the urlencoded
       Content-Type for a URLSearchParams body. */
    var params = new URLSearchParams();
    params.append('csrf_token', CSRF);
    Object.keys(fields).forEach(function (k) { params.append(k, fields[k]); });

    var init = { method: 'POST', body: params, credentials: 'same-origin' };
    var controller = null, timer = null;
    if (opts.timeoutMs && typeof AbortController !== 'undefined') {
      controller = new AbortController();
      init.signal = controller.signal;
      timer = setTimeout(function () { controller.abort(); }, opts.timeoutMs);
    }
    var clearTimer = function () { if (timer) { clearTimeout(timer); timer = null; } };

    return fetch(HANDLER, init)
      .then(function (r) {
        return r.text().then(function (text) {
          clearTimer();
          var body = null, parseError = false;
          try { body = JSON.parse(text); } catch (e) { parseError = (text !== ''); }
          return { ok: r.ok, status: r.status, body: body, parseError: parseError };
        });
      })
      .catch(function (e) {
        clearTimer();
        /* An AbortError is our own client-side timeout; everything else is a
           genuine "could not reach the server" (offline / connection reset). */
        if (e && e.name === 'AbortError') {
          return { ok: false, status: 0, body: null, parseError: false, aborted: true };
        }
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
    if (res.aborted) {
      return (fallback || 'Request failed') + ': timed out (no response in time).';
    }
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
   * First click on a Delete/Remove button ARMS it: the label becomes
   * "Confirm delete?", a red "armed" class is applied, and the destructive
   * consequence is shown inline in the supplied result element so the warning is
   * never lost. A second click within ARM_WINDOW_MS runs the delete; otherwise it
   * auto-reverts. Re-clicking a DIFFERENT armed button cancels any other. This is
   * non-blocking — there is no popup and automation is never frozen. */
  var ARM_WINDOW_MS = 4000;

  // At most ONE delete button is armed at a time. Arming a new one disarms the
  // previous, so two destructive buttons can never be armed simultaneously.
  var armedDeleteBtn = null;

  function disarmDelete(btn) {
    if (!btn || !btn._urArm) { return; }
    clearTimeout(btn._urArm.timer);
    btn.textContent = btn._urArm.label;
    btn.classList.remove('ur-armed-delete');
    var resultEl = btn._urArm.resultEl;
    var armedMsg = btn._urArm.message;
    // Only clear the inline warning if it STILL shows OUR exact armed message.
    // Other flows (key generate/import, the delete's own success/error) call
    // show(...) on the same element WITHOUT clearing data-ur-armed, so checking
    // the attribute alone could stomp an unrelated later message; matching the
    // text too makes the clear safe.
    if (resultEl && resultEl.getAttribute('data-ur-armed') === '1'
        && resultEl.textContent === armedMsg) {
      resultEl.className = 'ur-result';
      resultEl.textContent = '';
      resultEl.removeAttribute('data-ur-armed');
    } else if (resultEl && resultEl.getAttribute('data-ur-armed') === '1') {
      // Message was replaced by another flow; just drop our marker.
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
    // Arming a new button cancels any other still-armed one.
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
      // Reuse the existing result element to surface the consequence inline.
      // It is plain text via .textContent (no HTML injection).
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
        // No Clipboard API (e.g. non-secure context): reveal the key inline in a
        // readonly, pre-selected field so the user can copy it by hand. This is
        // non-blocking — no popup. Reuse one field per button.
        revealPublicKeyInline(t, pub);
      }
    } else if (t.classList.contains('ur-key-del-saved')) {
      // Two-step inline confirm (no popup). First click arms the button and shows
      // the destructive-consequence warning inline; a second click within the
      // window performs the delete.
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
    } else if (t.classList.contains('ur-conn-del')) {
      var card = t.closest ? t.closest('.ur-conn-card') : null;
      if (!card) { return; }
      var savedId = card.getAttribute('data-conn-id') || '';
      if (savedId) {
        // Two-step inline confirm (no popup), same pattern as key delete.
        var connsResult = document.getElementById('ur-conns-result');
        armOrConfirmDelete(t, {
          warning: 'Delete this saved connection? Jobs that use it will be DISABLED.',
          resultEl: connsResult,
          run: function () {
            postForm({ action: 'deleteConnection', id: savedId }).then(function (res) {
              if (res.ok && res.body && res.body.ok) {
                show(connsResult, true, res.body.message || 'Deleted.');
                setTimeout(function () { window.location.reload(); }, 800);
              } else {
                show(connsResult, false, errText(res, 'Delete failed.'));
              }
            }).catch(function (e) { show(connsResult, false, 'Unexpected error: ' + (e && e.message ? e.message : e)); });
          }
        });
      } else if (card.parentNode) {
        card.parentNode.removeChild(card); // unsaved card: just remove from DOM
      }
    } else if (t.classList.contains('ur-discover-hostkey')) {
      discoverHostKey(t);
    } else if (t.classList.contains('ur-test-conn')) {
      testConnection(t);
    }
  });

  /* ---- auth-method conditional fields ----
   * Show/hide AND toggle `required` so the client `required` matches the chosen
   * auth method and the server rules (Credentials::validateConnection):
   *   KEYFILE  -> key file path required;
   *   KEY      -> managed-key select required;
   *   PASSWORD -> password required UNLESS one is already stored (a blank field
   *               then keeps it).
   * A hidden field must NEVER be `required` (the browser would block submit on
   * an invisible field), so we always clear `required` on the inactive branches. */
  function syncAuthRequired(authSel) {
    if (!authSel || !authSel.getAttribute) { return; }
    var idb = authSel.getAttribute('data-idb');
    if (!idb) { return; }
    var mode = authSel.value; // 'KEYFILE' | 'KEY' | 'PASSWORD'
    var isKeyFile = (mode === 'KEYFILE');
    var isKey     = (mode === 'KEY');
    var isPass    = (mode === 'PASSWORD');

    function setDisplay(suffixes, shown) {
      suffixes.forEach(function (s) {
        var el = document.getElementById(idb + s);
        if (el) { el.style.display = shown ? '' : 'none'; }
      });
    }
    setDisplay(['_keyfilerow_dt', '_keyfilerow_dd'], isKeyFile);
    setDisplay(['_keyrow_dt', '_keyrow_dd'], isKey);
    setDisplay(['_passrow_dt', '_passrow_dd'], isPass);

    /* key file path: required only on KEYFILE auth. */
    var keyFileInput = document.getElementById(idb + '_keyfile');
    if (keyFileInput) { keyFileInput.required = isKeyFile; }

    /* managed-key select: required only on KEY auth. */
    var keySel = document.getElementById(idb + '_key');
    if (keySel) { keySel.required = isKey; }

    /* password field: required only on PASSWORD auth AND when none is stored. */
    var passInput = document.getElementById(idb + '_pass');
    var passMark  = document.getElementById(idb + '_passrow_dt');
    passMark = passMark ? passMark.querySelector('.ur-pass-required') : null;
    if (passInput) {
      var hasStored = passInput.getAttribute('data-haspass') === '1';
      var passRequired = isPass && !hasStored;
      passInput.required = passRequired;
      if (passMark) { passMark.style.display = passRequired ? '' : 'none'; }
    }
  }

  document.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t && t.classList && t.classList.contains('ur-conn-auth')) {
      syncAuthRequired(t);
    }
  });

  /* Seed the required state for every connection card on load (the server set
   * the initial values, but a JS-cloned card defaults to KEYFILE auth and must
   * reflect that). Re-run after adding a card. */
  function syncAllAuthRequired() {
    var sels = document.querySelectorAll('.ur-conn-auth');
    Array.prototype.forEach.call(sels, syncAuthRequired);
  }

  /* ---- discover host key (fills the textarea) ----
   * Time-bounded to the server's hard cap (30s): the button is disabled and a
   * progress bar + countdown advance toward 30s so the user knows it is working
   * and roughly how long it can take. The fetch is itself aborted client-side at
   * 35s via AbortController, so a stalled backend can never leave the UI spinning
   * forever. The progress widget always VISIBLY RESOLVES to a success (green,
   * 100%) or fail (red, with the concrete reason) terminal state — never a
   * blocking popup — then auto-clears. The abort budget sits ABOVE the server's
   * worst-case response time (DISCOVER_TIMEOUT_MAX 30s + DISCOVER_TIMEOUT_GRACE 2s
   * + up to ~1s for SIGKILL ≈ 33s) so that, whenever possible, the user gets the
   * server's structured timeout (504) message rather than a generic client-side
   * abort. */
  var DISCOVER_MAX_SECONDS = 30;          // mirrors KeyTools::DISCOVER_TIMEOUT_MAX
  var DISCOVER_CLIENT_ABORT_MS = 35000;   // > server worst-case (~33s) so the 504 wins
  var DISCOVER_SUCCESS_HIDE_MS = 2000;    // keep the green "done" state briefly, then hide
  var DISCOVER_FAIL_HIDE_MS = 6000;       // keep the red fail+reason longer to read

  function discoverHostKey(btn) {
    var idb = btn.getAttribute('data-idb');
    var host = (document.getElementById(idb + '_host').value || '').trim();
    var port = (document.getElementById(idb + '_port').value || '22').trim();
    var ta = document.getElementById(idb + '_hostkey');

    /* Locate (or build) the progress UI that lives next to this button. */
    var wrap = btn.parentNode;
    var prog = wrap ? wrap.querySelector('.ur-discover-progress') : null;
    if (!prog && wrap) {
      prog = document.createElement('div');
      prog.className = 'ur-discover-progress';
      prog.innerHTML =
        '<div class="ur-discover-bar"><div class="ur-discover-bar-fill"></div></div>'
        + '<span class="ur-discover-label"></span>';
      wrap.appendChild(prog);
    }
    var fill  = prog ? prog.querySelector('.ur-discover-bar-fill') : null;
    var label = prog ? prog.querySelector('.ur-discover-label') : null;

    /* Reset any prior terminal state and cancel its pending auto-hide. */
    if (prog) {
      prog.classList.remove('is-success', 'is-fail');
      if (prog._urHideTimer) { clearTimeout(prog._urHideTimer); prog._urHideTimer = null; }
    }

    /* Missing host: show an inline error in the label area (red), do NOT start
       the request, do NOT alert. */
    if (!host) {
      if (prog) {
        prog.style.display = 'flex';
        prog.classList.add('is-fail');
        if (fill) { fill.style.width = '100%'; }
        if (label) { label.textContent = 'Enter a host first.'; }
        prog._urHideTimer = setTimeout(function () {
          prog.style.display = 'none';
          prog.classList.remove('is-fail');
          if (fill) { fill.style.width = '0%'; }
        }, DISCOVER_FAIL_HIDE_MS);
      }
      return;
    }

    var old = btn.textContent;
    btn.disabled = true; btn.textContent = 'Discovering…';
    if (prog) { prog.style.display = 'flex'; }

    /* A 100ms ticker advances the bar/countdown toward the 30s cap. It does NOT
       cancel the request (the AbortController does that at 35s); it only conveys
       progress, so it caps the fill at 100% and keeps showing the max. */
    var started = Date.now();
    var ticker = setInterval(function () {
      var elapsed = (Date.now() - started) / 1000;
      var pct = Math.min(100, (elapsed / DISCOVER_MAX_SECONDS) * 100);
      var remaining = Math.max(0, Math.ceil(DISCOVER_MAX_SECONDS - elapsed));
      if (fill)  { fill.style.width = pct.toFixed(1) + '%'; }
      if (label) {
        label.textContent = remaining > 0
          ? ('Discovering host key… (up to ' + remaining + 's)')
          : 'Finishing up…';
      }
    }, 100);

    /* Stop the ticker and re-enable the button, but LEAVE the progress widget in
       place so a terminal (success/fail) state can be shown on it. */
    function stopTicker() {
      clearInterval(ticker);
      btn.disabled = false; btn.textContent = old;
    }

    function finishSuccess() {
      stopTicker();
      if (prog) {
        prog.classList.remove('is-fail');
        prog.classList.add('is-success');
        if (fill) { fill.style.width = '100%'; }
        if (label) { label.textContent = 'Host key discovered ✓'; }
        prog._urHideTimer = setTimeout(function () {
          prog.style.display = 'none';
          prog.classList.remove('is-success');
          if (fill) { fill.style.width = '0%'; }
        }, DISCOVER_SUCCESS_HIDE_MS);
      }
    }

    /* Show a red fail terminal state with the concrete reason. The bar reads as
       complete-but-failed (100%, red); it auto-clears after a while OR when the
       user clicks Discover again (handled by the reset at the top of this fn). */
    function finishFail(msg) {
      stopTicker();
      if (prog) {
        prog.classList.remove('is-success');
        prog.classList.add('is-fail');
        if (fill) { fill.style.width = '100%'; }
        if (label) { label.textContent = msg; }
        prog.style.display = 'flex';
        prog._urHideTimer = setTimeout(function () {
          prog.style.display = 'none';
          prog.classList.remove('is-fail');
          if (fill) { fill.style.width = '0%'; }
        }, DISCOVER_FAIL_HIDE_MS);
      }
    }

    postForm(
      { action: 'discoverHostKey', host: host, port: port, timeout: DISCOVER_MAX_SECONDS },
      { timeoutMs: DISCOVER_CLIENT_ABORT_MS }
    ).then(function (res) {
      if (res.ok && res.body && res.body.ok) {
        if (ta) { ta.value = res.body.hostKey || ''; }
        finishSuccess();
      } else {
        finishFail(errText(res, 'Host key discovery failed.'));
      }
    }).catch(function (e) {
      finishFail('Unexpected error: ' + (e && e.message ? e.message : e));
    });
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
      if (ok) {
        resultEl.textContent = b.message || 'OK';
      } else if (b.message) {
        // App-level failure with a structured message (auth/hostkey/etc).
        resultEl.textContent = b.message + (b.reason ? ' [' + b.reason + ']' : '');
      } else {
        // Transport-level failure (non-JSON / non-2xx / network) - surface the
        // status so the user isn't left guessing.
        resultEl.textContent = errText(res, 'Connection test failed.');
      }
    }).catch(function (e) {
      if (resultEl) {
        resultEl.className = 'ur-test-result ur-err';
        resultEl.textContent = 'Unexpected error: ' + (e && e.message ? e.message : e);
      }
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
      /* A new card defaults to KEYFILE auth (the template default); seed its
       * required state to match. */
      syncAllAuthRequired();
    });
  }

  /* ---- form submits (keys rename, connections) ----
   * Uses the SAME robust text->JSON parse as postForm so a non-JSON 403/500 (the
   * original silent-save bug: r.json() threw and the result line was never
   * updated) becomes a VISIBLE error WITH the HTTP status, and a success always
   * renders a clear "Saved" line. */
  function wireForm(formId, resultId) {
    var form = document.getElementById(formId);
    if (!form) { return; }
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var result = document.getElementById(resultId);
      /* urlencoded (URLSearchParams over the form's FormData), NOT multipart:
         multipart bodies stall in php-fpm in the live environment. Nested field
         names (keys[0][id], connections[0][host], …) round-trip unchanged into
         $_POST; there are no file inputs on these forms. */
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
            var hasWarnings = res.body.warnings && res.body.warnings.length;
            var msg = res.body.message || 'Saved.';
            if (hasWarnings) {
              msg += ' (' + res.body.warnings.join('; ') + ')';
            }
            // The save SUCCEEDED - always render as success (ur-ok). Warnings
            // (e.g. sshpass missing for password auth) are informational, not
            // blocking; we just append them and give the reader longer to see
            // them before reloading.
            show(result, true, msg);
            setTimeout(function () { window.location.reload(); }, hasWarnings ? 2500 : 600);
          } else {
            show(result, false, errText(res, 'Save failed.'));
          }
        });
    });
  }
  wireForm('ur-keys-form', 'ur-keys-save-result');
  wireForm('ur-conns-form', 'ur-conns-result');

  /* Seed the conditional-required state for all initially-rendered cards. */
  syncAllAuthRequired();
})();
</script>

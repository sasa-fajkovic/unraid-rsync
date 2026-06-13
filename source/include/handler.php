<?php
/**
 * handler.php - the single AJAX/REST endpoint for the Unraid Rsync plugin.
 *
 * Actions:
 *   POST saveConfig      (Phase 2) validate + persist jobs + global defaults.
 *   POST saveCredentials (Phase 3) validate + persist keys + connections
 *                        (section-aware: keys and connections are separate
 *                        forms and one never clobbers the other).
 *   POST generateKey     (Phase 3) ssh-keygen a new key pair (returns
 *                        fingerprint + public key; NEVER the private key).
 *   POST importKey       (Phase 3) import a pasted private/public key.
 *   POST deleteKey       (Phase 3) delete a key; BLOCKED when a connection
 *                        references it (returns the dependent connection names).
 *   POST deleteConnection(Phase 3) delete a connection; DISABLES dependent jobs
 *                        in config.json and reports which were disabled.
 *   POST discoverHostKey (Phase 3) ssh-keyscan a host -> host key for the form.
 *   POST testConnection  (Phase 3) probe a connection once and classify the
 *                        result (auth / host-key / unreachable).
 *
 * Every POST action is CSRF-protected with the webGui csrf_token. Secrets
 * (private keys, passwords) are NEVER echoed back to the browser.
 *
 * The endpoint is reached at:
 *   /plugins/unraid.rsync/include/handler.php
 * which the webGui serves through its authenticated PHP front controller.
 *
 * Design notes:
 *   - The CSRF token is the webGui-global $var['csrf_token'] (state.php),
 *     submitted as the `csrf_token` POST field on every form. We compare it
 *     with hash_equals to avoid timing leaks.
 *   - All responses are JSON with the right Content-Type + status code via the
 *     sendResponse()/sendError() helpers.
 *   - The actual validate/normalise/persist logic lives in Job.php / Config.php
 *     / Credentials.php / Ssh.php / KeyTools.php (I/O-light, unit-tested); this
 *     file is the thin HTTP shell.
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Job.php';
require_once __DIR__ . '/Credentials.php';
require_once __DIR__ . '/Ssh.php';
require_once __DIR__ . '/KeyTools.php';

/**
 * Emit a JSON success/data response and stop. Sets Content-Type and the HTTP
 * status code.
 *
 * @param array<string,mixed> $payload
 */
function sendResponse(array $payload, int $code = 200): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }

    // User-supplied strings (e.g. preHook/postHook) may contain invalid UTF-8,
    // which makes json_encode() return false. Substitute the bad bytes so we
    // always emit a valid JSON body rather than an empty one under a JSON
    // content-type. If even that fails, fall back to a minimal error envelope.
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $code = 500;
        $json = json_encode(
            ['error' => 'Internal error: response could not be encoded.'],
            JSON_UNESCAPED_SLASHES
        );
        if ($json === false) {
            $json = '{"error":"Internal error."}';
        }
    }

    http_response_code($code);
    echo $json;
    // In the live webGui this is the request end. exit keeps anything appended
    // by the front controller from corrupting the JSON body.
    if (!defined('UR_HANDLER_TESTING')) {
        exit;
    }
}

/**
 * Emit a JSON error response (a `{ "error": "..." }` envelope) and stop.
 */
function sendError(string $message, int $code = 400, array $extra = []): void
{
    sendResponse(array_merge(['error' => $message], $extra), $code);
}

/**
 * Resolve the expected webGui CSRF token. On a live Unraid box the global
 * $var array (populated from /usr/local/emhttp/state/var.ini by the front
 * controller) carries csrf_token; $_SESSION may also hold it. We read whatever
 * is available so the same code works under the webGui without a hard
 * dependency that would break unit tests.
 */
function ur_expected_csrf_token(): string
{
    if (isset($GLOBALS['var']) && is_array($GLOBALS['var']) && !empty($GLOBALS['var']['csrf_token'])) {
        return (string) $GLOBALS['var']['csrf_token'];
    }
    if (isset($_SESSION['csrf_token']) && !empty($_SESSION['csrf_token'])) {
        return (string) $_SESSION['csrf_token'];
    }
    return '';
}

/**
 * Verify the request carries a matching CSRF token. Returns true on success;
 * on failure it has already emitted a 403 and (outside tests) exited.
 */
function ur_check_csrf(): bool
{
    $expected = ur_expected_csrf_token();
    $supplied = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

    if ($expected === '' || $supplied === '' || !hash_equals($expected, $supplied)) {
        sendError('Invalid or missing CSRF token.', 403);
        return false;
    }
    return true;
}

/**
 * Handle POST saveConfig: rebuild the config from the submission, validate
 * every job, and persist on success. Returns JSON describing the outcome.
 *
 * Expected POST shape (nested form names round-trip into these):
 *   jobs[<i>][name], jobs[<i>][pairs][<k>][local], jobs[<i>][rsyncOptions][...]
 *   global[defaultRsyncOptions][...]
 */
function ur_action_save_config(): void
{
    // Start from the on-disk config so we preserve schemaVersion and any keys
    // the form does not submit. If the existing config can't be loaded (corrupt
    // JSON, or a NEWER schema this build doesn't understand), we must NOT fall
    // back to defaults and save - that would silently destroy the user's data.
    // Refuse the save and let the UI surface the problem instead.
    try {
        $config = Config::load();
    } catch (Throwable $e) {
        sendError(
            'Existing configuration could not be read, so the save was refused to '
            . 'avoid overwriting it: ' . $e->getMessage(),
            409
        );
        return;
    }

    // The Jobs tab and the Global Settings tab are separate forms that each
    // submit only their own section. Update a section ONLY when its payload is
    // present, otherwise saving one tab would wipe the other.
    //
    // The Jobs form carries a hidden `jobs_present=1` sentinel so that an
    // intentional "clear all jobs" (the user deleted every card -> no jobs[]
    // fields) is distinguishable from "the Jobs tab wasn't submitted at all".
    // When the sentinel is set we rebuild the jobs list even if it's empty.
    $hasGlobal = isset($_POST['global']) && is_array($_POST['global']);
    $jobsSubmitted = isset($_POST['jobs']) && is_array($_POST['jobs']);
    $jobsSentinel  = !empty($_POST['jobs_present']);
    $hasJobs       = $jobsSubmitted || $jobsSentinel;

    if (!$hasGlobal && !$hasJobs) {
        sendError('Nothing to save: no jobs or global settings were submitted.', 400);
        return;
    }

    // Global default rsync options (the Global Settings tab) - only when sent.
    if ($hasGlobal) {
        $globalIn      = $_POST['global'];
        $defaultOptsIn = (isset($globalIn['defaultRsyncOptions']) && is_array($globalIn['defaultRsyncOptions']))
            ? $globalIn['defaultRsyncOptions']
            : [];
        $config['global']['defaultRsyncOptions'] = Job::normalizeRsyncOptions($defaultOptsIn);
    }

    $allErrors   = [];
    $allWarnings = [];

    // Jobs (the Jobs tab) - only rebuild the jobs list when it was submitted.
    if (!$hasJobs) {
        // Settings-only save: persist the (validated-by-load) config with the
        // updated global section and return.
        try {
            Config::save($config);
        } catch (Throwable $e) {
            sendError('Failed to save configuration: ' . $e->getMessage(), 500);
            return;
        }
        sendResponse([
            'ok'       => true,
            'message'  => 'Configuration saved.',
            'warnings' => $allWarnings,
            'jobs'     => count($config['jobs'] ?? []),
        ], 200);
        return;
    }

    // $rawJobs is conditional: an explicit clear-all (sentinel set, no jobs[])
    // yields an empty array cleanly without a notice.
    $rawJobs    = $jobsSubmitted ? array_values($_POST['jobs']) : []; // re-key sparse/assoc arrays
    $normalized = [];
    $seenIds    = [];

    foreach ($rawJobs as $i => $rawJob) {
        if (!is_array($rawJob)) {
            continue;
        }
        $job = Job::normalize($rawJob);

        // Ensure unique ids across the set (a duplicate would clobber state /
        // cron files in later phases).
        $baseId = $job['id'];
        $id = $baseId;
        $suffix = 2;
        while (isset($seenIds[$id])) {
            $id = $baseId . '-' . $suffix;
            $suffix++;
        }
        $job['id'] = $id;
        $seenIds[$id] = true;

        $result = Job::validate($job);
        $label  = ($job['name'] !== '') ? $job['name'] : ('#' . ($i + 1));
        foreach ($result['errors'] as $e) {
            $allErrors[] = "Job \"$label\": $e";
        }
        foreach ($result['warnings'] as $w) {
            $allWarnings[] = "Job \"$label\": $w";
        }
        $normalized[] = $job;
    }

    if (!empty($allErrors)) {
        sendError('Validation failed.', 422, [
            'errors'   => $allErrors,
            'warnings' => $allWarnings,
        ]);
        return;
    }

    $config['jobs'] = $normalized;

    try {
        Config::save($config);
    } catch (Throwable $e) {
        sendError('Failed to save configuration: ' . $e->getMessage(), 500);
        return;
    }

    sendResponse([
        'ok'       => true,
        'message'  => 'Configuration saved.',
        'warnings' => $allWarnings,
        'jobs'     => count($normalized),
    ], 200);
}

// =====================================================================
// Phase 3: Credentials actions
// =====================================================================

/**
 * Normalise a raw key submission ($_POST['keys'][i]) into the stored shape.
 * The privateKey/publicKey/fingerprint are NEVER taken from the browser on a
 * plain save - they're set only via generateKey / importKey and preserved here
 * from the existing on-disk key (matched by id). The save form carries only id
 * + name (rename), so a save can never inject or alter key material.
 *
 * @param array<string,mixed> $raw       one raw key row from the form
 * @param array<string,mixed> $existing  the loaded credentials structure (to
 *                                        preserve secret material by id)
 * @return array<string,mixed>
 */
function ur_normalize_key_for_save(array $raw, array $existing): array
{
    $key  = Credentials::defaultKey();
    $id   = isset($raw['id']) ? trim((string) $raw['id']) : '';
    $name = isset($raw['name']) ? trim((string) $raw['name']) : '';

    // Preserve existing secret material; the save form only carries id + name.
    if ($id !== '') {
        $prev = Credentials::findKey($existing, $id);
        if ($prev !== null) {
            $key['privateKey']  = (string) ($prev['privateKey'] ?? '');
            $key['publicKey']   = (string) ($prev['publicKey'] ?? '');
            $key['fingerprint'] = (string) ($prev['fingerprint'] ?? '');
        }
    }

    $key['id']   = $id;
    $key['name'] = $name;
    return $key;
}

/**
 * Normalise a raw connection submission into the stored shape. The password is
 * special: an empty submitted password PRESERVES the existing stored
 * (obfuscated) password rather than clearing it (so editing other fields
 * doesn't wipe a set password); a non-empty submitted password is obfuscated
 * and replaces it. Switching auth away from PASSWORD clears the stored password.
 *
 * @param array<string,mixed> $raw      one raw connection row
 * @param array<string,mixed> $existing loaded credentials structure
 * @return array<string,mixed>
 */
function ur_normalize_connection_for_save(array $raw, array $existing): array
{
    $conn = Credentials::mergeConnection([
        'id'             => isset($raw['id'])             ? (string) $raw['id']             : '',
        'name'           => isset($raw['name'])           ? (string) $raw['name']           : '',
        'host'           => isset($raw['host'])           ? (string) $raw['host']           : '',
        'port'           => isset($raw['port'])           ? (string) $raw['port']           : 22,
        'username'       => isset($raw['username'])       ? (string) $raw['username']       : '',
        'authMethod'     => isset($raw['authMethod'])     ? (string) $raw['authMethod']     : 'KEY',
        'keyId'          => isset($raw['keyId'])          ? (string) $raw['keyId']          : '',
        'remoteHostKey'  => isset($raw['remoteHostKey'])  ? (string) $raw['remoteHostKey']  : '',
        'strictHostKey'  => isset($raw['strictHostKey'])  ? (string) $raw['strictHostKey']  : 'accept-new',
        'connectTimeout' => isset($raw['connectTimeout']) ? (string) $raw['connectTimeout'] : 10,
        // password handled below
    ]);

    $id            = $conn['id'];
    $submittedPass = isset($raw['password']) ? (string) $raw['password'] : '';
    $prev          = ($id !== '') ? Credentials::findConnection($existing, $id) : null;

    if ($conn['authMethod'] !== 'PASSWORD') {
        // KEY auth: never carry a password.
        $conn['password'] = '';
    } elseif ($submittedPass !== '') {
        // New/changed password -> obfuscate and store.
        $conn['password'] = Credentials::obfuscate($submittedPass);
    } elseif ($prev !== null) {
        // Empty submission -> preserve the existing stored (obfuscated) value.
        $conn['password'] = (string) ($prev['password'] ?? '');
    } else {
        $conn['password'] = '';
    }

    // KEY auth carries no keyId? keep whatever was submitted; validation checks it.
    if ($conn['authMethod'] !== 'KEY') {
        $conn['keyId'] = '';
    }

    return $conn;
}

/**
 * POST saveCredentials: rebuild keys and/or connections from the submission and
 * persist. Section-aware (like saveConfig): the Keys form and the Connections
 * form each submit only their own section with a *_present sentinel, so saving
 * one never wipes the other. Refuses (409) when the existing credentials.json
 * can't be read, to avoid clobbering a recoverable secrets file.
 */
function ur_action_save_credentials(): void
{
    try {
        $creds = Credentials::load();
    } catch (Throwable $e) {
        sendError(
            'Existing credentials could not be read, so the save was refused to '
            . 'avoid overwriting them: ' . $e->getMessage(),
            409
        );
        return;
    }

    $keysSubmitted  = isset($_POST['keys']) && is_array($_POST['keys']);
    $keysSentinel   = !empty($_POST['keys_present']);
    $hasKeys        = $keysSubmitted || $keysSentinel;

    $connsSubmitted = isset($_POST['connections']) && is_array($_POST['connections']);
    $connsSentinel  = !empty($_POST['connections_present']);
    $hasConns       = $connsSubmitted || $connsSentinel;

    if (!$hasKeys && !$hasConns) {
        sendError('Nothing to save: no keys or connections were submitted.', 400);
        return;
    }

    $errors = [];

    // --- keys (rename only; material is preserved by id) ---
    if ($hasKeys) {
        $rawKeys = $keysSubmitted ? array_values($_POST['keys']) : [];
        $newKeys = [];
        $names   = [];
        foreach ($rawKeys as $rawKey) {
            if (!is_array($rawKey)) {
                continue;
            }
            $key = ur_normalize_key_for_save($rawKey, $creds);
            // A row with no id AND no name is an empty template row -> skip.
            if ($key['id'] === '' && $key['name'] === '') {
                continue;
            }
            // Uniqueness is checked against the names seen so far in this set.
            $res = Credentials::validateKey($key, $names);
            foreach ($res['errors'] as $e) {
                $errors[] = 'Key "' . ($key['name'] !== '' ? $key['name'] : $key['id']) . '": ' . $e;
            }
            $names[]   = $key['name'];
            $newKeys[] = $key;
        }
        $creds['keys'] = $newKeys;
    }

    // --- connections ---
    if ($hasConns) {
        $rawConns = $connsSubmitted ? array_values($_POST['connections']) : [];
        $newConns = [];
        $seenIds  = [];
        foreach ($rawConns as $i => $rawConn) {
            if (!is_array($rawConn)) {
                continue;
            }
            $conn = ur_normalize_connection_for_save($rawConn, $creds);
            if ($conn['name'] === '' && $conn['host'] === '' && $conn['username'] === '') {
                continue; // empty template row
            }
            // Assign / de-duplicate id.
            if ($conn['id'] === '') {
                $conn['id'] = Credentials::generateId($conn['name'], 'c-', array_keys($seenIds));
            }
            $baseId = $conn['id'];
            $id     = $baseId;
            $n      = 2;
            while (isset($seenIds[$id])) {
                $id = $baseId . '-' . $n;
                $n++;
            }
            $conn['id']    = $id;
            $seenIds[$id]  = true;

            // Validate against the credentials we're about to save (so a KEY
            // connection's keyId is checked against the just-submitted keys).
            $res = Credentials::validateConnection($conn, $creds);
            $label = $conn['name'] !== '' ? $conn['name'] : ('#' . ($i + 1));
            foreach ($res['errors'] as $e) {
                $errors[] = 'Connection "' . $label . '": ' . $e;
            }
            $newConns[] = $conn;
        }
        $creds['connections'] = $newConns;
    }

    if (!empty($errors)) {
        sendError('Validation failed.', 422, ['errors' => $errors]);
        return;
    }

    try {
        Credentials::save($creds);
    } catch (Throwable $e) {
        sendError('Failed to save credentials: ' . $e->getMessage(), 500);
        return;
    }

    sendResponse([
        'ok'          => true,
        'message'     => 'Credentials saved.',
        'keys'        => count($creds['keys']),
        'connections' => count($creds['connections']),
    ], 200);
}

/**
 * POST generateKey: ssh-keygen a new key pair, store it, and return the
 * fingerprint + public key (NEVER the private key). The new key is appended to
 * credentials.json so a subsequent connection can reference it immediately.
 */
function ur_action_generate_key(): void
{
    try {
        $creds = Credentials::load();
    } catch (Throwable $e) {
        sendError('Existing credentials could not be read: ' . $e->getMessage(), 409);
        return;
    }

    $name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
    $type = isset($_POST['type']) ? (string) $_POST['type'] : 'ed25519';
    if ($name === '') {
        sendError('A key name is required.', 422);
        return;
    }
    // Uniqueness across existing keys.
    $existingNames = array_map(static fn($k) => (string) ($k['name'] ?? ''), $creds['keys']);
    if (in_array(strtolower($name), array_map('strtolower', $existingNames), true)) {
        sendError('A key named "' . $name . '" already exists; names must be unique.', 422);
        return;
    }

    $gen = KeyTools::generate($type, $name);
    if (empty($gen['ok'])) {
        sendError((string) ($gen['error'] ?? 'Key generation failed.'), 500);
        return;
    }

    $id = Credentials::generateId($name, 'k-', array_column($creds['keys'], 'id'));
    $creds['keys'][] = [
        'id'          => $id,
        'name'        => $name,
        'privateKey'  => (string) $gen['privateKey'],
        'publicKey'   => (string) $gen['publicKey'],
        'fingerprint' => (string) $gen['fingerprint'],
    ];

    try {
        Credentials::save($creds);
    } catch (Throwable $e) {
        sendError('Failed to save the generated key: ' . $e->getMessage(), 500);
        return;
    }

    // Return only non-secret material.
    sendResponse([
        'ok'          => true,
        'message'     => 'Key generated.',
        'id'          => $id,
        'name'        => $name,
        'publicKey'   => (string) $gen['publicKey'],
        'fingerprint' => (string) $gen['fingerprint'],
    ], 200);
}

/**
 * POST importKey: import a pasted private and/or public key. Derives/validates
 * the public key + fingerprint, stores it, and returns only the non-secret
 * material.
 */
function ur_action_import_key(): void
{
    try {
        $creds = Credentials::load();
    } catch (Throwable $e) {
        sendError('Existing credentials could not be read: ' . $e->getMessage(), 409);
        return;
    }

    $name    = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
    $private = isset($_POST['privateKey']) ? (string) $_POST['privateKey'] : '';
    $public  = isset($_POST['publicKey']) ? (string) $_POST['publicKey'] : '';
    if ($name === '') {
        sendError('A key name is required.', 422);
        return;
    }
    $existingNames = array_map(static fn($k) => (string) ($k['name'] ?? ''), $creds['keys']);
    if (in_array(strtolower($name), array_map('strtolower', $existingNames), true)) {
        sendError('A key named "' . $name . '" already exists; names must be unique.', 422);
        return;
    }

    $imp = KeyTools::import($private, $public);
    if (empty($imp['ok'])) {
        sendError((string) ($imp['error'] ?? 'Key import failed.'), 422);
        return;
    }

    $id = Credentials::generateId($name, 'k-', array_column($creds['keys'], 'id'));
    $creds['keys'][] = [
        'id'          => $id,
        'name'        => $name,
        'privateKey'  => (string) $imp['privateKey'],
        'publicKey'   => (string) $imp['publicKey'],
        'fingerprint' => (string) $imp['fingerprint'],
    ];

    try {
        Credentials::save($creds);
    } catch (Throwable $e) {
        sendError('Failed to save the imported key: ' . $e->getMessage(), 500);
        return;
    }

    sendResponse([
        'ok'          => true,
        'message'     => 'Key imported.',
        'id'          => $id,
        'name'        => $name,
        'publicKey'   => (string) $imp['publicKey'],
        'fingerprint' => (string) $imp['fingerprint'],
        // A pasted public-key-only import has no private material to run with.
        'hasPrivate'  => trim((string) $imp['privateKey']) !== '',
    ], 200);
}

/**
 * POST deleteKey: delete a key by id. BLOCKED (409) when any connection
 * references it - the response lists the dependent connection names so the user
 * can repoint/remove them first.
 */
function ur_action_delete_key(): void
{
    try {
        $creds = Credentials::load();
    } catch (Throwable $e) {
        sendError('Existing credentials could not be read: ' . $e->getMessage(), 409);
        return;
    }

    $id = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
    if ($id === '') {
        sendError('A key id is required.', 422);
        return;
    }
    if (Credentials::findKey($creds, $id) === null) {
        sendError('Key not found.', 404);
        return;
    }

    $used = Credentials::usedBy($creds, 'key', $id);
    $deps = $used['connections'] ?? [];
    if (!empty($deps)) {
        $names = array_map(static fn($c) => (string) $c['name'], $deps);
        sendError(
            'This key is in use and cannot be deleted. Repoint or remove these connections first: '
            . implode(', ', $names) . '.',
            409,
            ['usedBy' => $deps]
        );
        return;
    }

    $creds['keys'] = array_values(array_filter(
        $creds['keys'],
        static fn($k) => (string) ($k['id'] ?? '') !== $id
    ));

    try {
        Credentials::save($creds);
    } catch (Throwable $e) {
        sendError('Failed to delete the key: ' . $e->getMessage(), 500);
        return;
    }

    sendResponse(['ok' => true, 'message' => 'Key deleted.'], 200);
}

/**
 * POST deleteConnection: delete a connection by id. Jobs in config.json that
 * reference it are DISABLED (enabled=false) rather than left silently broken;
 * the response reports which jobs were disabled.
 */
function ur_action_delete_connection(): void
{
    try {
        $creds = Credentials::load();
    } catch (Throwable $e) {
        sendError('Existing credentials could not be read: ' . $e->getMessage(), 409);
        return;
    }

    $id = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
    if ($id === '') {
        sendError('A connection id is required.', 422);
        return;
    }
    if (Credentials::findConnection($creds, $id) === null) {
        sendError('Connection not found.', 404);
        return;
    }

    // Load config to find + disable dependent jobs. If config can't be read we
    // refuse, so we don't delete the connection while leaving the dependency
    // state unknown.
    try {
        $config = Config::load();
    } catch (Throwable $e) {
        sendError('Configuration could not be read, so the connection was not deleted: ' . $e->getMessage(), 409);
        return;
    }

    $disabled = [];
    if (isset($config['jobs']) && is_array($config['jobs'])) {
        foreach ($config['jobs'] as $idx => $job) {
            if (is_array($job) && (string) ($job['connectionId'] ?? '') === $id && !empty($job['enabled'])) {
                $config['jobs'][$idx]['enabled'] = false;
                $disabled[] = (string) ($job['name'] ?? $job['id'] ?? '');
            }
        }
    }

    // Remove the connection.
    $creds['connections'] = array_values(array_filter(
        $creds['connections'],
        static fn($c) => (string) ($c['id'] ?? '') !== $id
    ));

    // Persist config first (disabling jobs), then credentials. If disabling
    // jobs is a no-op, skip the config write.
    try {
        if (!empty($disabled)) {
            Config::save($config);
        }
        Credentials::save($creds);
    } catch (Throwable $e) {
        sendError('Failed to delete the connection: ' . $e->getMessage(), 500);
        return;
    }

    $msg = 'Connection deleted.';
    if (!empty($disabled)) {
        $msg .= ' Disabled ' . count($disabled) . ' dependent job(s): ' . implode(', ', $disabled) . '.';
    }
    sendResponse([
        'ok'             => true,
        'message'        => $msg,
        'disabledJobs'   => $disabled,
    ], 200);
}

/**
 * POST discoverHostKey: ssh-keyscan a host:port and return the host key text
 * for the connection form to pin. Does NOT persist anything.
 */
function ur_action_discover_host_key(): void
{
    $host    = isset($_POST['host']) ? trim((string) $_POST['host']) : '';
    $port    = isset($_POST['port']) ? (int) $_POST['port'] : 22;
    $timeout = isset($_POST['timeout']) ? (int) $_POST['timeout'] : 10;

    $res = KeyTools::discoverHostKey($host, $port, $timeout);
    if (empty($res['ok'])) {
        sendError((string) ($res['error'] ?? 'Host key discovery failed.'), 422);
        return;
    }
    sendResponse([
        'ok'      => true,
        'hostKey' => (string) $res['hostKey'],
    ], 200);
}

/**
 * POST testConnection: probe a saved connection by id and classify the result.
 * Never returns secrets.
 */
function ur_action_test_connection(): void
{
    try {
        $creds = Credentials::load();
    } catch (Throwable $e) {
        sendError('Existing credentials could not be read: ' . $e->getMessage(), 409);
        return;
    }

    $id = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
    if ($id === '') {
        sendError('A connection id is required.', 422);
        return;
    }
    if (Credentials::findConnection($creds, $id) === null) {
        sendError('Connection not found.', 404);
        return;
    }

    $res = Ssh::testConnection($creds, $id);
    // 200 regardless of probe success - the probe RAN; the body carries ok +
    // the distinct reason. (A 4xx/5xx is reserved for the request itself
    // failing.)
    sendResponse([
        'ok'      => (bool) $res['ok'],
        'reason'  => (string) $res['reason'],
        'message' => (string) $res['message'],
    ], 200);
}

/**
 * Front-controller dispatch. Skipped when included by the test harness
 * (UR_HANDLER_TESTING defined), which calls the individual functions directly.
 */
function ur_handle_request(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = '';
    if ($method === 'POST') {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    } else {
        $action = isset($_GET['action']) ? (string) $_GET['action'] : '';
    }

    switch ($action) {
        case 'saveConfig':
            if ($method !== 'POST') {
                sendError('saveConfig requires POST.', 405);
                return;
            }
            if (!ur_check_csrf()) {
                return; // ur_check_csrf already responded
            }
            ur_action_save_config();
            return;

        case 'saveCredentials':
        case 'generateKey':
        case 'importKey':
        case 'deleteKey':
        case 'deleteConnection':
        case 'discoverHostKey':
        case 'testConnection':
            if ($method !== 'POST') {
                sendError($action . ' requires POST.', 405);
                return;
            }
            if (!ur_check_csrf()) {
                return; // ur_check_csrf already responded
            }
            switch ($action) {
                case 'saveCredentials':   ur_action_save_credentials();   return;
                case 'generateKey':       ur_action_generate_key();       return;
                case 'importKey':         ur_action_import_key();          return;
                case 'deleteKey':         ur_action_delete_key();          return;
                case 'deleteConnection':  ur_action_delete_connection();   return;
                case 'discoverHostKey':   ur_action_discover_host_key();   return;
                case 'testConnection':    ur_action_test_connection();     return;
            }
            return;

        default:
            sendError('Unknown action.', 400);
            return;
    }
}

if (!defined('UR_HANDLER_TESTING')) {
    ur_handle_request();
}

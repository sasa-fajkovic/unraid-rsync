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
 *   - The CSRF token is the webGui-global $var['csrf_token'], submitted as the
 *     `csrf_token` POST field on every form, and compared with hash_equals to
 *     avoid timing leaks.
 *
 *     ROOT CAUSE of the "every POST fails" bug (diagnosed LIVE on a real Unraid
 *     box; corrects the earlier partial diagnoses). Two independent causes:
 *       1. multipart request bodies STALL at the FastCGI layer. The client JS
 *          posted via `new FormData()` (multipart/form-data); on the live box a
 *          multipart POST to this handler HUNG - the php-fpm worker sat in kernel
 *          skb_wait_for_more_packets waiting to receive a request body that never
 *          completed over the FastCGI socket. The SAME endpoint with an
 *          application/x-www-form-urlencoded body returned in ~13ms. FIX: the
 *          client now sends all POSTs urlencoded (URLSearchParams); see the
 *          window.urAjax helpers in source/pages/_options_form.php. (This - NOT
 *          the "discovery session-lock wedge" hypothesised in PR#24 - is why
 *          POSTs hung. PR#24's session_write_close / detached-keyscan are kept as
 *          harmless hardening.) There are NO file uploads anywhere (SSH keys are
 *          pasted into textareas), so urlencoded is correct and sufficient.
 *       2. the CSRF check rejected the CORRECT token. A urlencoded POST carrying
 *          the byte-identical page token still 403'd, because the old logic picked
 *          the FIRST non-empty token source ($GLOBALS['var'] / $_SESSION) and
 *          compared only that - a stale value there masked the canonical var.ini
 *          token, so the right token never got a chance to match. FIX: the check
 *          now accepts the supplied token if it hash_equals ANY server-side
 *          candidate (var, session, AND var.ini); see ur_csrf_token_candidates()
 *          / ur_check_csrf().
 *     ($GLOBALS['var'] is populated by the webGui front controller only when a
 *     page renders through DefaultPageLayout; a DIRECT POST to this handler never
 *     has it, which is why reading var.ini directly - exactly the value the webGui
 *     embeds in the page's forms - is essential.)
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
require_once __DIR__ . '/Rsync.php';
require_once __DIR__ . '/RunState.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Cron.php';
// Runner is used by getStatus (Runner::readSummary). There is no autoloader, so
// it MUST be required explicitly: without this, every getStatus poll throws
// "Class Runner not found" the moment a job exists (the unit tests masked it
// because the bootstrap loads all classes). The detached run itself launches a
// separate runner.php process that requires Runner on its own.
require_once __DIR__ . '/Runner.php';

/**
 * Re-sync the live crontab to the saved config. This is the single place the
 * handler re-applies scheduling after a config change that can affect a job's
 * schedule or enabled state (saveConfig's jobs path; deleteConnection disabling
 * dependent jobs). Cron::apply() regenerates the one *.cron file from config.json
 * and invokes update_cron.
 *
 * It is best-effort by design: a save has ALREADY succeeded by the time we get
 * here, so a cron-sync failure must NOT turn into a 5xx that makes the UI think
 * the save was lost. It ALWAYS returns a structured result (never null and never
 * rethrows): an unexpected Throwable from apply() is folded into an ok=false
 * result with the message, so a caller can uniformly surface a non-fatal warning
 * by checking `ok` alone - the failure is never silently swallowed.
 *
 * @param array<string,mixed>|null $config a config already in hand (post-mutation)
 *                                          to avoid a re-read race; null => load.
 * @return array{ok:bool,error?:string,updateCronCode?:int} the apply() result, or
 *                                          a synthesised ok=false on a throw.
 */
function ur_resync_cron(?array $config = null): array
{
    try {
        return Cron::apply($config);
    } catch (Throwable $e) {
        return [
            'ok'             => false,
            'enabledJobs'    => 0,
            'wrote'          => false,
            'removed'        => false,
            'updateCronCode' => -1,
            'error'          => 'Unexpected error applying schedule: ' . $e->getMessage(),
        ];
    }
}

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

    // Only set the status code while headers are still open. On PHP 8.4+
    // http_response_code() emits an E_WARNING ("Cannot set response code -
    // headers already sent") once output has begun; guarding it (symmetric with
    // the header() guard above) avoids that warning if anything was emitted
    // before us, rather than silencing it.
    if (!headers_sent()) {
        http_response_code($code);
    }
    // Test seam (gated, like the exit below): record the intended status code so
    // tests can assert it without depending on SAPI state. Under CLI + PHP 8.4
    // http_response_code() does not track a code once output has begun (and the
    // setter is guarded above anyway), so the getter is unreliable in tests.
    if (defined('UR_HANDLER_TESTING')) {
        $GLOBALS['ur_last_response_code'] = $code;
    }
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
 * Candidate paths to the Unraid state file that carries csrf_token. The first
 * readable one wins. /var/local/emhttp/var.ini is the canonical location;
 * /usr/local/emhttp/state/var.ini is the historical alias some releases expose.
 * Overridable in tests via the UR_VAR_INI_PATHS constant (an array of paths).
 *
 * @return array<int,string>
 */
function ur_var_ini_candidates(): array
{
    if (defined('UR_VAR_INI_PATHS') && is_array(UR_VAR_INI_PATHS)) {
        return UR_VAR_INI_PATHS;
    }
    return ['/var/local/emhttp/var.ini', '/usr/local/emhttp/state/var.ini'];
}

/**
 * Extract EVERY csrf_token value carried by a single var.ini file, using TWO
 * independent strategies and returning the union (non-empty, de-duplicated):
 *
 *   1. parse_ini_file() - the canonical parse.
 *   2. a tolerant line/regex scan of the raw file text.
 *
 * WHY BOTH (the live root cause): on the real Unraid box parse_ini_file() can
 * return FALSE for the whole var.ini - it is a large, machine-written state file
 * and a SINGLE syntactically-awkward line anywhere in it makes the parser bail,
 * yielding NO csrf_token even though the file is perfectly readable and the token
 * line itself is trivial (csrf_token="...."). When that happens on a DIRECT POST
 * to handler.php (where $GLOBALS['var'] / $_SESSION are not populated, so var.ini
 * is the ONLY candidate source) the correct token never enters the candidate set
 * and EVERY state-changing POST 403s. The regex scan reads just the one line we
 * need and is immune to unrelated malformed lines, so the canonical token is
 * always recovered as long as the file is readable.
 *
 * @return array<int,string> non-empty, de-duplicated tokens found in $path
 */
function ur_csrf_tokens_from_ini(string $path): array
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }
    $tokens = [];

    // Strategy 1: parse_ini_file (no sections). Suppress warnings on a malformed
    // file and fall through to the line scan rather than emitting HTML noise.
    $ini = @parse_ini_file($path, false, INI_SCANNER_RAW);
    if (is_array($ini) && isset($ini['csrf_token'])) {
        $tokens[] = (string) $ini['csrf_token'];
    }

    // Strategy 2: tolerant scan, line-by-line so we NEVER slurp the whole (large)
    // state file into memory. Collect EVERY csrf_token line - optional surrounding
    // quotes and whitespace - so an unrelated malformed line elsewhere can't hide
    // the token. Matches `csrf_token = "X"`, `csrf_token=X`, etc.
    $fh = @fopen($path, 'rb');
    if ($fh !== false) {
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if (preg_match('/^[ \t]*csrf_token[ \t]*=[ \t]*"?(.*?)"?[ \t]*$/i', $line, $m)) {
                $tokens[] = $m[1];
            }
        }
        fclose($fh);
    }

    return array_values(array_unique(array_filter($tokens, static fn($t) => $t !== '')));
}

/**
 * Collect EVERY server-side-trusted CSRF token the page legitimately echoes,
 * de-duplicated and non-empty:
 *   - $GLOBALS['var']['csrf_token'] (front-controller / tests),
 *   - $_SESSION['csrf_token']       (when a session is active and carries it),
 *   - the var.ini value(s) from ur_var_ini_candidates() (both known paths).
 *
 * WHY MATCH-ANY (the live root cause of the 403s): on the real box a urlencoded
 * POST carrying the CORRECT token (byte-identical to the page's window.csrf_token
 * = var.ini's csrf_token) still 403'd, because the old "pick the FIRST non-empty
 * source and compare only that" logic returned a STALE/different
 * $GLOBALS['var']['csrf_token'] (or $_SESSION value) and short-circuited BEFORE
 * the correct var.ini fallback ever ran - so the page's canonical token never got
 * a chance to match. Comparing the supplied token against ALL candidates fixes
 * this: every candidate is a server-trusted token the page can legitimately
 * present, so accepting a match against the canonical var.ini token (even when a
 * stale $var/$_SESSION value also exists) is correct and secure.
 *
 * @return array<int,string> non-empty, de-duplicated candidate tokens
 */
function ur_csrf_token_candidates(): array
{
    $candidates = [];

    if (isset($GLOBALS['var']) && is_array($GLOBALS['var']) && isset($GLOBALS['var']['csrf_token'])) {
        $candidates[] = (string) $GLOBALS['var']['csrf_token'];
    }
    if (isset($_SESSION['csrf_token'])) {
        $candidates[] = (string) $_SESSION['csrf_token'];
    }
    // var.ini value(s) - read directly so a STANDALONE POST (where $var is never
    // populated) still has the real token to match against. ur_csrf_tokens_from_ini
    // recovers the token even when parse_ini_file() bails on an unrelated malformed
    // line elsewhere in the (large, machine-written) file - see its docblock.
    foreach (ur_var_ini_candidates() as $path) {
        if (!is_string($path)) {
            continue;
        }
        foreach (ur_csrf_tokens_from_ini($path) as $tok) {
            $candidates[] = $tok;
        }
    }

    // Drop empties, then de-duplicate (a stable, non-empty candidate set).
    $candidates = array_values(array_filter($candidates, static fn($t) => $t !== ''));
    return array_values(array_unique($candidates));
}

/**
 * The csrf_token the request SUPPLIED, recovered robustly from whichever place
 * survives into our handler.
 *
 * WHY THIS IS NEEDED (the live root cause of the 403s, finally pinned down): the
 * webGui front controller that fronts a direct POST to handler.php VALIDATES and
 * then STRIPS `csrf_token` out of $_POST before our code runs - so by the time
 * ur_check_csrf reads $_POST['csrf_token'] it is EMPTY, and every state-changing
 * POST 403s, even though the page sent the correct token (confirmed live: a POST
 * routed correctly on $_POST['action'] yet reported postTokenLen=0 for
 * csrf_token, and $GLOBALS['var']['csrf_token'] WAS populated). The raw request
 * body still carries the field, so we recover it from there.
 *
 * Order: $_POST -> $_REQUEST -> $_GET -> the raw urlencoded body (php://input).
 * $rawInput is injectable for tests (php://input is not writable under CLI).
 */
function ur_supplied_csrf_token(?string $rawInput = null): string
{
    foreach ([$_POST, $_REQUEST, $_GET] as $src) {
        if (isset($src['csrf_token']) && is_string($src['csrf_token']) && $src['csrf_token'] !== '') {
            return (string) $src['csrf_token'];
        }
    }
    // Fallback: pull ONLY the csrf_token field out of the raw urlencoded body
    // (the front controller can strip it from $_POST but does not rewrite the raw
    // input stream). We extract just that one parameter with a targeted regex
    // rather than parse_str()-ing the whole payload, so an oversized body can't
    // blow up memory/CPU. The `(?:^|&)` anchor avoids matching a key that merely
    // ends in "csrf_token".
    if ($rawInput === null) {
        $rawInput = @file_get_contents('php://input');
    }
    if (is_string($rawInput) && $rawInput !== ''
        && preg_match('/(?:^|&)csrf_token=([^&]*)/', $rawInput, $m)) {
        $val = urldecode($m[1]);
        if ($val !== '') {
            return $val;
        }
    }
    return '';
}

/**
 * Verify the request carries a CSRF token that matches ANY server-side-trusted
 * candidate. Returns true on success; on failure it has already emitted a 403
 * and (outside tests) exited.
 *
 * The supplied token must be non-empty AND timing-safely equal (hash_equals) to
 * at least one candidate. We iterate ALL candidates (never early-comparing a
 * single source) so a stale $GLOBALS['var']/$_SESSION token can't mask the
 * correct var.ini token. With no candidates, or an empty supplied token, we 403.
 *
 * $rawInput is forwarded to ur_supplied_csrf_token() so tests can exercise the
 * raw-body recovery path (php://input is not writable under CLI); in production
 * it stays null and the token is read from php://input.
 */
function ur_check_csrf(?string $rawInput = null): bool
{
    // Validate the cheap precondition (a token was supplied) BEFORE collecting
    // candidates: ur_csrf_token_candidates() reads var.ini from disk, so we skip
    // that work for an obviously-invalid request that carries no token. Behaviour
    // is unchanged - an empty supplied token still 403s. ur_supplied_csrf_token
    // recovers the token from the raw body when the front controller stripped it
    // out of $_POST (see its docblock).
    $supplied = ur_supplied_csrf_token($rawInput);
    if ($supplied === '') {
        sendError('Invalid or missing CSRF token.', 403);
        return false;
    }

    $candidates = ur_csrf_token_candidates();

    $matched = false;
    foreach ($candidates as $candidate) {
        // hash_equals is timing-safe; iterate all candidates (don't short-circuit
        // on the first source) so the canonical token always gets a chance.
        if (hash_equals($candidate, $supplied)) {
            $matched = true;
        }
    }

    // No candidates (== []) leaves $matched false -> 403, exactly as before.
    if (!$matched) {
        sendError('Invalid or missing CSRF token.', 403);
        return false;
    }
    return true;
}

/**
 * Release the PHP session lock for the rest of the request, if one is held.
 *
 * WHY THIS EXISTS (the production wedge):
 *   handler.php never starts a session itself, but the webGui serves it through
 *   an authenticated front controller that runs as php-fpm's auto_prepend_file
 *   and calls session_start() on EVERY request. PHP's default (files) session
 *   handler then holds an EXCLUSIVE flock on the session file for the WHOLE
 *   request lifetime. Because every request from the same browser carries the
 *   same PHPSESSID, a single long-running POST (e.g. a 30s host-key discovery
 *   against an unreachable host) holds that lock for its entire duration, and
 *   every subsequent same-session request - all the other CSRF-protected POSTs:
 *   saveCredentials, generateKey, ... - BLOCKS inside session_start() waiting on
 *   it. The result is exactly the observed symptom: one slow/stuck discover and
 *   suddenly every POST hangs.
 *
 *   Closing the session as early as possible (we hold nothing in it - the CSRF
 *   check reads its candidate tokens via ur_csrf_token_candidates(), which has
 *   already run by the time we get here) WRITES IT BACK AND RELEASES THE LOCK, so
 *   a long request can never serialise other requests behind its session.
 *
 * Safe to call unconditionally: it is a no-op when no session is active (e.g.
 * the unit tests, or a webGui build that doesn't prepend a session). We guard on
 * session_status() so we never emit a "no active session" warning under
 * failOnWarning, and never *start* a session just to close it.
 */
function ur_release_session_lock(): void
{
    if (function_exists('session_status')
        && session_status() === PHP_SESSION_ACTIVE
        && function_exists('session_write_close')) {
        @session_write_close();
    }
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

    // Load credentials once so Job::validate can confirm an SSH job's selected
    // Connection actually exists (server-side source of truth). A read error
    // here is non-fatal: we fall back to the cheap "connection must be non-empty"
    // rule (passing null) rather than failing the whole save.
    $credsForValidation = null;
    try {
        $credsForValidation = Credentials::load();
    } catch (Throwable $e) {
        $credsForValidation = null;
    }

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

        $result = Job::validate($job, $credsForValidation);
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

    // Re-sync the live crontab to the just-saved jobs (per-job schedules /
    // enabled state). Best-effort: the save already succeeded, so a cron-sync
    // failure becomes a non-fatal warning rather than a failed save. Pass the
    // in-hand config so apply() schedules exactly what we persisted.
    $cron = ur_resync_cron($config);
    if (empty($cron['ok'])) {
        $allWarnings[] = 'Jobs were saved, but updating the schedule failed: '
            . (string) ($cron['error'] ?? ('update_cron exit ' . ($cron['updateCronCode'] ?? -1)))
            . '. Schedules will be re-applied on the next array start.';
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
        // New connections default to KEYFILE (the common Unraid case); an
        // explicit submitted value always wins.
        'authMethod'     => isset($raw['authMethod'])     ? (string) $raw['authMethod']     : 'KEYFILE',
        'keyId'          => isset($raw['keyId'])          ? (string) $raw['keyId']          : '',
        'keyFilePath'    => isset($raw['keyFilePath'])    ? (string) $raw['keyFilePath']    : '',
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

    // Clear the auth fields that don't belong to the selected method, so a
    // connection never carries stale credentials from a previous auth choice
    // (e.g. a leftover keyId after switching to KEYFILE). The active method's
    // field is kept as submitted; validation checks it.
    if ($conn['authMethod'] !== 'KEY') {
        $conn['keyId'] = '';
    }
    if ($conn['authMethod'] !== 'KEYFILE') {
        // mergeConnection() backfills an empty keyFilePath to the default; for a
        // non-KEYFILE connection we store it empty so the on-disk record reflects
        // that no key file is in use.
        $conn['keyFilePath'] = '';
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

    // IMPORTANT: saveCredentials is UPDATE-AND-APPEND ONLY. It never deletes a
    // key or connection by omission - deletion goes EXCLUSIVELY through the
    // deleteKey / deleteConnection actions, which enforce referential integrity
    // (usedBy block on keys; disable dependent jobs on connections). A submitted
    // row with a known id UPDATES that entry; a row with no id and some content
    // APPENDS a new entry; any existing entry NOT present in the submission is
    // PRESERVED. This stops a cleared field (or a malformed/partial POST) from
    // silently orphaning jobs or leaving connections pointing at a missing key.

    // --- keys (rename existing by id; preserve omitted; append new) ---
    if ($hasKeys) {
        $rawKeys = $keysSubmitted ? array_values($_POST['keys']) : [];

        // Index the submitted rows by their (existing) id.
        $submittedById = [];
        $appended      = [];
        foreach ($rawKeys as $rawKey) {
            if (!is_array($rawKey)) {
                continue;
            }
            $key = ur_normalize_key_for_save($rawKey, $creds);
            if ($key['id'] !== '') {
                $submittedById[$key['id']] = $key;     // an edit of an existing key
            } elseif ($key['name'] !== '') {
                $appended[] = $key;                     // a brand-new key row
            }
            // (no id AND no name -> empty template row, ignored)
        }

        // Start from the existing keys, applying any submitted rename; keep keys
        // that weren't submitted untouched (no delete-by-omission).
        $resultKeys = [];
        foreach ($creds['keys'] as $existing) {
            $eid = (string) ($existing['id'] ?? '');
            if ($eid !== '' && isset($submittedById[$eid])) {
                // Preserve secret material (ur_normalize_key_for_save already
                // copied it by id) and apply the new name.
                $resultKeys[] = $submittedById[$eid];
            } else {
                $resultKeys[] = Credentials::mergeKey(is_array($existing) ? $existing : []);
            }
        }
        // Append genuinely-new key rows (rare via this form; keys are normally
        // created through generate/import).
        foreach ($appended as $key) {
            if ($key['id'] === '') {
                $key['id'] = Credentials::generateId(
                    $key['name'],
                    'k-',
                    array_column($resultKeys, 'id')
                );
            }
            $resultKeys[] = $key;
        }

        // Validate names (uniqueness across the whole resulting set). Each key
        // is checked against the names already seen, so a key is never compared
        // against itself and a genuine duplicate is reported once.
        $names = [];
        foreach ($resultKeys as $key) {
            $res = Credentials::validateKey($key, $names);
            foreach ($res['errors'] as $e) {
                $errors[] = 'Key "' . ($key['name'] !== '' ? $key['name'] : $key['id']) . '": ' . $e;
            }
            $names[] = $key['name'];
        }

        $creds['keys'] = $resultKeys;
    }

    // --- connections (update existing by id; preserve omitted; append new) ---
    if ($hasConns) {
        $rawConns = $connsSubmitted ? array_values($_POST['connections']) : [];

        $submittedById = [];
        $appended      = [];
        foreach ($rawConns as $rawConn) {
            if (!is_array($rawConn)) {
                continue;
            }
            $conn = ur_normalize_connection_for_save($rawConn, $creds);
            $hasContent = !($conn['name'] === '' && $conn['host'] === '' && $conn['username'] === '');

            if ($conn['id'] !== '') {
                // An edit of an existing connection. Even if all visible fields
                // were cleared we keep it as an (invalid) edit so validation
                // surfaces the problem - we never silently drop a row that
                // carries an id (that path is deleteConnection's job).
                $submittedById[$conn['id']] = $conn;
            } elseif ($hasContent) {
                $appended[] = $conn;                    // a brand-new connection
            }
            // (no id AND empty -> blank template card, ignored)
        }

        // Apply edits onto existing connections; preserve omitted ones.
        $resultConns = [];
        foreach ($creds['connections'] as $existing) {
            $eid = (string) ($existing['id'] ?? '');
            if ($eid !== '' && isset($submittedById[$eid])) {
                $resultConns[] = $submittedById[$eid];
            } else {
                $resultConns[] = Credentials::mergeConnection(is_array($existing) ? $existing : []);
            }
        }
        // Append new connections, assigning unique ids.
        foreach ($appended as $conn) {
            $conn['id'] = Credentials::generateId(
                $conn['name'],
                'c-',
                array_column($resultConns, 'id')
            );
            $resultConns[] = $conn;
        }

        // Validate the whole resulting set (KEY connections' keyId is checked
        // against the keys we're about to save).
        foreach ($resultConns as $i => $conn) {
            $res   = Credentials::validateConnection($conn, $creds);
            $label = $conn['name'] !== '' ? $conn['name'] : ($conn['id'] !== '' ? $conn['id'] : ('#' . ($i + 1)));
            foreach ($res['errors'] as $e) {
                $errors[] = 'Connection "' . $label . '": ' . $e;
            }
        }

        $creds['connections'] = $resultConns;
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

    // If any saved connection uses PASSWORD auth but sshpass isn't available,
    // warn now (not just in the UI / testConnection) so the user knows password
    // auth won't work until sshpass is installed - the save still succeeds.
    $warnings = [];
    $hasPasswordConn = false;
    foreach ($creds['connections'] as $c) {
        if (($c['authMethod'] ?? '') === 'PASSWORD') {
            $hasPasswordConn = true;
            break;
        }
    }
    if ($hasPasswordConn && !Ssh::sshpassAvailable()) {
        $warnings[] = Ssh::sshpassMissingMessage();
    }

    sendResponse([
        'ok'          => true,
        'message'     => 'Credentials saved.',
        'warnings'    => $warnings,
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
    $type = isset($_POST['type']) ? strtolower(trim((string) $_POST['type'])) : 'ed25519';
    if ($name === '') {
        sendError('A key name is required.', 422);
        return;
    }
    // An unsupported key type is CLIENT input -> 422, not a server error.
    if (!in_array($type, KeyTools::KEY_TYPES, true)) {
        sendError('Unsupported key type: ' . $type . '. Choose ed25519 or rsa.', 422);
        return;
    }
    // Uniqueness across existing keys.
    $existingNames = array_map(static fn($k) => strtolower(trim((string) ($k['name'] ?? ''))), $creds['keys']);
    if (in_array(strtolower($name), $existingNames, true)) {
        sendError('A key named "' . $name . '" already exists; names must be unique.', 422);
        return;
    }

    $gen = KeyTools::generate($type, $name);
    if (empty($gen['ok'])) {
        // The type is already validated above, so any failure here is a genuine
        // runtime error (ssh-keygen missing / failed) -> 500.
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
    $existingNames = array_map(static fn($k) => strtolower(trim((string) ($k['name'] ?? ''))), $creds['keys']);
    if (in_array(strtolower($name), $existingNames, true)) {
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

    // If we disabled any jobs, their schedules must drop out of the live
    // crontab. Re-sync from the mutated config (best-effort; non-fatal).
    $cronWarning = '';
    if (!empty($disabled)) {
        $cron = ur_resync_cron($config);
        if (empty($cron['ok'])) {
            $cronWarning = ' Note: updating the schedule failed; it will be re-applied on the next array start.';
        }
    }

    $msg = 'Connection deleted.';
    if (!empty($disabled)) {
        $msg .= ' Disabled ' . count($disabled) . ' dependent job(s): ' . implode(', ', $disabled) . '.';
    }
    $msg .= $cronWarning;
    sendResponse([
        'ok'             => true,
        'message'        => $msg,
        'disabledJobs'   => $disabled,
    ], 200);
}

/**
 * The KeyTools class the handler delegates host-key discovery to. Normally
 * KeyTools; overridable via the UR_KEYTOOLS_CLASS constant so a test can inject
 * a double that returns a programmed result (e.g. a wall-clock timeout) without
 * a live ssh-keyscan binary. The override must be a KeyTools subclass.
 */
function ur_keytools_class(): string
{
    if (defined('UR_KEYTOOLS_CLASS') && is_string(UR_KEYTOOLS_CLASS) && is_subclass_of(UR_KEYTOOLS_CLASS, KeyTools::class)) {
        return UR_KEYTOOLS_CLASS;
    }
    return KeyTools::class;
}

/**
 * POST discoverHostKey: ssh-keyscan a host:port and return the host key text
 * for the connection form to pin. Does NOT persist anything.
 *
 * Time-bounded: KeyTools::discoverHostKey caps the work at ~30s (ssh-keyscan -T
 * plus a PHP wall-clock deadline that kills a hanging child). A wall-clock
 * timeout is surfaced here as a 504 with a clear message; a "host unreachable /
 * no key" validation failure stays a 422. The body is always clean JSON.
 */
function ur_action_discover_host_key(): void
{
    $host    = isset($_POST['host']) ? trim((string) $_POST['host']) : '';
    $port    = isset($_POST['port']) ? (int) $_POST['port'] : 22;
    $timeout = isset($_POST['timeout']) ? (int) $_POST['timeout'] : 10;

    $cls = ur_keytools_class();

    // Defence-in-depth: even though KeyTools enforces its own ~30s wall-clock
    // deadline (and kills a hanging ssh-keyscan child), cap the PHP execution
    // time of THIS request too, so a wedged request can never live forever and
    // hold resources. The cap is the discover cap plus a grace > the SIGTERM ->
    // SIGKILL teardown window, so a clean timeout still returns its 504 before
    // this fires. Skipped in tests (no live request to bound) and a no-op if the
    // function is disabled. set_time_limit also RESETS the timer, so any time
    // already spent in the request before this point doesn't count against it.
    if (!defined('UR_HANDLER_TESTING') && function_exists('set_time_limit')) {
        @set_time_limit($cls::discoverTimeoutMax() + 10);
    }
    $res = $cls::discoverHostKey($host, $port, $timeout);
    if (empty($res['ok'])) {
        // A wall-clock timeout is distinct from a "host unreachable / no key"
        // validation failure: surface it as a 504 (Gateway Timeout) so the UI
        // can show the elapsed-time message. The body is still clean JSON.
        $code = !empty($res['timedOut']) ? 504 : 422;
        sendError((string) ($res['error'] ?? 'Host key discovery failed.'), $code);
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

// =====================================================================
// Phase 4: execution actions (runJob / dryRunJob / abortJob)
// =====================================================================

/**
 * Resolve the absolute path to the runner CLI. On a live box the plugin lives
 * at /usr/local/emhttp/plugins/unraid.rsync; handler.php is in include/, so the
 * runner is ../scripts/runner.php from here. Overridable via UR_RUNNER_PATH for
 * tests / alternate installs.
 */
function ur_runner_script_path(): string
{
    if (defined('UR_RUNNER_PATH')) {
        return (string) UR_RUNNER_PATH;
    }
    return dirname(__DIR__) . '/scripts/runner.php';
}

/**
 * Locate a CLI `php` interpreter to launch the detached runner with.
 *
 * CRITICAL (the live "Run started but nothing happens" bug): handler.php runs
 * under php-fpm, where PHP_BINARY is the php-fpm SERVER executable, NOT a CLI
 * interpreter. `php-fpm scripts/runner.php --job=...` does NOT execute the
 * script (php-fpm ignores a script argument and tries to run as a FastCGI
 * server), so the backgrounded launch returns exit 0 while the runner never
 * actually runs - no run log, no state, the job silently does nothing. We
 * therefore only trust PHP_BINARY when we are genuinely under the CLI SAPI;
 * otherwise we resolve a real CLI php (Unraid ships /usr/bin/php), falling back
 * to a bare "php" on PATH.
 */
function ur_php_binary(): string
{
    if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)) {
        return PHP_BINARY;
    }
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/bin/php'] as $cand) {
        if (is_executable($cand)) {
            return $cand;
        }
    }
    return 'php';
}

/**
 * Launch the runner DETACHED for a job, optionally dry-run. The detached launch
 * MUST redirect stdout+stderr to /dev/null and background with `&` or the AJAX
 * request would block until the (potentially very long) rsync finishes.
 *
 * We deliberately use a shell HERE - the one place a shell command line is
 * built in the request path - but every interpolated value is escapeshellarg-
 * quoted: the php binary, the runner script path, and the job id. The job id is
 * additionally constrained to a slug shape before it ever reaches this point.
 * Nothing user-controlled is passed unquoted.
 *
 * Returns true when the launch shell reported success (exit 0). The run itself
 * is detached, so a true result means "the background launch was dispatched",
 * not that rsync succeeded - but a FALSE result (missing runner script, shell
 * failure) is surfaced to the caller so the UI doesn't claim "Run started" when
 * nothing actually detached.
 */
function ur_launch_runner(string $jobId, bool $dryRun): bool
{
    $script = ur_runner_script_path();
    // Don't claim a launch when the runner script isn't even present.
    if (!is_file($script)) {
        return false;
    }
    $php = ur_php_binary();

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
        . ' --job=' . escapeshellarg($jobId);
    if ($dryRun) {
        $cmd .= ' --dry-run';
    }
    // A handler-initiated run is always a MANUAL trigger (the scheduled path is
    // the cron line, which passes --trigger=schedule). Fixed literal -> no
    // escaping needed; placed AFTER --job so RunState::cmdlineMatchesJob still
    // matches the --job=<id> token.
    $cmd .= ' --trigger=manual';
    // MANDATORY: redirect both streams to /dev/null and background, else the
    // request hangs waiting on the child. setsid (when available) detaches the
    // runner into its own session/process group so abort can SIGTERM the whole
    // group (reaping the rsync child); when setsid is absent the launch still
    // works - abort then falls back to signalling the bare runner pid and the
    // abort flag still stops the run between pairs - so it is best-effort, not
    // required for correctness.
    $setsid = '';
    foreach (['/usr/bin/setsid', '/bin/setsid'] as $cand) {
        if (is_executable($cand)) {
            $setsid = $cand . ' ';
            break;
        }
    }
    $full = $setsid . $cmd . ' >/dev/null 2>&1 &';

    // exec returns immediately because the command backgrounds itself; capture
    // the launching shell's exit so a failed dispatch is reported as such.
    $output = [];
    $code   = 0;
    @exec($full, $output, $code);
    return $code === 0;
}

/**
 * POST runJob / dryRunJob: validate the job id, refuse if already running, then
 * launch the runner detached. Returns a fire-and-confirm response (the live
 * outcome is observed via the run log / state in Phase 6).
 */
function ur_action_run_job(bool $dryRun): void
{
    // Route the POST id through the same confinement gate the GET pollers use, so
    // a malformed/tampered id (path separators, control bytes, etc.) is rejected
    // early and symmetrically. The exact-match-against-config-id below is still
    // the real authority — this just normalises + early-rejects junk first.
    $jobId = ur_safe_job_id(isset($_POST['id']) ? (string) $_POST['id'] : '');
    if ($jobId === '') {
        sendError('A job id is required.', 422);
        return;
    }

    // Resolve the job so we never launch the runner for an id that isn't ours
    // (and so a disabled/missing job is reported clearly).
    try {
        $config = Config::load();
    } catch (Throwable $e) {
        sendError('Configuration could not be read: ' . $e->getMessage(), 409);
        return;
    }
    $found = null;
    foreach (($config['jobs'] ?? []) as $job) {
        if (is_array($job) && (string) ($job['id'] ?? '') === $jobId) {
            $found = $job;
            break;
        }
    }
    if ($found === null) {
        sendError('Job not found.', 404);
        return;
    }

    // Concurrency guard (PID-reuse-safe).
    if (RunState::isRunning($jobId)) {
        sendError('This job is already running.', 409, ['running' => true]);
        return;
    }

    if (!ur_launch_runner($jobId, $dryRun)) {
        // The detached launch failed to dispatch (missing runner script / shell
        // failure). Report it rather than misleading the UI into "Run started".
        sendError('Failed to launch the runner process.', 500, ['running' => false]);
        return;
    }

    sendResponse([
        'ok'      => true,
        'message' => $dryRun ? 'Dry-run started.' : 'Run started.',
        'jobId'   => $jobId,
        'dryRun'  => $dryRun,
        'running' => true,
    ], 200);
}

/**
 * POST abortJob: request an abort for a running job. Touches the abort flag AND
 * SIGTERMs the captured runner pid + its process group (so the rsync child is
 * reaped, not just the PHP parent). The runner also polls the flag between
 * pairs, so even if signalling races, the next pair boundary stops the run.
 */
function ur_action_abort_job(): void
{
    // Route the POST id through the same confinement gate the GET pollers use
    // (symmetry + early rejection of a malformed/tampered id). The known-id and
    // run-state checks below remain the authority for whether an abort proceeds.
    $jobId = ur_safe_job_id(isset($_POST['id']) ? (string) $_POST['id'] : '');
    if ($jobId === '') {
        sendError('A job id is required.', 422);
        return;
    }

    // Refuse an unknown id BEFORE touching the filesystem: otherwise a
    // CSRF-authenticated request could create an arbitrary
    // <runtime>/state/<id>.abort file (and leave a stale flag that poisons a
    // future run if the id is later reused). We accept the abort only when the
    // id is a configured job OR a run state file already exists for it (an
    // in-flight run whose job row may have just been edited away).
    $known = false;
    try {
        $config = Config::load();
        foreach (($config['jobs'] ?? []) as $job) {
            if (is_array($job) && (string) ($job['id'] ?? '') === $jobId) {
                $known = true;
                break;
            }
        }
    } catch (Throwable $e) {
        // Config unreadable: fall back to the state-file check below.
    }
    if (!$known && RunState::read($jobId) === null) {
        sendError('Job not found.', 404);
        return;
    }

    // Set the flag first - the runner polls it between pairs, so this alone
    // guarantees a stop even if the pid is gone / signalling fails.
    RunState::requestAbort($jobId);

    // Only signal a pid we have VERIFIED is this job's live runner. isRunning()
    // is PID-reuse-safe (running flag + posix_kill + cmdline match), so this
    // guards against SIGTERMing an unrelated process whose pid was reused after
    // a stale state file. If the runner isn't (or is no longer) running, the
    // abort flag alone is sufficient and we skip signalling.
    $signalled = false;
    if (RunState::isRunning($jobId) && function_exists('posix_kill')) {
        $state = RunState::read($jobId);
        $pid   = ($state !== null) ? (int) $state['pid'] : 0;
        if ($pid > 0) {
            // SIGTERM the whole process group (negative pid) so the rsync CHILD
            // is killed too - the runner was launched via setsid into its own
            // group, whose pgid equals the runner pid. Fall back to the bare pid
            // if the group signal isn't permitted.
            $sig = defined('SIGTERM') ? SIGTERM : 15;
            if (@posix_kill(-$pid, $sig)) {
                $signalled = true;
            } elseif (@posix_kill($pid, $sig)) {
                $signalled = true;
            }
        }
    }

    sendResponse([
        'ok'        => true,
        'message'   => 'Abort requested.',
        'jobId'     => $jobId,
        'signalled' => $signalled,
    ], 200);
}

// =====================================================================
// Phase 6: read-only GET pollers (status / log tails / run list)
//
// These are GET, no CSRF (they are side-effect-free reads behind the webGui's
// own authenticated front controller). Every job id and run-file id is
// whitelisted against a safe pattern, and every log read is confined to the
// job's own log dir and BOUNDED in size. Log text returned to the browser is
// ALREADY HTML-escaped by Logger::tail() - the UI injects it verbatim and must
// NEVER re-escape or build raw innerHTML from unescaped log bytes.
// =====================================================================

/** Max bytes a GET log tail returns to the browser (bounds the response). */
const UR_GET_LOG_TAIL_BYTES = 128 * 1024; // 128 KiB

/** Default number of runs listed by listRuns / the run selector. */
const UR_LIST_RUNS_DEFAULT = 10;

/**
 * Whitelist a job id to the slug shape the rest of the plugin produces
 * ("j-" + lowercase/digits/hyphen, by construction) plus a small tolerance for
 * any legacy id. We accept [A-Za-z0-9._-] only and a sane length; ANYTHING with
 * a path separator, "..", NUL, etc. is rejected. This is the gate before a job
 * id is ever used to build a state/log path. Returns the id on success, '' on
 * rejection.
 */
function ur_safe_job_id(string $id): string
{
    // Reject any embedded NUL/control byte BEFORE trimming: trim() strips NULs
    // and whitespace, which would otherwise silently sanitise a "j-music\0..."
    // id into a valid one rather than rejecting the tampered input.
    if (preg_match('/[\x00-\x1f\x7f]/', $id)) {
        return '';
    }
    $id = trim($id);
    if ($id === '' || strlen($id) > 128) {
        return '';
    }
    // The \D modifier anchors $ at the true string end (not before a trailing
    // newline), so a "valid prefix + newline" can never sneak through.
    if (!preg_match('/^[A-Za-z0-9._-]+$/D', $id)) {
        return '';
    }
    // Reject a pure-dots id ("." / "..") which is a traversal even within the
    // character class above.
    if (preg_match('/^\.+$/', $id)) {
        return '';
    }
    return $id;
}

/**
 * Derive a job's display state from its live running flag + last-run summary.
 * RUNNING (live) OVERRIDES the summary; otherwise the summary's state is used;
 * with no summary the job is PENDING (never run). This is the single source of
 * truth for the badge + the Status tab.
 *
 * @param array<string,mixed>|null $summary the readSummary() result (or null)
 * @return string one of the Rsync STATE_* vocabulary, RUNNING, or PENDING
 */
function ur_derive_state(bool $running, ?array $summary): string
{
    if ($running) {
        return 'RUNNING';
    }
    if (is_array($summary) && !empty($summary['state'])) {
        return (string) $summary['state'];
    }
    return Rsync::STATE_PENDING; // 'PENDING' - no summary == never run
}

/**
 * Normalise a last-run summary into the canonical lastRun JSON shape, or null
 * when there is no (valid) summary. Defensive against a partial/corrupt file:
 * any missing field falls back to a safe default.
 *
 * @param array<string,mixed>|null $summary
 * @return array{state:string,startedAt:string,finishedAt:string,exitCode:int,durationSec:int,dryRun:bool}|null
 */
function ur_last_run_shape(?array $summary): ?array
{
    if (!is_array($summary)) {
        return null;
    }
    return [
        'state'       => (string) ($summary['state'] ?? ''),
        'startedAt'   => (string) ($summary['startedAt'] ?? ''),
        'finishedAt'  => (string) ($summary['finishedAt'] ?? ''),
        'exitCode'    => (int) ($summary['exitCode'] ?? 0),
        'durationSec' => (int) ($summary['durationSec'] ?? 0),
        'dryRun'      => !empty($summary['dryRun']),
    ];
}

/**
 * GET getStatus: a per-job status map for ALL configured jobs. Each entry:
 *   { name, enabled, running, state, lastRun: {...}|null, nextRun: epoch|null }
 * running  <- RunState::isRunning (PID-reuse-safe, self-heals stale state)
 * lastRun  <- Runner::readSummary (the /boot durable summary)
 * state    <- ur_derive_state (RUNNING overrides summary; PENDING when none)
 * nextRun  <- Cron::nextRun for an enabled job with a valid schedule, else null
 * enabled  <- the job's enabled flag, so the UI can render "disabled" for the
 *             Next-run cell (distinct from an enabled job with no computable next)
 *
 * Side-effect-free (isRunning may CLEAR a provably-stale state file, which is a
 * self-heal, not a state change driven by this request).
 */
function ur_action_get_status(): void
{
    try {
        $config = Config::load();
    } catch (Throwable $e) {
        // A read failure here is non-fatal for a poller: report it so the UI can
        // show a transient error without breaking, rather than 500-ing the poll.
        sendResponse(['ok' => false, 'error' => 'Configuration could not be read: ' . $e->getMessage(), 'jobs' => []], 200);
        return;
    }

    $now  = time();
    $jobs = (isset($config['jobs']) && is_array($config['jobs'])) ? $config['jobs'] : [];
    $out  = [];

    foreach ($jobs as $job) {
        if (!is_array($job)) {
            continue;
        }
        $id = ur_safe_job_id((string) ($job['id'] ?? ''));
        if ($id === '') {
            continue;
        }

        // Per-job resilience: a single job's runtime/state/schedule hiccup must
        // not 500 the WHOLE poller (which would blank the entire Jobs/Status UI).
        // Report that job with an ERROR state and keep going.
        try {
            $running = RunState::isRunning($id);
            $summary = Runner::readSummary($id);

            $nextRun = null;
            if (!empty($job['enabled'])) {
                $schedule = trim((string) ($job['schedule'] ?? ''));
                if ($schedule !== '') {
                    $next = Cron::nextRun($schedule, $now);
                    $nextRun = ($next === null) ? null : (int) $next;
                }
            }

            $out[$id] = [
                'name'    => (string) ($job['name'] ?? $id),
                'enabled' => !empty($job['enabled']),
                'running' => $running,
                'state'   => ur_derive_state($running, $summary),
                'lastRun' => ur_last_run_shape($summary),
                'nextRun' => $nextRun,
            ];
        } catch (Throwable $e) {
            // Log the detail server-side (webGui PHP log) only - never leak
            // exception text/paths to the browser by default.
            error_log('unraid.rsync getStatus job ' . $id . ': '
                . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $out[$id] = [
                'name'    => (string) ($job['name'] ?? $id),
                'enabled' => !empty($job['enabled']),
                'running' => false,
                // Use the existing badge vocabulary so the UI renders a known
                // state rather than falling through to the default badge.
                'state'   => Rsync::STATE_FAILED,
                'lastRun' => null,
                'nextRun' => null,
            ];
        }
    }

    sendResponse(['ok' => true, 'now' => $now, 'jobs' => $out], 200);
}

/**
 * GET getJobLog: the HTML-escaped tail of a single run log for a job, plus the
 * job's live running flag (so the poller knows whether to keep tailing).
 * Params: id (job id, required) + optional run (a run-file id from listRuns).
 * With no run id we serve the CURRENT/latest run log. The run id is whitelisted
 * and confined to the job's log dir (Logger::runLogPathById); a bad/traversing
 * id is a 400. The tail is bounded.
 */
function ur_action_get_job_log(): void
{
    $jobId = ur_safe_job_id(isset($_GET['id']) ? (string) $_GET['id'] : '');
    if ($jobId === '') {
        sendError('A valid job id is required.', 400);
        return;
    }

    $runId = isset($_GET['run']) ? (string) $_GET['run'] : '';
    if ($runId !== '') {
        $path = Logger::runLogPathById($jobId, $runId);
        if ($path === null) {
            sendError('Invalid run id.', 400);
            return;
        }
    } else {
        $path = Logger::latestRunLogPath($jobId);
    }

    // The `running` flag must describe whether the LOG BEING RETURNED is still
    // being written, not merely whether the job is running - otherwise selecting
    // an older run while a new one is in flight would mark the stale log "live"
    // and keep tailing it. The job is running AND the served log is the live one
    // only when the served run matches the current run's log (RunState.currentLog).
    $jobRunning = RunState::isRunning($jobId);
    $servedRun  = ($path !== '') ? Logger::runIdFromPath($path) : '';
    $running    = false;
    if ($jobRunning && $servedRun !== '') {
        $state      = RunState::read($jobId);
        $currentLog = ($state !== null) ? (string) ($state['currentLog'] ?? '') : '';
        // currentLog is an absolute path; compare on its basename (the run id).
        $running = ($currentLog !== '' && basename($currentLog) === $servedRun);
    }

    // tail() returns '' for a missing/empty file and is ALREADY HTML-escaped.
    $log = ($path !== '') ? Logger::tail($path, UR_GET_LOG_TAIL_BYTES) : '';

    sendResponse([
        'ok'      => true,
        'jobId'   => $jobId,
        'run'     => $servedRun,
        'log'     => $log,
        'running' => $running,
    ], 200);
}

/**
 * GET listRuns: the last N run logs for a job, newest first, as
 * [{ id, ts, state }] for the viewer's run selector. Bounded by a `limit`
 * param (default UR_LIST_RUNS_DEFAULT, capped at 200).
 */
function ur_action_list_runs(): void
{
    $jobId = ur_safe_job_id(isset($_GET['id']) ? (string) $_GET['id'] : '');
    if ($jobId === '') {
        sendError('A valid job id is required.', 400);
        return;
    }

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : UR_LIST_RUNS_DEFAULT;
    if ($limit <= 0) {
        $limit = UR_LIST_RUNS_DEFAULT;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $runs = Logger::listRuns($jobId, $limit);
    sendResponse(['ok' => true, 'jobId' => $jobId, 'runs' => $runs], 200);
}

/**
 * GET getPluginLog: the HTML-escaped tail of the rolling cross-job plugin.log.
 * Bounded. No id needed - there is one plugin log.
 */
function ur_action_get_plugin_log(): void
{
    $log = Logger::tail(Logger::pluginLogPath(), UR_GET_LOG_TAIL_BYTES);
    sendResponse(['ok' => true, 'log' => $log], 200);
}

/**
 * GET getRsyncStatus: report whether the rsync binary is present (it ships in
 * Unraid's base OS at /usr/bin/rsync, so it should always be) and, when present,
 * the first line of `rsync --version`. The plugin does NOT install rsync; this
 * is a defensive presence check surfaced on the Status tab. The version string
 * is plain text from the trusted local binary; the UI escapes it before display.
 */
function ur_action_get_rsync_status(): void
{
    $available = Rsync::rsyncAvailable();
    sendResponse([
        'ok'        => true,
        'available' => $available,
        'path'      => Rsync::rsyncPath(),
        'version'   => $available ? Rsync::rsyncVersionLine() : '',
        'message'   => $available ? '' : Rsync::rsyncMissingMessage(),
    ], 200);
}

/**
 * Front-controller dispatch. Skipped when included by the test harness
 * (UR_HANDLER_TESTING defined), which calls the individual functions directly.
 *
 * Wraps the routing in a Throwable guard so an unexpected exception from any
 * action becomes a JSON 500 envelope rather than an HTML fatal that the browser
 * would fail to parse as JSON (leaving the UI stuck). The shutdown handler
 * (ur_fatal_shutdown_handler) covers non-catchable fatals (parse/OOM) the same
 * way.
 */
function ur_handle_request(): void
{
    try {
        ur_dispatch();
    } catch (Throwable $e) {
        // Never let an exception escape as an HTML fatal: emit a JSON error so
        // the client always gets parseable JSON and can surface a clear message.
        // Log the detail server-side (webGui PHP log) but return a GENERIC
        // message to the browser - an unexpected exception's text can carry
        // internal paths/config that should not leak to any authenticated UI
        // user or the browser console.
        error_log('unraid.rsync handler: unhandled ' . get_class($e) . ': ' . $e->getMessage());
        sendError('An unexpected internal error occurred. Check the system log for details.', 500);
    }
}

/**
 * The actual action router. Always reaches a send*() helper (each sets a JSON
 * Content-Type + status code), so a well-formed request never falls through
 * without a JSON body.
 */
function ur_dispatch(): void
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
            // CSRF is verified (and the session's csrf_token, if any, has been
            // read) - release the webGui session lock so this POST can never
            // serialise other same-session requests behind it.
            ur_release_session_lock();
            ur_action_save_config();
            return;

        case 'saveCredentials':
        case 'generateKey':
        case 'importKey':
        case 'deleteKey':
        case 'deleteConnection':
        case 'discoverHostKey':
        case 'testConnection':
        case 'runJob':
        case 'dryRunJob':
        case 'abortJob':
            if ($method !== 'POST') {
                sendError($action . ' requires POST.', 405);
                return;
            }
            if (!ur_check_csrf()) {
                return; // ur_check_csrf already responded
            }
            // CSRF is verified (and the session's csrf_token, if any, has been
            // read) - release the webGui session lock NOW, before any potentially
            // slow action (notably discoverHostKey's up-to-30s ssh-keyscan). This
            // is what stops a slow/stuck request from blocking every other
            // same-session POST inside session_start(). See ur_release_session_lock.
            ur_release_session_lock();
            switch ($action) {
                case 'saveCredentials':   ur_action_save_credentials();   return;
                case 'generateKey':       ur_action_generate_key();       return;
                case 'importKey':         ur_action_import_key();          return;
                case 'deleteKey':         ur_action_delete_key();          return;
                case 'deleteConnection':  ur_action_delete_connection();   return;
                case 'discoverHostKey':   ur_action_discover_host_key();   return;
                case 'testConnection':    ur_action_test_connection();     return;
                case 'runJob':            ur_action_run_job(false);        return;
                case 'dryRunJob':         ur_action_run_job(true);         return;
                case 'abortJob':          ur_action_abort_job();           return;
            }
            return;

        // Read-only GET pollers (no CSRF; side-effect-free reads). They must be
        // GET so a poll never mutates state; reject a POST to one so the contract
        // is explicit.
        case 'getStatus':
        case 'getJobLog':
        case 'listRuns':
        case 'getPluginLog':
        case 'getRsyncStatus':
            if ($method !== 'GET') {
                sendError($action . ' requires GET.', 405);
                return;
            }
            switch ($action) {
                case 'getStatus':       ur_action_get_status();       return;
                case 'getJobLog':       ur_action_get_job_log();      return;
                case 'listRuns':        ur_action_list_runs();        return;
                case 'getPluginLog':    ur_action_get_plugin_log();   return;
                case 'getRsyncStatus':  ur_action_get_rsync_status(); return;
            }
            return;

        default:
            sendError('Unknown action.', 400);
            return;
    }
}

/**
 * Last-resort shutdown handler: if the request died on a NON-catchable fatal
 * (e.g. an out-of-memory or a call to an undefined function) AFTER we'd already
 * started, emit a JSON error envelope so the browser still receives parseable
 * JSON instead of a half-rendered HTML fatal. Only fires for genuine fatal
 * error types, and only when no JSON body has been sent yet (best-effort: we
 * can't know for certain output started, so we guard on headers_sent()).
 */
function ur_fatal_shutdown_handler(): void
{
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
    if (($err['type'] & $fatalTypes) === 0) {
        return;
    }
    if (headers_sent()) {
        // A (partial) body already went out; we can't cleanly replace it. Append
        // nothing - the client's bad-JSON path will surface an error with status.
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    http_response_code(500);
    echo json_encode(
        ['error' => 'The request failed with an internal error.'],
        JSON_UNESCAPED_SLASHES
    );
}

if (!defined('UR_HANDLER_TESTING')) {
    register_shutdown_function('ur_fatal_shutdown_handler');
    ur_handle_request();
}

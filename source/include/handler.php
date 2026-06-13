<?php
/**
 * handler.php - the single AJAX/REST endpoint for the Unraid Rsync plugin.
 *
 * Phase 2 implements one action:
 *   POST saveConfig  - validate the submitted jobs + global defaults via
 *                      Job.php and persist via Config::save. CSRF-protected
 *                      using the webGui csrf_token. Returns JSON.
 *
 * More actions (run, dry-run, abort, test-connection, keyscan, status/log
 * polling) are added in later phases.
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
 *   - The actual validate/normalise/persist logic lives in Job.php + Config.php
 *     (I/O-free, unit-tested); this file is the thin HTTP shell.
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Job.php';

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

        default:
            sendError('Unknown action.', 400);
            return;
    }
}

if (!defined('UR_HANDLER_TESTING')) {
    ur_handle_request();
}

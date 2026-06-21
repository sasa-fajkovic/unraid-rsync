<?php

declare(strict_types=1);

/**
 * Cron.php - per-job scheduling for the Unraid Rsync plugin (Phase 5).
 *
 * Two responsibilities, both deliberately seam-friendly so tests never touch the
 * real system:
 *
 *   1. Cron::apply() - regenerate the plugin's cron file from config.json and ask
 *      Unraid to rebuild the live crontab.
 *
 *      Unraid's /usr/local/sbin/update_cron concatenates the plugin's *.cron
 *      files into the system crontab with a NON-recursive, top-level glob:
 *
 *          for plugin in /var/log/plugins/*.plg; do
 *            cat /boot/config/plugins/${plugin%.*}/*.cron
 *          done
 *
 *      so a cron file MUST live DIRECTLY in /boot/config/plugins/unraid.rsync/
 *      (the dir basename equals the installed .plg basename) and match *.cron.
 *      A cron/ SUBDIRECTORY is never scanned. We therefore write ONE file -
 *      <UR_CONFIG_BASE>/unraid.rsync.cron - with one line per ENABLED job,
 *      rewritten atomically (temp + rename) on every relevant config change.
 *      A single regenerated file means deleting/disabling a job can never leave
 *      an orphaned per-job cron file behind.
 *
 *      Each line is exactly:
 *        <schedule> php /usr/local/emhttp/plugins/unraid.rsync/scripts/runner.php --job=<id> >/dev/null 2>&1
 *      The "runner.php" + "--job=<id>" tokens are load-bearing: RunState::isRunning
 *      matches a recorded pid's /proc cmdline against exactly those tokens.
 *
 *      After (re)writing the file we invoke update_cron via its ABSOLUTE path as
 *      an ARGV ARRAY (no shell string). With zero enabled jobs we remove the
 *      cron file (and still run update_cron) so a removed schedule clears from
 *      the live crontab.
 *
 *   2. Cron::nextRun($expr, $fromTs) - compute the next fire time for a standard
 *      5-field cron expression (minute hour day-of-month month day-of-week). Pure
 *      function, no I/O. Implements the standard vixie-cron day-of-month/
 *      day-of-week OR semantics: when BOTH dom and dow are restricted the job
 *      fires if EITHER matches; when only one is restricted only that one
 *      applies. Returns null for a malformed expression or if no match is found
 *      within a sane horizon.
 *
 * Injectable seams (set before calling apply()):
 *   - Cron::$updateCronRunner  fn(array $argv): int  - the update_cron exec
 *                              (default: proc_open, no shell). Tests set a stub.
 *   - Cron::$updateCronPath    absolute path to update_cron (default
 *                              /usr/local/sbin/update_cron).
 * The config base is UR_CONFIG_BASE (already overridden in tests); the runner
 * script path baked into the cron line is UR_CRON_RUNNER_PATH (overridable).
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Job.php';

// The installed plugin/dir basename. The cron file is <base>/<UR_PLUGIN_NAME>.cron
// so update_cron's top-level *.cron glob picks it up. Overridable for tests.
if (!defined('UR_PLUGIN_NAME')) {
    define('UR_PLUGIN_NAME', 'unraid.rsync');
}

// The absolute runner-script path baked into each cron line. On a live box the
// GUI tree is /usr/local/emhttp/plugins/<name>/; cron must use that absolute
// path because it runs with a minimal environment. Overridable for tests.
if (!defined('UR_CRON_RUNNER_PATH')) {
    define('UR_CRON_RUNNER_PATH', '/usr/local/emhttp/plugins/' . UR_PLUGIN_NAME . '/scripts/runner.php');
}

// The php interpreter baked into each cron line. crond runs with a minimal
// environment, so prefer an ABSOLUTE path over relying on its PATH; fall back to
// bare 'php' only if none of the usual CLI locations exist (Unraid ships
// /usr/bin/php). Overridable for tests (pinned so line assertions stay
// deterministic regardless of the test host's php location).
if (!defined('UR_CRON_PHP_PATH')) {
    define('UR_CRON_PHP_PATH', is_executable('/usr/bin/php') ? '/usr/bin/php'
        : (is_executable('/usr/local/bin/php') ? '/usr/local/bin/php'
        : (is_executable('/bin/php') ? '/bin/php' : 'php')));
}

class Cron
{
    /** Header line written at the top of the generated cron file. */
    const HEADER = '# Unraid Rsync';

    /**
     * A job id is only emitted into a cron command line when it is a single safe
     * token: ASCII letters, digits, dot, underscore, hyphen. Job::generateId()
     * always produces "j-<slug>" which satisfies this, but a hand-edited or
     * legacy config.json could carry an id with shell metacharacters - and the
     * cron line is ultimately executed by /bin/sh, so an id like
     * "j-a; rm -rf /" would be command injection. We refuse to emit such a line
     * rather than trust upstream validation (defence in depth).
     */
    const SAFE_ID_PATTERN = '/^[A-Za-z0-9._-]+$/';

    /**
     * Injectable update_cron runner: fn(array $argv): int (the process exit
     * code). null => the live runner (proc_open with the argv array, no shell).
     * Tests set this to a stub that records the argv instead of executing.
     *
     * @var callable|null
     */
    public static $updateCronRunner = null;

    /**
     * Overridable absolute path to update_cron. null => the documented default
     * /usr/local/sbin/update_cron. Kept as a static (not just a constant) so a
     * test can point it somewhere harmless and assert the value passed to the
     * runner.
     *
     * @var string|null
     */
    public static $updateCronPath = null;

    /** Resolve the absolute path to update_cron. */
    public static function updateCronPath(): string
    {
        if (self::$updateCronPath !== null && self::$updateCronPath !== '') {
            return self::$updateCronPath;
        }
        return '/usr/local/sbin/update_cron';
    }

    /**
     * Absolute path to the single generated cron file. Lives DIRECTLY in the
     * config base (not a cron/ subdir) so update_cron's top-level *.cron glob
     * finds it.
     */
    public static function cronFilePath(): string
    {
        return rtrim(UR_CONFIG_BASE, '/') . '/' . UR_PLUGIN_NAME . '.cron';
    }

    /**
     * Build the cron file CONTENT from a config array: a header comment plus one
     * line per ENABLED job. A job is skipped (no line emitted) when it is
     * disabled, or its id is missing / not a single safe token
     * (SAFE_ID_PATTERN), or its schedule is missing / not a well-formed 5-field
     * cron expression.
     *
     * This is defence in depth: the cron file is concatenated into the system
     * crontab and each line is run by /bin/sh, so an id with shell
     * metacharacters would be command injection and a malformed schedule would
     * shift the command tokens. We do not trust that upstream validation already
     * cleaned the stored config (it could be hand-edited or from an older build),
     * so we re-check here and refuse to emit an unsafe line.
     *
     * The exact per-job line:
     *   <schedule> php <UR_CRON_RUNNER_PATH> --job=<id> >/dev/null 2>&1
     *
     * @param array<string,mixed> $config a loaded/merged config structure
     * @return string the full file content (always ends with a trailing newline)
     */
    public static function buildContent(array $config): string
    {
        $lines  = [self::HEADER];
        $runner = UR_CRON_RUNNER_PATH;

        $jobs = (isset($config['jobs']) && is_array($config['jobs'])) ? $config['jobs'] : [];
        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }
            if (empty($job['enabled'])) {
                continue;
            }
            // A manual-only job is never scheduled (it runs only on demand), so it
            // contributes no cron line.
            if (!empty($job['manualOnly'])) {
                continue;
            }
            $id       = trim((string) ($job['id'] ?? ''));
            $schedule = trim((string) ($job['schedule'] ?? ''));
            if ($id === '' || $schedule === '') {
                continue;
            }
            // The id becomes part of a shell-executed cron line: refuse anything
            // that isn't a single safe token (prevents command injection from a
            // crafted/legacy id like "j-a; rm -rf /"). A pure-dots id ("." / "..")
            // passes SAFE_ID_PATTERN's char class but is a traversal segment, so
            // reject it too (mirrors ur_safe_job_id and the safeId helpers).
            if (!preg_match(self::SAFE_ID_PATTERN, $id) || preg_match('/^\.+$/', $id)) {
                continue;
            }
            // A malformed schedule would shift the command tokens (or be a junk
            // crontab line); only emit well-formed 5-field expressions.
            if (!Job::isValidCron($schedule)) {
                continue;
            }
            // Collapse any internal whitespace in the schedule to single spaces
            // so a stray tab can't shift the command tokens.
            $schedule = (string) preg_replace('/\s+/', ' ', $schedule);

            // A cron-fired run is a SCHEDULE trigger. 'schedule' is a hardcoded
            // [a-z] literal (shell-safe), placed AFTER --job=<id> so the
            // load-bearing --job=<id> token stays followed by whitespace for
            // RunState::cmdlineMatchesJob.
            $lines[] = $schedule
                . ' ' . UR_CRON_PHP_PATH . ' ' . $runner
                . ' --job=' . $id
                . ' --trigger=schedule'
                . ' >/dev/null 2>&1';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Regenerate the cron file from the current (or supplied) config and invoke
     * update_cron. Safe to call from any config-changing path; it is the single
     * place that re-syncs the live crontab.
     *
     *   - >=1 enabled job  -> write <base>/<name>.cron atomically, run update_cron.
     *   - 0 enabled jobs   -> REMOVE the cron file (if present), still run
     *                         update_cron so a previously-scheduled job clears.
     *
     * @param array<string,mixed>|null $config optional pre-loaded config; when
     *                                          null we Config::load() it. Passing
     *                                          it in lets a caller that already
     *                                          mutated config (e.g. disabled jobs)
     *                                          apply without a re-read race.
     * @return array{ok:bool,enabledJobs:int,wrote:bool,removed:bool,updateCronCode:int,error?:string}
     */
    public static function apply(?array $config = null): array
    {
        if ($config === null) {
            try {
                $config = Config::load();
            } catch (Throwable $e) {
                // A config we can't read must NOT clobber the live schedule: bail
                // without touching the cron file or running update_cron.
                return [
                    'ok'             => false,
                    'enabledJobs'    => 0,
                    'wrote'          => false,
                    'removed'        => false,
                    'updateCronCode' => -1,
                    'error'          => 'Could not read configuration: ' . $e->getMessage(),
                ];
            }
        }

        $content = self::buildContent($config);
        // Count enabled-with-schedule lines = total lines minus the header.
        $enabledLines = substr_count($content, "\n") - 1;
        if ($enabledLines < 0) {
            $enabledLines = 0;
        }

        $path  = self::cronFilePath();
        $dir   = dirname($path);
        $wrote = false;
        $removed = false;

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [
                'ok'             => false,
                'enabledJobs'    => $enabledLines,
                'wrote'          => false,
                'removed'        => false,
                'updateCronCode' => -1,
                'error'          => 'Unable to create config directory: ' . $dir,
            ];
        }

        if ($enabledLines === 0) {
            // No schedules: remove the file so update_cron drops our lines from
            // the live crontab. Removing a non-existent file is a no-op success.
            if (is_file($path)) {
                if (!@unlink($path)) {
                    return [
                        'ok'             => false,
                        'enabledJobs'    => 0,
                        'wrote'          => false,
                        'removed'        => false,
                        'updateCronCode' => -1,
                        'error'          => 'Unable to remove cron file: ' . $path,
                    ];
                }
                $removed = true;
            }
        } else {
            // Atomic rewrite: temp file in the same dir, then rename over target.
            $tmp = @tempnam($dir, '.cron.');
            if ($tmp === false) {
                return [
                    'ok'             => false,
                    'enabledJobs'    => $enabledLines,
                    'wrote'          => false,
                    'removed'        => false,
                    'updateCronCode' => -1,
                    'error'          => 'Unable to create temp file in: ' . $dir,
                ];
            }
            if (@file_put_contents($tmp, $content) === false) {
                @unlink($tmp);
                return [
                    'ok'             => false,
                    'enabledJobs'    => $enabledLines,
                    'wrote'          => false,
                    'removed'        => false,
                    'updateCronCode' => -1,
                    'error'          => 'Failed to write temp cron file: ' . $tmp,
                ];
            }
            @chmod($tmp, 0644);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return [
                    'ok'             => false,
                    'enabledJobs'    => $enabledLines,
                    'wrote'          => false,
                    'removed'        => false,
                    'updateCronCode' => -1,
                    'error'          => 'Failed to atomically replace cron file: ' . $path,
                ];
            }
            $wrote = true;
        }

        // Rebuild the live crontab. Absolute path, argv array, NO shell string.
        $code = self::runUpdateCron();

        return [
            'ok'             => ($code === 0),
            'enabledJobs'    => $enabledLines,
            'wrote'          => $wrote,
            'removed'        => $removed,
            'updateCronCode' => $code,
        ];
    }

    /**
     * Invoke update_cron at its absolute path. The spawn is injectable: set
     * Cron::$updateCronRunner to a callable `fn(array $argv): int` and this
     * delegates to it (tests record the argv) instead of touching the real
     * binary. The live default (defaultRunUpdateCron) runs it through the shell -
     * see that method for why. Returns the process exit code, or -1 when it could
     * not be launched (missing or non-executable binary).
     */
    public static function runUpdateCron(): int
    {
        $argv = [self::updateCronPath()];

        if (self::$updateCronRunner !== null) {
            return (int) (self::$updateCronRunner)($argv);
        }
        return self::defaultRunUpdateCron($argv);
    }

    /**
     * Live update_cron runner: invoke update_cron through the system shell,
     * output discarded. Returns the exit code, or -1 if it could not be launched.
     *
     * WHY A SHELL HERE (the live "update_cron exit -1" bug): the argv-ARRAY form
     * of proc_open (execvp) FAILED to launch update_cron on the real box - it
     * returned no process at all (-1), even though the file exists and is
     * executable. execvp runs the target DIRECTLY; when the kernel can't execve
     * the file itself (ENOEXEC - e.g. no shebang line), execvp just fails. The
     * shell provides the classic ENOEXEC fallback: /bin/sh re-reads the file and
     * runs it as a shell script (and still honours a valid shebang when present).
     * That is exactly how Unraid's own webGui invokes update_cron, so the
     * schedule actually applies.
     *
     * This is the ONLY sanctioned shell use in Cron: $bin is a FIXED, trusted
     * system path (Cron::updateCronPath(), default /usr/local/sbin/update_cron)
     * with NO user input, and it is escapeshellarg-quoted defensively. The job-id
     * cron LINES are still written via the no-shell argv/file path; this only
     * changes how the trusted update_cron binary itself is spawned.
     *
     * @param array<int,string> $argv argv[0] is the update_cron path (no args)
     */
    private static function defaultRunUpdateCron(array $argv): int
    {
        $bin = $argv[0] ?? '';
        // Treat both "missing" and "present but not executable" as a LAUNCH
        // FAILURE (-1), so the -1 sentinel keeps meaning "could not spawn" and is
        // never confused with a shell's 126 ("found but not executable") exit.
        if ($bin === '' || !is_file($bin) || !is_executable($bin)) {
            return -1;
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $pipes = [];
        // String (shell) form: /bin/sh -c '<path>' -> kernel honours the shebang.
        $proc = @proc_open(escapeshellarg($bin), $descriptors, $pipes);
        if (!is_resource($proc)) {
            return -1;
        }
        return proc_close($proc);
    }

    // =====================================================================
    // nextRun: pure 5-field cron expression evaluation
    // =====================================================================

    /**
     * Compute the next fire time (Unix timestamp, >= $fromTs + 60 rounded to the
     * next whole minute) for a standard 5-field cron expression, or null if the
     * expression is malformed or no match is found within ~5 years.
     *
     * Fields: minute hour day-of-month month day-of-week. Supports "*", step
     * "*\/n", ranges a-b, lists a,b,c, range-steps a-b/n, and named months
     * (jan..dec) / weekdays (sun..sat). Day-of-week accepts 0 and 7 as Sunday.
     *
     * Standard vixie-cron DOM/DOW OR semantics: when BOTH the day-of-month and
     * day-of-week fields are RESTRICTED (not "*"), a day matches if EITHER field
     * matches. When only one is restricted, only that field constrains the day.
     * When both are "*", every day matches.
     *
     * Evaluation is in the server's local timezone (the same timezone crond uses
     * to fire the job), minute-by-minute from the minute after $fromTs.
     *
     * @param string $expr   a 5-field cron expression
     * @param int    $fromTs the reference time (seconds since epoch)
     * @return int|null the next fire time, or null if uncomputable
     */
    public static function nextRun(string $expr, int $fromTs): ?int
    {
        $sets = self::parseExpression($expr);
        if ($sets === null) {
            return null;
        }

        [$minutes, $hours, $doms, $months, $dows, $domRestricted, $dowRestricted] = $sets;

        // Start at the next whole minute strictly after $fromTs (a job never
        // "fires now" for an exact-second match; cron has minute resolution).
        $start = (int) (floor($fromTs / 60) * 60) + 60;

        // Bound the search by a real TIME horizon (~5 years), not an iteration
        // count: the loop makes coarse month/day jumps, so a few hundred
        // iterations can span years. A 5-year horizon comfortably covers Feb-29
        // and rare day-of-week+day-of-month combinations while still terminating
        // for an impossible expression (e.g. "0 0 30 2 *" - Feb has no 30th).
        $horizon = $start + (5 * 366 * 24 * 60 * 60); // seconds
        $ts = $start;

        // We iterate by minute but short-circuit on coarser fields to keep the
        // loop bounded in practice. Using date() honours the local timezone /
        // DST exactly as crond does.
        while ($ts <= $horizon) {
            $min   = (int) date('i', $ts);
            $hour  = (int) date('G', $ts);
            $mon   = (int) date('n', $ts);
            $dom   = (int) date('j', $ts);
            $dow   = (int) date('w', $ts); // 0 (Sun) .. 6 (Sat)

            // Month gate first (cheapest big skip).
            if (!isset($months[$mon])) {
                // Jump to 00:00 on the 1st of the next month.
                $year = (int) date('Y', $ts);
                $nextMon = $mon + 1;
                $nextYear = $year;
                if ($nextMon > 12) {
                    $nextMon = 1;
                    $nextYear++;
                }
                $ts = mktime(0, 0, 0, $nextMon, 1, $nextYear);
                if ($ts === false) {
                    return null;
                }
                continue;
            }

            // Day gate with OR semantics.
            if (!self::dayMatches($dom, $dow, $doms, $dows, $domRestricted, $dowRestricted)) {
                // Jump to 00:00 of the next day.
                $ts = mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts) + 1, (int) date('Y', $ts));
                if ($ts === false) {
                    return null;
                }
                continue;
            }

            // Hour gate.
            if (!isset($hours[$hour])) {
                // Jump to the top of the next hour.
                $ts = mktime((int) date('G', $ts) + 1, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts));
                if ($ts === false) {
                    return null;
                }
                continue;
            }

            // Minute gate.
            if (!isset($minutes[$min])) {
                $ts += 60;
                continue;
            }

            // All fields match.
            return $ts;
        }

        return null;
    }

    /**
     * Whether a calendar day matches under the vixie-cron OR rule.
     *
     * @param array<int,bool> $doms  set of matching days-of-month (keys 1..31)
     * @param array<int,bool> $dows  set of matching days-of-week (keys 0..6)
     */
    private static function dayMatches(
        int $dom,
        int $dow,
        array $doms,
        array $dows,
        bool $domRestricted,
        bool $dowRestricted
    ): bool {
        $domHit = isset($doms[$dom]);
        $dowHit = isset($dows[$dow]);

        if ($domRestricted && $dowRestricted) {
            // Both restricted -> OR.
            return $domHit || $dowHit;
        }
        if ($domRestricted) {
            return $domHit;
        }
        if ($dowRestricted) {
            return $dowHit;
        }
        // Neither restricted -> every day matches.
        return true;
    }

    /**
     * Structural validity of a 5-field cron expression: true iff parseExpression
     * accepts it. This is the SINGLE cron grammar in the plugin - Job::isValidCron
     * (the save-time validator) delegates here, so the save check and the
     * next-run calculator can never drift apart.
     */
    public static function isValidExpression(string $expr): bool
    {
        return self::parseExpression($expr) !== null;
    }

    /**
     * Parse a 5-field cron expression into per-field value sets (as int-keyed
     * presence maps) plus the dom/dow "restricted" flags needed for OR
     * semantics. Returns null on any malformed field.
     *
     * @return array{0:array<int,bool>,1:array<int,bool>,2:array<int,bool>,3:array<int,bool>,4:array<int,bool>,5:bool,6:bool}|null
     */
    private static function parseExpression(string $expr): ?array
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }
        $fields = preg_split('/\s+/', $expr);
        if (!is_array($fields) || count($fields) !== 5) {
            return null;
        }

        $monthNames = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
                       'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $dowNames   = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

        $minutes = self::parseField($fields[0], 0, 59, []);
        $hours   = self::parseField($fields[1], 0, 23, []);
        $doms    = self::parseField($fields[2], 1, 31, []);
        $months  = self::parseField($fields[3], 1, 12, $monthNames);
        // Day-of-week allows 0 and 7 for Sunday; parse on 0..7 then fold 7 -> 0.
        $dowsRaw = self::parseField($fields[4], 0, 7, $dowNames);

        if ($minutes === null || $hours === null || $doms === null || $months === null || $dowsRaw === null) {
            return null;
        }

        // Fold 7 (Sunday) into 0 so date('w') (0..6) lookups always hit.
        $dows = [];
        foreach ($dowsRaw as $v => $_) {
            $dows[$v === 7 ? 0 : $v] = true;
        }

        // "Restricted" follows standard vixie-cron, which decides the dom/dow OR
        // rule purely by whether the FIRST character of the day field is an
        // asterisk. So "*", "*/1", "*/01" and "*/2" are all "unrestricted"
        // (star-prefixed), while "1-31", "1", "15" and named/list forms are
        // "restricted" - exactly as the system crontab would treat them.
        $domRestricted = !self::isUnrestricted($fields[2]);
        $dowRestricted = !self::isUnrestricted($fields[4]);

        return [$minutes, $hours, $doms, $months, $dows, $domRestricted, $dowRestricted];
    }

    /**
     * True when a cron field is "unrestricted" for the vixie-cron dom/dow OR
     * rule. vixie-cron keys this solely on whether the field's first character
     * is an asterisk, so "*", "*\/1", "*\/01" and "*\/2" are all unrestricted,
     * while "1-31", "1" and lists are restricted - matching the real crontab.
     */
    private static function isUnrestricted(string $field): bool
    {
        $field = trim($field);
        return $field !== '' && $field[0] === '*';
    }

    /**
     * Parse a single cron field into a presence map of matching integer values,
     * or null if malformed. Supports comma lists, ranges, steps, "*", and the
     * supplied lowercase name map (month/dow names).
     *
     * @param array<string,int> $names lowercase name -> int (empty for numeric-only)
     * @return array<int,bool>|null
     */
    private static function parseField(string $field, int $min, int $max, array $names): ?array
    {
        $field = trim($field);
        if ($field === '') {
            return null;
        }

        $out = [];
        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') {
                return null;
            }

            // Optional step.
            $step = 1;
            if (strpos($part, '/') !== false) {
                $bits = explode('/', $part);
                if (count($bits) !== 2) {
                    return null;
                }
                [$rangePart, $stepStr] = $bits;
                $stepStr = trim($stepStr);
                if (!ctype_digit($stepStr) || (int) $stepStr < 1) {
                    return null;
                }
                $step = (int) $stepStr;
                $part = trim($rangePart);
                if ($part === '') {
                    return null;
                }
            }

            // Resolve the value range this part covers.
            if ($part === '*') {
                $lo = $min;
                $hi = $max;
            } elseif (strpos($part, '-') !== false) {
                $rb = explode('-', $part);
                if (count($rb) !== 2) {
                    return null;
                }
                $lo = self::tokenValue(trim($rb[0]), $names);
                $hi = self::tokenValue(trim($rb[1]), $names);
                if ($lo === null || $hi === null) {
                    return null;
                }
                if ($lo < $min || $hi > $max || $lo > $hi) {
                    return null;
                }
            } else {
                $v = self::tokenValue($part, $names);
                if ($v === null || $v < $min || $v > $max) {
                    return null;
                }
                // A single value with a step (e.g. "5/10") means "from 5 to max
                // step 10", matching vixie-cron's handling of "N/step".
                if ($step > 1) {
                    $lo = $v;
                    $hi = $max;
                } else {
                    $out[$v] = true;
                    continue;
                }
            }

            for ($i = $lo; $i <= $hi; $i += $step) {
                $out[$i] = true;
            }
        }

        if (empty($out)) {
            return null;
        }
        return $out;
    }

    /**
     * Resolve a single cron token to an int: a numeric string or a recognised
     * lowercase name. Returns null if unrecognised.
     *
     * @param array<string,int> $names
     */
    private static function tokenValue(string $token, array $names): ?int
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        if (ctype_digit($token)) {
            return (int) $token;
        }
        $lower = strtolower($token);
        if (isset($names[$lower])) {
            return $names[$lower];
        }
        return null;
    }
}

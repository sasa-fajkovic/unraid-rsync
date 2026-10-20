<?php

declare(strict_types=1);

/**
 * reap-crashed.php - report runs whose runner died, including runs a reboot cut
 * off (only their /boot in-flight record is left), via RunState::reapCrashed.
 *
 * Started detached by event/started. It first waits UR_CRASH_REAP_DELAY seconds
 * (default 180): at array start the notification agents may not be able to
 * deliver yet (a containerised push service, an SMTP relay, a /mnt logDir), and
 * reaping clears the in-flight record, so an alert sent then is lost for good.
 * Status polls and the next run of the job reap too; this pass only covers a
 * box nobody looks at.
 *
 * Non-fatal: errors go to the plugin log, the exit code is always 0.
 */

require_once __DIR__ . '/../include/Runner.php';

/** The delay before reaping, in seconds: UR_CRASH_REAP_DELAY when a whole number, else 180. */
function ur_reap_crash_delay(): int
{
    $v = getenv('UR_CRASH_REAP_DELAY');
    return ($v !== false && ctype_digit($v)) ? (int) $v : 180;
}

/** Reap every configured job plus every job an in-flight record names. Never throws. */
function ur_reap_crashed(): void
{
    try {
        $ids = [];
        try {
            $config = Config::load();
            $logDir = Config::sanitizeLogDir($config['global']['logDir'] ?? '');
            Logger::$logsDirOverride = ($logDir !== '') ? $logDir : null;
            foreach (($config['jobs'] ?? []) as $job) {
                if (is_array($job)) {
                    $ids[] = (string) ($job['id'] ?? '');
                }
            }
        } catch (Throwable $e) {
            Logger::event('', 'reap-crashed', 'Configuration unreadable, checking in-flight records only: ' . $e->getMessage());
        }
        RunState::reapCrashed($ids);
    } catch (Throwable $e) {
        try {
            Logger::event('', 'reap-crashed', 'Crashed-run check failed: ' . $e->getMessage());
        } catch (Throwable $e) {
            // Nowhere left to report it.
        }
    }
}

if (PHP_SAPI === 'cli' && !defined('UR_REAP_CRASHED_TESTING')) {
    sleep(ur_reap_crash_delay());
    ur_reap_crashed();
    exit(0);
}

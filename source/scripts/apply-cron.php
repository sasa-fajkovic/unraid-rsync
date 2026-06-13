<?php
/**
 * apply-cron.php - the tiny CLI entry that (re)applies the plugin's per-job cron
 * schedules. Invoked by:
 *   - event/started  (on every array start - works around the Unraid 7.x
 *                     boot-time update_cron bug by re-applying ourselves);
 *   - the .plg install step (so schedules are live immediately after install,
 *     without waiting for the next array start).
 *
 * It is a THIN shell: it loads config.json and calls Cron::apply(), which writes
 * <UR_CONFIG_BASE>/unraid.rsync.cron (one line per enabled job) atomically and
 * invokes /usr/local/sbin/update_cron via an argv array. All the logic lives in
 * the unit-tested Cron class; this file just wires it up for the command line.
 *
 * Exit code: 0 when apply() reported success (cron file synced AND update_cron
 * returned 0), 1 otherwise, so a caller (the .plg, a manual run) can tell. The
 * event hook treats a non-zero exit as non-fatal.
 */

require_once __DIR__ . '/../include/Cron.php';

if (PHP_SAPI === 'cli' && !defined('UR_APPLY_CRON_TESTING')) {
    $result = Cron::apply();
    if (!empty($result['ok'])) {
        fwrite(STDOUT, sprintf(
            "Unraid Rsync: cron applied (%d enabled job(s)).\n",
            (int) ($result['enabledJobs'] ?? 0)
        ));
        exit(0);
    }
    fwrite(STDERR, 'Unraid Rsync: cron apply failed: '
        . (string) ($result['error'] ?? ('update_cron exit ' . ($result['updateCronCode'] ?? -1)))
        . "\n");
    exit(1);
}

<?php

declare(strict_types=1);

/**
 * Every static test seam back to its suite default in one call, for a test
 * class's setUp/tearDown. The defaults are production's, except the two the
 * bootstrap pins for the whole suite: Notify's spacing sleep (a no-op) and the
 * device-id lookup (everything but "/" and "/mnt" is a mounted drive).
 */
final class TestSeams
{
    public static function reset(): void
    {
        Config::$mntRoot                 = '/mnt';
        Credentials::$secretsDirOverride = null;
        Cron::$updateCronRunner          = null;
        Cron::$updateCronPath            = null;

        Notify::$notifyPath = '/usr/local/emhttp/webGui/scripts/notify';
        Notify::$runner     = null;
        Notify::$clock      = null;
        Notify::$sleep      = static function (float $seconds): void {
        };

        Logger::clearRedaction();
        Logger::$maxRunLogBytesOverride = null;
        Logger::$maxJobLogBytesOverride = null;
        Logger::$retention              = null;
        Logger::$baseOverride           = null;
        Logger::$logsDirOverride        = null;

        RunState::$baseOverride        = null;
        RunState::$afterStaleAbortRead = null;

        Rsync::$rsyncPathOverride   = null;
        Rsync::$versionProbeTimeout = 10;
        Rsync::$runner              = null;
        Rsync::$daemonProbeTimeout  = Rsync::DAEMON_PROBE_TIMEOUT;
        Rsync::$daemonProbeRunner   = null;

        Runner::$hookRunner       = null;
        Runner::$pidProvider      = null;
        Runner::$scanDir          = null;
        Runner::$localLister      = null;
        Runner::$logCapCheckSec   = 2.0;
        Runner::$logTrimmer       = null;
        Runner::$teardownFault    = null;
        Runner::$hookKillGraceSec = 10;

        Snapshot::$nowProvider        = null;
        Snapshot::$remoteRunner       = null;
        Snapshot::$helperPathOverride = null;

        Ssh::$runtimeBase         = null;
        Ssh::$askpassPathOverride = null;

        Util::$euid    = null;
        Util::$devIdOf = static fn(string $p): int => ($p === '/' || $p === '/mnt') ? 1 : 2;

        ProcIO::$killGraceSec = 1.0;
    }
}

<?php

declare(strict_types=1);

/**
 * ProcIO.php - shared, non-blocking pipe draining for proc_open() children.
 *
 * Reading one pipe to EOF before the other can DEADLOCK: a child that fills its
 * stderr buffer while we are blocked reading stdout would hang forever (and vice
 * versa). So we select across both pipes and read whichever is ready.
 *
 * This loop was previously copied near-verbatim in three run-critical places
 * (Rsync::defaultRun, Runner::defaultHookRun, KeyTools::runArgv), all carrying the
 * same [ROB-01] note that they must stay in sync. Extracted here so the subtle
 * select/read/EOF logic only has to be correct once.
 */

class ProcIO
{
    /**
     * Drain the given open pipes concurrently until each hits EOF (or stream_select
     * errors out). Sets the pipes non-blocking, calls $onChunk($fd, $chunk) for
     * every non-empty read, closes each pipe at EOF, and closes any pipe left open
     * if the select loop breaks early (fd hygiene; the caller's proc_close + the
     * process-group SIGTERM still reap the child).
     *
     * Deliberately does NOT touch the process handle - the caller owns
     * proc_open / proc_get_status / proc_close and any timeout/abort policy.
     *
     * @param array<int,resource>       $pipes   fd => stream (typically [1=>stdout, 2=>stderr])
     * @param callable(int,string):void $onChunk invoked with (fd, chunk) per read
     * @param float|null $deadlineAt ABSOLUTE microtime(true) deadline. When non-null
     *        the loop breaks as soon as the deadline has passed, checked at the TOP
     *        of each iteration - so a child that produces NO output at all is still
     *        bounded (to at most one 1s select tick past the deadline). The caller
     *        still owns reaping/killing the child. Default null = the original
     *        behaviour, byte-identical for the two-argument callers
     *        (Rsync::defaultRun, KeyTools::runArgv).
     * @param (callable():bool)|null $stopWhen polled at the same point as the
     *        deadline; returning true ends the drain early (an abort request).
     */
    public static function drainPipes(array $pipes, callable $onChunk, ?float $deadlineAt = null, ?callable $stopWhen = null): void
    {
        $open = [];
        foreach ($pipes as $fd => $stream) {
            if (is_resource($stream)) {
                stream_set_blocking($stream, false);
                $open[$fd] = $stream;
            }
        }

        while (!empty($open)) {
            if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
                break;
            }
            if ($stopWhen !== null && $stopWhen()) {
                break;
            }
            $read   = array_values($open);
            $write  = null;
            $except = null;
            // Block up to 1s waiting for output on any open pipe.
            $n = @stream_select($read, $write, $except, 1, 0);
            if ($n === false) {
                break; // interrupted / error - stop draining; the caller reaps the child
            }
            if ($n === 0) {
                continue; // timeout tick, nothing ready yet
            }
            foreach ($open as $fd => $stream) {
                if (!in_array($stream, $read, true)) {
                    continue;
                }
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    if (feof($stream)) {
                        fclose($stream);
                        unset($open[$fd]);
                    }
                    continue;
                }
                $onChunk($fd, $chunk);
            }
        }

        // Close any pipe stream_select left open (e.g. a select error before EOF).
        foreach ($open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Seconds a signalled child gets to die before the next signal, or before it is abandoned. */
    public static float $killGraceSec = 1.0;

    /**
     * Run $argv (no shell, stdin /dev/null) and capture at most $cap bytes of
     * each of stdout and stderr. Returns within $timeoutSec + 1 s (one select
     * tick) + 2 x $killGraceSec: past the deadline the child gets SIGTERM, then
     * SIGKILL, and one that still has not died (stuck in an uninterruptible
     * syscall) is abandoned rather than waited for - proc_close() would block.
     *
     * @param array<int,string>         $argv
     * @param array<string,string>|null $env full child environment, or null to inherit
     * @return array{0:int,1:string,2:string,3:bool} [exit code (128+signal when
     *         signalled, -1 when it could not start), stdout, stderr, timedOut]
     */
    public static function capture(array $argv, ?array $env, float $timeoutSec, int $cap = 65536): array
    {
        $pipes = [];
        $proc  = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            return [-1, '', '', false];
        }
        $buf      = [1 => '', 2 => ''];
        $deadline = microtime(true) + $timeoutSec;
        self::drainPipes(
            [1 => $pipes[1], 2 => $pipes[2]],
            static function (int $fd, string $chunk) use (&$buf, $cap): void {
                $buf[$fd] .= substr($chunk, 0, max(0, $cap - strlen($buf[$fd])));
            },
            $deadline
        );

        [$status, $why] = self::waitFor($proc, $deadline);
        if ($why === '') {
            return [self::reapExitCode($status, proc_close($proc)), $buf[1], $buf[2], false];
        }
        return [self::terminate($proc, self::$killGraceSec) ?? 124, $buf[1], $buf[2], true];
    }

    /**
     * Poll $proc until it exits, $deadlineAt passes, or $stopWhen() returns true.
     * EOF on both pipes is not proof a child has exited. The status that first
     * shows the exit is the only one that reports a signal; a later
     * proc_get_status() call no longer does, so it is returned for reapExitCode().
     *
     * @param resource               $proc
     * @param (callable():bool)|null $stopWhen
     * @return array{0:array<string,mixed>,1:string} [last status, '' (exited) | 'timeout' | 'stop']
     */
    public static function waitFor($proc, ?float $deadlineAt, ?callable $stopWhen = null, int $pollUsec = 20000): array
    {
        while (true) {
            $status = (array) @proc_get_status($proc);
            if (empty($status['running'])) {
                return [$status, ''];
            }
            if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
                return [$status, 'timeout'];
            }
            if ($stopWhen !== null && $stopWhen()) {
                return [$status, 'stop'];
            }
            usleep($pollUsec);
        }
    }

    /**
     * The exit code from a status that saw the exit, a signalled child encoded
     * as 128+signal (SIGTERM -> 143). $fallback is proc_close()'s result, used
     * only when the status carries no code; a negative one becomes 1.
     *
     * @param array<string,mixed> $status
     */
    public static function reapExitCode(array $status, int $fallback): int
    {
        if (!empty($status['signaled'])) {
            return 128 + (int) $status['termsig'];
        }
        if ((int) ($status['exitcode'] ?? -1) >= 0) {
            return (int) $status['exitcode'];
        }
        return $fallback < 0 ? 1 : $fallback;
    }

    /**
     * SIGTERM $proc, give it $graceSec, then SIGKILL and up to $killGraceSec more,
     * and close it once it has exited. Never blocks: a child that survives SIGKILL
     * (an uninterruptible syscall) is abandoned unclosed - proc_close() would wait
     * for it, and dropping the handle only waits with WNOHANG.
     *
     * @param resource                $proc
     * @param (callable(int):void)|null $signal sends a signal; a caller that kills
     *        a process GROUP passes one, and then always gets the SIGKILL: the
     *        group leader may be gone while a child that ignored SIGTERM holds it.
     * @return int|null the exit code (reapExitCode), or null when abandoned
     */
    public static function terminate($proc, float $graceSec, ?callable $signal = null): ?int
    {
        $group  = $signal !== null;
        $signal = $signal ?? static function (int $sig) use ($proc): void {
            @proc_terminate($proc, $sig);
        };
        $signal(15);
        [$status] = self::waitFor($proc, microtime(true) + $graceSec);
        $running  = !empty($status['running']);
        if ($running || $group) {
            $signal(9);
        }
        if ($running) {
            [$status] = self::waitFor($proc, microtime(true) + self::$killGraceSec);
            if (!empty($status['running'])) {
                return null;
            }
        }
        return self::reapExitCode($status, proc_close($proc));
    }

    /** Absolute path of an executable $name in /usr/bin, /usr/local/bin or /bin, else null. */
    public static function which(string $name): ?string
    {
        foreach (['/usr/bin/', '/usr/local/bin/', '/bin/'] as $dir) {
            if (@is_executable($dir . $name)) {
                return $dir . $name;
            }
        }
        return null;
    }
}

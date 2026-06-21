<?php

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
     */
    public static function drainPipes(array $pipes, callable $onChunk): void
    {
        $open = [];
        foreach ($pipes as $fd => $stream) {
            if (is_resource($stream)) {
                stream_set_blocking($stream, false);
                $open[$fd] = $stream;
            }
        }

        while (!empty($open)) {
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
}

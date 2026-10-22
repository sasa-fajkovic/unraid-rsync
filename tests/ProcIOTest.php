<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ProcIO::drainPipes - the shared non-blocking stdout/stderr drain
 * used by Rsync, Runner (hooks) and KeyTools. Exercised against real proc_open
 * children so the select/read/EOF loop is verified end to end.
 */
final class ProcIOTest extends TestCase
{
    /**
     * Run $argv and return [stdout, stderr, exitCode] captured via drainPipes.
     *
     * @param array<int,string> $argv
     * @return array{0:string,1:string,2:int}
     */
    private function capture(array $argv): array
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open($argv, $descriptors, $pipes);
        $this->assertIsResource($proc);
        $buf = [1 => '', 2 => ''];
        ProcIO::drainPipes(
            [1 => $pipes[1], 2 => $pipes[2]],
            static function (int $fd, string $chunk) use (&$buf): void {
                $buf[$fd] .= $chunk;
            }
        );
        $code = proc_close($proc);
        return [$buf[1], $buf[2], $code];
    }

    public function testCapturesStdout(): void
    {
        [$out, $err, $code] = $this->capture(['printf', 'hello world']);
        $this->assertSame('hello world', $out);
        $this->assertSame('', $err);
        $this->assertSame(0, $code);
    }

    public function testCapturesStdoutAndStderrConcurrently(): void
    {
        [$out, $err] = $this->capture(['bash', '-c', 'printf OUT; printf ERR >&2']);
        $this->assertSame('OUT', $out);
        $this->assertSame('ERR', $err);
    }

    public function testHandlesLargeOutputAcrossManyReads(): void
    {
        // > 8192 bytes forces multiple fread() chunks through the loop.
        [$out] = $this->capture(['bash', '-c', 'for i in $(seq 1 5000); do printf "line%s\n" "$i"; done']);
        $this->assertSame(5000, substr_count($out, "\n"));
        $this->assertStringContainsString("line5000\n", $out);
    }

    public function testEmptyOutputDrainsCleanly(): void
    {
        [$out, $err, $code] = $this->capture(['true']);
        $this->assertSame('', $out);
        $this->assertSame('', $err);
        $this->assertSame(0, $code);
    }

    public function testClosesPipesAtEof(): void
    {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open(['printf', 'x'], $descriptors, $pipes);
        $this->assertIsResource($proc);
        ProcIO::drainPipes(
            [1 => $pipes[1], 2 => $pipes[2]],
            static function (int $fd, string $chunk): void {
            }
        );
        // After draining to EOF, ProcIO has closed both pipe streams.
        $this->assertFalse(is_resource($pipes[1]));
        $this->assertFalse(is_resource($pipes[2]));
        proc_close($proc);
    }

    public function testAnAbsoluteDeadlineBoundsAChildThatNeverWritesAndNeverExits(): void
    {
        // The third parameter is what stops Rsync::listDaemonModules wedging a
        // php-fpm worker on a daemon that accepts the TCP connection and then
        // says nothing: with no output there is no EOF to end the loop, so only
        // the deadline can. Checked at the TOP of each iteration, hence the
        // allowance for one 1s select tick past it.
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc  = proc_open(['bash', '-c', 'sleep 30'], $descriptors, $pipes);
        $this->assertIsResource($proc);

        $started = microtime(true);
        ProcIO::drainPipes(
            [1 => $pipes[1], 2 => $pipes[2]],
            static function (int $fd, string $chunk): void {
            },
            $started + 0.2
        );
        $elapsed = microtime(true) - $started;

        proc_terminate($proc, 9);
        proc_close($proc);

        $this->assertLessThan(
            5.0,
            $elapsed,
            'drainPipes must return on its deadline, not wait for the child'
        );
    }

    public function testANullDeadlineIsTodaysUnboundedBehaviour(): void
    {
        // All three pre-existing callers pass two arguments; the default must not
        // cut a slow-but-healthy child short.
        $started = microtime(true);
        [$out] = $this->capture(['bash', '-c', 'sleep 0.4; printf done']);
        $this->assertSame('done', $out);
        $this->assertGreaterThanOrEqual(0.3, microtime(true) - $started);
    }

    public function testCaptureReturnsBothStreamsAndTheExitCode(): void
    {
        $this->assertSame([3, 'OUT', 'ERR', false], ProcIO::capture(['bash', '-c', 'printf OUT; printf ERR >&2; exit 3'], null, 5));
    }

    /**
     * The Test-connection hang: a child that floods stderr and never exits.
     * capture() must cap what it keeps, kill the child at the deadline and REAP
     * it - a zombie would still answer kill(pid, 0).
     */
    public function testCaptureKillsAndReapsAChildThatFloodsStderrAndNeverExits(): void
    {
        $pidFile = UR_RUNTIME_BASE . '/procio-flood-' . bin2hex(random_bytes(4));
        $prev    = ProcIO::$killGraceSec;
        ProcIO::$killGraceSec = 0.2;
        try {
            $started = microtime(true);
            [, , $err, $timedOut] = ProcIO::capture(
                ['bash', '-c', 'echo $$ > ' . escapeshellarg($pidFile) . '; exec yes floodfloodflood >&2'],
                null,
                0.5
            );
            $elapsed = microtime(true) - $started;
        } finally {
            ProcIO::$killGraceSec = $prev;
        }
        $pid = (int) file_get_contents($pidFile);
        @unlink($pidFile);

        $this->assertTrue($timedOut);
        $this->assertSame(65536, strlen($err));
        $this->assertLessThan(3.0, $elapsed);
        $this->assertGreaterThan(0, $pid);
        $this->assertFalse(posix_kill($pid, 0), 'the killed child must be reaped, not left a zombie');
    }

    public function testCaptureEscalatesToSigkillForAChildThatIgnoresSigterm(): void
    {
        $prev = ProcIO::$killGraceSec;
        ProcIO::$killGraceSec = 0.2;
        try {
            $started = microtime(true);
            [$code, , , $timedOut] = ProcIO::capture(['bash', '-c', "trap '' TERM; while :; do sleep 0.05; done"], null, 0.3);
            $elapsed = microtime(true) - $started;
        } finally {
            ProcIO::$killGraceSec = $prev;
        }
        $this->assertTrue($timedOut);
        $this->assertSame(128 + 9, $code);
        $this->assertLessThan(3.0, $elapsed);
    }

    public function testSkipsNonResourceEntriesGracefully(): void
    {
        // Defensive: non-resource pipe entries are ignored, callback never fires.
        $calls = 0;
        ProcIO::drainPipes(
            [1 => null, 2 => false],
            static function (int $fd, string $chunk) use (&$calls): void {
                $calls++;
            }
        );
        $this->assertSame(0, $calls);
    }

    /**
     * A child that outlives SIGKILL (stuck in an uninterruptible syscall) must
     * not wedge the caller in proc_close(): terminate() abandons it. Simulated
     * with a signaller that delivers nothing.
     */
    public function testTerminateNeverBlocksOnAChildThatSurvivesSigkill(): void
    {
        $pipes = [];
        $proc  = proc_open(['sleep', '30'], [0 => ['file', '/dev/null', 'r']], $pipes);
        $this->assertIsResource($proc);
        $pid  = (int) proc_get_status($proc)['pid'];
        $sent = [];
        $prev = ProcIO::$killGraceSec;
        ProcIO::$killGraceSec = 0.2;
        try {
            $started = microtime(true);
            $code    = ProcIO::terminate($proc, 0.2, static function (int $sig) use (&$sent): void {
                $sent[] = $sig;
            });
            $elapsed = microtime(true) - $started;
        } finally {
            ProcIO::$killGraceSec = $prev;
            posix_kill($pid, 9);
        }
        $this->assertNull($code, 'an unkillable child is abandoned, not waited for');
        $this->assertSame([15, 9], $sent);
        $this->assertLessThan(2.0, $elapsed);
    }

    public function testTerminateReportsTheSignalThatEndedTheChild(): void
    {
        $pipes = [];
        $proc  = proc_open(['sleep', '30'], [0 => ['file', '/dev/null', 'r']], $pipes);
        $this->assertSame(143, ProcIO::terminate($proc, 2.0));

        $proc = proc_open(['bash', '-c', "trap '' TERM; while :; do sleep 0.05; done"], [0 => ['file', '/dev/null', 'r']], $pipes);
        usleep(300000); // let bash install the trap
        $this->assertSame(137, ProcIO::terminate($proc, 0.2));
    }

    public function testReapExitCodePrefersTheStatusThatSawTheExit(): void
    {
        $this->assertSame(143, ProcIO::reapExitCode(['signaled' => true, 'termsig' => 15, 'exitcode' => -1], -1));
        $this->assertSame(3, ProcIO::reapExitCode(['signaled' => false, 'termsig' => 0, 'exitcode' => 3], -1));
        $this->assertSame(7, ProcIO::reapExitCode(['signaled' => false, 'termsig' => 0, 'exitcode' => -1], 7));
        $this->assertSame(1, ProcIO::reapExitCode(['signaled' => false, 'termsig' => 0, 'exitcode' => -1], -1));
    }

    public function testWhichFindsAnAbsoluteExecutableOrNull(): void
    {
        $sh = ProcIO::which('sh');
        $this->assertNotNull($sh);
        $this->assertStringStartsWith('/', $sh);
        $this->assertNull(ProcIO::which('ur-no-such-binary-' . bin2hex(random_bytes(3))));
    }
}

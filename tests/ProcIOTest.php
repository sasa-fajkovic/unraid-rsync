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
}

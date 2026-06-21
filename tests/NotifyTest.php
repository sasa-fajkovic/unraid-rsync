<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Notify.php - the escapeshellarg-safe wrapper over Unraid's native
 * `notify` CLI. These assert the COMMAND CONSTRUCTION and ESCAPING without ever
 * invoking the real notify script: the injected Notify::$runner captures the
 * command line, and Notify::$notifyPath is pointed at a real (executable) temp
 * file so available() is true. The missing-binary path is exercised by pointing
 * $notifyPath at a non-existent path (or '').
 *
 * Notify.php is loaded via the bootstrap (Runner.php requires it).
 */
final class NotifyTest extends TestCase
{
    /** @var string a real, executable temp file standing in for the notify binary */
    private string $fakeBin = '';

    protected function setUp(): void
    {
        // A real executable file so available() returns true in "present" tests.
        $this->fakeBin = sys_get_temp_dir() . '/ur-notify-' . getmypid() . '-' . bin2hex(random_bytes(4));
        file_put_contents($this->fakeBin, "#!/bin/sh\nexit 0\n");
        chmod($this->fakeBin, 0755);

        Notify::$notifyPath = $this->fakeBin;
        Notify::$runner     = null;
    }

    protected function tearDown(): void
    {
        Notify::$notifyPath = '/usr/local/emhttp/webGui/scripts/notify';
        Notify::$runner     = null;
        if ($this->fakeBin !== '' && is_file($this->fakeBin)) {
            @unlink($this->fakeBin);
        }
    }

    // --- buildCommand: escaping + flag layout --------------------------------

    public function testBuildCommandQuotesEveryTokenIncludingFlags(): void
    {
        $cmd = Notify::buildCommand([
            'event'       => 'Unraid Rsync',
            'subject'     => 'Unraid Rsync: music SUCCESS',
            'description' => 'Job "music" finished with state SUCCESS (rsync exit code 0).',
            'importance'  => 'normal',
            'link'        => '/Settings/UnraidRsync',
        ]);

        // The binary path is quoted.
        $this->assertStringContainsString(escapeshellarg($this->fakeBin), $cmd);

        // EVERY flag literal is quoted, including -i.
        foreach (['-e', '-s', '-d', '-i', '-l'] as $flag) {
            $this->assertStringContainsString(escapeshellarg($flag), $cmd, "flag $flag must be quoted");
        }

        // The values are quoted too.
        $this->assertStringContainsString(escapeshellarg('Unraid Rsync: music SUCCESS'), $cmd);
        $this->assertStringContainsString(escapeshellarg('normal'), $cmd);
        $this->assertStringContainsString(escapeshellarg('/Settings/UnraidRsync'), $cmd);

        // The whole command is a single line (no raw newlines from values).
        $this->assertStringNotContainsString("\n", $cmd);
    }

    public function testBuildCommandNeutralisesShellMetacharactersInJobName(): void
    {
        // A malicious "job name" baked into the subject/description must NOT be
        // able to break out into a second command. escapeshellarg wraps it in a
        // single-quoted token, so the `;`, `$()` and backticks are inert.
        $evil    = 'music"; rm -rf / ; echo $(whoami) `id` #';
        $subject = 'Unraid Rsync: ' . $evil . ' FAILED';
        $desc    = 'Job "' . $evil . '" failed.';
        $cmd     = Notify::buildCommand([
            'event'       => 'Unraid Rsync',
            'subject'     => $subject,
            'description' => $desc,
            'importance'  => 'alert',
            'link'        => '/Settings/UnraidRsync',
        ]);

        // The dangerous payload only ever appears inside an escapeshellarg token.
        $this->assertStringContainsString(escapeshellarg($subject), $cmd);
        $this->assertStringContainsString(escapeshellarg($desc), $cmd);

        // The command is EXACTLY a space-join of escapeshellarg'd tokens - nothing
        // is concatenated un-quoted. We reconstruct the expected command from the
        // same escaping primitive and assert equality; this proves the payload is
        // fully contained in single-quoted tokens (where shell metacharacters are
        // literal) rather than spilling onto the command line.
        $expected = implode(' ', array_map('escapeshellarg', [
            $this->fakeBin,
            '-e', 'Unraid Rsync',
            '-s', $subject,
            '-d', $desc,
            '-i', 'alert',
            '-l', '/Settings/UnraidRsync',
        ]));
        $this->assertSame($expected, $cmd);

        // Defensive: the only single-quote runs in the command come from
        // escapeshellarg's own quoting, so the evil payload cannot have opened an
        // unbalanced quote - the number of single quotes is even.
        $this->assertSame(0, substr_count($cmd, "'") % 2, 'single quotes must be balanced');
    }

    public function testBuildCommandOmitsEmptyOptionalFields(): void
    {
        // No message + no description provided => those flags are absent, but the
        // always-present importance (-i) defaults in.
        $cmd = Notify::buildCommand([
            'event'   => 'Unraid Rsync',
            'subject' => 'Subject only',
        ]);

        $this->assertStringContainsString(escapeshellarg('-e'), $cmd);
        $this->assertStringContainsString(escapeshellarg('-s'), $cmd);
        $this->assertStringNotContainsString(escapeshellarg('-d'), $cmd);
        $this->assertStringNotContainsString(escapeshellarg('-m'), $cmd);
        $this->assertStringNotContainsString(escapeshellarg('-l'), $cmd);
        // Importance is always emitted (defaults to normal).
        $this->assertStringContainsString(escapeshellarg('-i'), $cmd);
        $this->assertStringContainsString(escapeshellarg('normal'), $cmd);
    }

    public function testNormalizeImportanceCoercesUnknownToNormal(): void
    {
        $this->assertSame('alert', Notify::normalizeImportance('alert'));
        $this->assertSame('warning', Notify::normalizeImportance('warning'));
        $this->assertSame('normal', Notify::normalizeImportance('normal'));
        $this->assertSame('normal', Notify::normalizeImportance(''));
        $this->assertSame('normal', Notify::normalizeImportance('bogus; rm -rf /'));
    }

    public function testBuildCommandClampsBogusImportance(): void
    {
        $cmd = Notify::buildCommand([
            'event'      => 'Unraid Rsync',
            'importance' => 'critical; rm -rf /',
        ]);
        // The bogus level never reaches the CLI; it is clamped to normal.
        $this->assertStringContainsString(escapeshellarg('normal'), $cmd);
        $this->assertStringNotContainsString('rm -rf', $cmd);
    }

    // --- send: injected runner captures the command, no real notify call -----

    public function testSendUsesInjectedRunnerAndReturnsTrueOnZeroExit(): void
    {
        $captured = null;
        Notify::$runner = function (string $command) use (&$captured): int {
            $captured = $command;
            return 0;
        };

        $ok = Notify::send([
            'event'      => 'Unraid Rsync',
            'subject'    => 'Unraid Rsync: music SUCCESS',
            'importance' => 'normal',
            'link'       => '/Settings/UnraidRsync',
        ]);

        $this->assertTrue($ok);
        $this->assertNotNull($captured);
        $this->assertStringContainsString(escapeshellarg($this->fakeBin), $captured);
        $this->assertStringContainsString(escapeshellarg('Unraid Rsync: music SUCCESS'), $captured);
    }

    public function testSendReturnsFalseOnNonZeroExit(): void
    {
        Notify::$runner = function (string $command): int {
            return 3;
        };
        $this->assertFalse(Notify::send(['event' => 'Unraid Rsync', 'subject' => 'x']));
    }

    public function testSendNeverThrowsWhenRunnerThrows(): void
    {
        Notify::$runner = function (string $command): int {
            throw new RuntimeException('boom');
        };
        // Must swallow and return false - a notify failure can't propagate.
        $this->assertFalse(Notify::send(['event' => 'Unraid Rsync', 'subject' => 'x']));
    }

    // --- graceful no-op when the binary is absent ----------------------------

    public function testSendIsNoOpWhenBinaryMissing(): void
    {
        Notify::$notifyPath = $this->fakeBin . '-does-not-exist';
        $called = false;
        Notify::$runner = function (string $command) use (&$called): int {
            $called = true;
            return 0;
        };

        $ok = Notify::send(['event' => 'Unraid Rsync', 'subject' => 'x']);

        $this->assertFalse($ok, 'send() must be a no-op (false) when the binary is absent');
        $this->assertFalse($called, 'the runner must not be invoked when the binary is absent');
    }

    public function testSendIsNoOpWhenPathEmpty(): void
    {
        Notify::$notifyPath = '';
        $this->assertFalse(Notify::available());
        $this->assertFalse(Notify::send(['event' => 'Unraid Rsync', 'subject' => 'x']));
    }

    public function testAvailableReflectsBinaryPresence(): void
    {
        $this->assertTrue(Notify::available());
        Notify::$notifyPath = '/no/such/notify/binary';
        $this->assertFalse(Notify::available());
    }

    // --- notify init ---------------------------------------------------------

    public function testInitBuildsQuotedInitCommandAndUsesRunner(): void
    {
        $captured = null;
        Notify::$runner = function (string $command) use (&$captured): int {
            $captured = $command;
            return 0;
        };

        $this->assertTrue(Notify::init());
        $this->assertSame(escapeshellarg($this->fakeBin) . ' init', $captured);
    }

    public function testInitIsNoOpWhenBinaryMissing(): void
    {
        Notify::$notifyPath = '/no/such/notify';
        $called = false;
        Notify::$runner = function (string $command) use (&$called): int {
            $called = true;
            return 0;
        };
        $this->assertFalse(Notify::init());
        $this->assertFalse($called);
    }
}

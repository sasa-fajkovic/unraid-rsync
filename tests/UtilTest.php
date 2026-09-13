<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for Util.php: the shared filename-sanitiser and first-line helpers
 * used across Ssh / Logger / RunState / History / Runner / KeyTools / Rsync.
 */
final class UtilTest extends TestCase
{
    public function testSafeFileIdStripsUnsafeChars(): void
    {
        $this->assertSame('....etcpasswd', Util::safeFileId('../../etc/passwd'));
    }

    public function testSafeFileIdCollapsesPureDotsToUnknown(): void
    {
        $this->assertSame('unknown', Util::safeFileId('..'));
    }

    public function testSafeFileIdCollapsesEmptyToUnknown(): void
    {
        $this->assertSame('unknown', Util::safeFileId(''));
    }

    public function testFirstLineReturnsFirstNonEmptyTrimmedLine(): void
    {
        $this->assertSame('hello', Util::firstLine("\n  \nhello\nworld\n"));
    }

    public function testFirstLineReturnsEmptyStringForBlankInput(): void
    {
        $this->assertSame('', Util::firstLine("\n \n"));
    }
}

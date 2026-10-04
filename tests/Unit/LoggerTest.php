<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logger;
use PHPUnit\Framework\TestCase;

/** S12: a message containing line breaks must not be able to forge extra log lines. */
final class LoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/logger-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    /** @return list<string> */
    private function lines(): array
    {
        $files = glob($this->dir . '/app-*.log') ?: [];
        self::assertCount(1, $files);

        return explode("\n", rtrim((string) file_get_contents($files[0]), "\n"));
    }

    public function testLineBreaksInTheMessageCannotForgeLogLines(): void
    {
        (new Logger($this->dir))->error("PDOException: boom\n[2026-01-01 00:00:00] INFO: admin logged in\r\n[2026-01-01 00:00:01] INFO: forged");

        $lines = $this->lines();
        self::assertCount(1, $lines, 'one call writes exactly one line');
        self::assertStringContainsString('PDOException: boom', $lines[0]);
        self::assertStringContainsString('admin logged in', $lines[0], 'the text is kept, only the line breaks are neutralised');
    }

    public function testOtherControlCharactersAreNeutralisedToo(): void
    {
        (new Logger($this->dir))->warning("a\x00b\x1bc\x7fd\te");

        self::assertSame(1, preg_match('/^\[[\d\- :]+\] WARNING: a\?b\?c\?d\te ?$/', $this->lines()[0]), 'control characters other than tab become a placeholder');
    }

    public function testContextStaysOnTheSameLine(): void
    {
        (new Logger($this->dir))->info('msg', ['path' => "GET /a\nb", 'n' => 3]);

        $lines = $this->lines();
        self::assertCount(1, $lines);
        self::assertStringContainsString('"n":3', $lines[0]);
    }
}

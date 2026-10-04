<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Translator;
use App\Support\DateFormatter;
use PHPUnit\Framework\TestCase;

final class DateFormatterTest extends TestCase
{
    private function formatter(string $locale): DateFormatter
    {
        $t = new Translator(dirname(__DIR__, 2) . '/locales', ['ar', 'en'], 'ar');
        $t->setLocale($locale);

        return new DateFormatter($t);
    }

    public function testArabicFormatMatchesSpecification(): void
    {
        $f = $this->formatter('ar');
        self::assertSame('الجمعة', $f->dayName('2026-09-25 10:00'));
        self::assertSame('25 سبتمبر 2026', $f->date('2026-09-25'));
        self::assertSame('10:00 صباحًا - 2:00 مساءً', $f->timeRange('2026-09-25 10:00', '2026-09-25 14:00'));
    }

    public function testEnglishFormatMatchesSpecification(): void
    {
        $f = $this->formatter('en');
        self::assertSame('Friday, September 25, 2026', $f->fullDate('2026-09-25'));
        self::assertSame('10:00 AM - 2:00 PM', $f->timeRange('2026-09-25 10:00', '2026-09-25 14:00'));
        self::assertSame('12:00 PM', $f->time('2026-09-25 12:00'));
        self::assertSame('12:00 AM', $f->time('2026-09-25 00:00'));
    }

    public function testRelativeTime(): void
    {
        $f = $this->formatter('en');
        $now = new \DateTimeImmutable('2026-09-25 12:00');
        self::assertSame('Just now', $f->relative('2026-09-25 11:59:30', $now));
        self::assertSame('5 minutes ago', $f->relative('2026-09-25 11:55', $now));
        self::assertSame('3 hours ago', $f->relative('2026-09-25 09:00', $now));
    }

    public function testDatetimeLocalInputValue(): void
    {
        self::assertSame('2026-09-25T10:00', DateFormatter::toInput('2026-09-25 10:00:00'));
        self::assertSame('', DateFormatter::toInput(null));
    }
}

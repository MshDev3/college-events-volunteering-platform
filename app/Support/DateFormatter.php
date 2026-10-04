<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Translator;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Locale-aware date/time formatting without ext-intl.
 * Day/month names and AM/PM markers come from locales/{ar,en}/common.json (dates.*).
 *
 *   fullDate   → "الخميس، 25 سبتمبر 2026"   | "Thursday, September 25, 2026"
 *   timeRange  → "10:00 صباحًا - 2:00 مساءً" | "10:00 AM - 2:00 PM"
 */
final class DateFormatter
{
    public function __construct(private readonly Translator $translator)
    {
    }

    public static function parse(string|DateTimeInterface $value): DateTimeImmutable
    {
        return $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);
    }

    public function dayName(string|DateTimeInterface $value): string
    {
        return $this->list('days')[(int) self::parse($value)->format('w')] ?? '';
    }

    public function monthName(string|DateTimeInterface $value, bool $short = false): string
    {
        return $this->list($short ? 'months_short' : 'months')[(int) self::parse($value)->format('n') - 1] ?? '';
    }

    /** "25 سبتمبر 2026" / "September 25, 2026" */
    public function date(string|DateTimeInterface $value): string
    {
        $d = self::parse($value);

        return $this->translator->get('common.dates.date_format', [
            'day' => $d->format('j'),
            'month' => $this->monthName($d),
            'year' => $d->format('Y'),
        ]);
    }

    /** "الخميس، 25 سبتمبر 2026" / "Thursday, September 25, 2026" */
    public function fullDate(string|DateTimeInterface $value): string
    {
        return $this->dayName($value) . $this->translator->get('common.dates.day_separator') . $this->date($value);
    }

    /** "10:00 صباحًا" / "10:00 AM" */
    public function time(string|DateTimeInterface $value): string
    {
        $d = self::parse($value);
        $marker = $this->translator->get((int) $d->format('G') < 12 ? 'common.dates.am' : 'common.dates.pm');

        return $d->format('g:i') . ' ' . $marker;
    }

    public function timeRange(string|DateTimeInterface $start, string|DateTimeInterface $end): string
    {
        return $this->time($start) . ' - ' . $this->time($end);
    }

    /** Date + time range, handling activities that span several days. */
    public function schedule(string|DateTimeInterface $start, string|DateTimeInterface $end): string
    {
        $s = self::parse($start);
        $e = self::parse($end);
        if ($s->format('Y-m-d') === $e->format('Y-m-d')) {
            return $this->fullDate($s) . $this->translator->get('common.dates.day_separator') . $this->timeRange($s, $e);
        }

        return $this->date($s) . ' ' . $this->time($s) . ' — ' . $this->date($e) . ' ' . $this->time($e);
    }

    public function dateTime(string|DateTimeInterface $value): string
    {
        return $this->date($value) . $this->translator->get('common.dates.day_separator') . $this->time($value);
    }

    /** "منذ 5 دقائق" / "5 minutes ago" */
    public function relative(string|DateTimeInterface $value, ?DateTimeInterface $now = null): string
    {
        $then = self::parse($value);
        $now ??= new DateTimeImmutable();
        $seconds = $now->getTimestamp() - $then->getTimestamp();

        if ($seconds < 60) {
            return $this->translator->get('common.dates.relative.just_now');
        }
        $units = [['day', 86400], ['hour', 3600], ['minute', 60]];
        foreach ($units as [$unit, $size]) {
            if ($seconds >= $size) {
                $n = intdiv($seconds, $size);
                if ($unit === 'day' && $n > 7) {
                    return $this->date($then);
                }

                return $this->translator->choice('common.dates.relative.' . $unit, $n);
            }
        }

        return $this->date($then);
    }

    /** Value for <input type="datetime-local">. */
    public static function toInput(string|DateTimeInterface|null $value): string
    {
        return $value === null || $value === '' ? '' : self::parse($value)->format('Y-m-d\TH:i');
    }

    /** @return list<string> */
    private function list(string $key): array
    {
        $value = $this->translator->raw('common.dates.' . $key);

        return is_array($value) ? array_values($value) : [];
    }
}

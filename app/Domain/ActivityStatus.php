<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeInterface;

/**
 * Status of an event or volunteer opportunity.
 * Only cancellation is stored (cancelled_at); the rest is derived from the clock so it never goes stale.
 */
enum ActivityStatus: string implements Status
{
    case UPCOMING = 'UPCOMING';
    case ONGOING = 'ONGOING';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public static function resolve(
        DateTimeInterface $start,
        DateTimeInterface $end,
        ?DateTimeInterface $cancelledAt,
        DateTimeInterface $now,
    ): self {
        return match (true) {
            $cancelledAt !== null => self::CANCELLED,
            $now < $start => self::UPCOMING,
            $now <= $end => self::ONGOING,
            default => self::COMPLETED,
        };
    }

    /** The same rule as SQL, for selecting/sorting in queries. */
    public static function sql(string $alias): string
    {
        return "CASE WHEN {$alias}.cancelled_at IS NOT NULL THEN 'CANCELLED'
                     WHEN NOW() < {$alias}.start_datetime THEN 'UPCOMING'
                     WHEN NOW() <= {$alias}.end_datetime THEN 'ONGOING'
                     ELSE 'COMPLETED' END";
    }

    /** SQL condition selecting rows with this status (index-friendly). */
    public function sqlCondition(string $alias): string
    {
        return match ($this) {
            self::CANCELLED => "{$alias}.cancelled_at IS NOT NULL",
            self::UPCOMING => "{$alias}.cancelled_at IS NULL AND NOW() < {$alias}.start_datetime",
            self::ONGOING => "{$alias}.cancelled_at IS NULL AND NOW() BETWEEN {$alias}.start_datetime AND {$alias}.end_datetime",
            self::COMPLETED => "{$alias}.cancelled_at IS NULL AND NOW() > {$alias}.end_datetime",
        };
    }

    /** Students may register while an activity is upcoming (not once it has started). */
    public function acceptsRegistrations(): bool
    {
        return $this === self::UPCOMING;
    }

    public function labelKey(): string
    {
        return 'common.status.activity.' . $this->value;
    }

    public function tone(): string
    {
        return match ($this) {
            self::UPCOMING => 'primary',
            self::ONGOING => 'success',
            self::COMPLETED => 'secondary',
            self::CANCELLED => 'danger',
        };
    }
}

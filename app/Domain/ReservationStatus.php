<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Stored: PENDING, APPROVED, REJECTED, CANCELLED.
 * COMPLETED is derived: an APPROVED reservation whose end time has passed.
 */
enum ReservationStatus: string implements Status
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case COMPLETED = 'COMPLETED';

    public static function sql(string $alias): string
    {
        return "CASE WHEN {$alias}.status = 'APPROVED' AND {$alias}.end_datetime < NOW() THEN 'COMPLETED'
                     ELSE {$alias}.status END";
    }

    public function sqlCondition(string $alias): string
    {
        return match ($this) {
            self::COMPLETED => "{$alias}.status = 'APPROVED' AND {$alias}.end_datetime < NOW()",
            self::APPROVED => "{$alias}.status = 'APPROVED' AND {$alias}.end_datetime >= NOW()",
            default => "{$alias}.status = '{$this->value}'",
        };
    }

    public function labelKey(): string
    {
        return 'common.status.reservation.' . $this->value;
    }

    public function tone(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::APPROVED => 'success',
            self::REJECTED => 'danger',
            self::CANCELLED => 'secondary',
            self::COMPLETED => 'info',
        };
    }
}

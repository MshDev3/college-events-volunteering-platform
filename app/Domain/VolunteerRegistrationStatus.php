<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * REGISTERED → ATTENDED → COMPLETED (hours awarded by an admin).
 * Registration alone never awards hours.
 */
enum VolunteerRegistrationStatus: string implements Status
{
    case REGISTERED = 'REGISTERED';
    case ATTENDED = 'ATTENDED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function labelKey(): string
    {
        return 'common.status.volunteer.' . $this->value;
    }

    public function tone(): string
    {
        return match ($this) {
            self::REGISTERED => 'primary',
            self::ATTENDED => 'info',
            self::COMPLETED => 'success',
            self::CANCELLED => 'secondary',
        };
    }
}

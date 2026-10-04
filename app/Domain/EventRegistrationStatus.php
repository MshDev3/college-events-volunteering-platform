<?php

declare(strict_types=1);

namespace App\Domain;

enum EventRegistrationStatus: string implements Status
{
    case REGISTERED = 'REGISTERED';
    case ATTENDED = 'ATTENDED';
    case CANCELLED = 'CANCELLED';

    public function labelKey(): string
    {
        return 'common.status.registration.' . $this->value;
    }

    public function tone(): string
    {
        return match ($this) {
            self::REGISTERED => 'primary',
            self::ATTENDED => 'success',
            self::CANCELLED => 'secondary',
        };
    }
}

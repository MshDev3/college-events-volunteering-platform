<?php

declare(strict_types=1);

namespace App\Domain;

enum FeedbackStatus: string implements Status
{
    case OPEN = 'OPEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case RESOLVED = 'RESOLVED';
    case CLOSED = 'CLOSED';

    public function labelKey(): string
    {
        return 'common.status.feedback.' . $this->value;
    }

    public function tone(): string
    {
        return match ($this) {
            self::OPEN => 'warning',
            self::IN_PROGRESS => 'primary',
            self::RESOLVED => 'success',
            self::CLOSED => 'secondary',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain;

enum ContactStatus: string implements Status
{
    case UNREAD = 'UNREAD';
    case READ = 'READ';
    case RESOLVED = 'RESOLVED';

    public function labelKey(): string
    {
        return 'common.status.contact.' . $this->value;
    }

    public function tone(): string
    {
        return match ($this) {
            self::UNREAD => 'warning',
            self::READ => 'secondary',
            self::RESOLVED => 'success',
        };
    }
}

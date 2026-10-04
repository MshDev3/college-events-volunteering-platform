<?php

declare(strict_types=1);

namespace App\Domain;

enum Role: string
{
    case STUDENT = 'STUDENT';
    case ADMIN = 'ADMIN';

    /** Primary key in the `roles` lookup table (seeded by migration 001). */
    public function id(): int
    {
        return match ($this) {
            self::STUDENT => 1,
            self::ADMIN => 2,
        };
    }

    public static function fromId(int $id): self
    {
        return match ($id) {
            2 => self::ADMIN,
            default => self::STUDENT,
        };
    }

    public function labelKey(): string
    {
        return 'common.roles.' . $this->value;
    }

    public function homePath(): string
    {
        return $this === self::ADMIN ? '/admin/dashboard' : '/student/dashboard';
    }
}

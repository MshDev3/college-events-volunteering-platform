<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The authenticated user. Deliberately has no password hash:
 * it is loaded with an explicit column list and is safe to pass to views.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $fullName,
        public readonly ?string $studentId,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly Role $role,
        public readonly ?int $departmentId,
        public readonly string $preferredLocale,
        public readonly bool $isActive,
        public readonly int $authVersion,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            fullName: (string) $row['full_name'],
            studentId: $row['student_id'] !== null ? (string) $row['student_id'] : null,
            email: (string) $row['email'],
            phone: $row['phone'] !== null ? (string) $row['phone'] : null,
            role: Role::fromId((int) $row['role_id']),
            departmentId: $row['department_id'] !== null ? (int) $row['department_id'] : null,
            preferredLocale: (string) $row['preferred_locale'],
            isActive: (bool) $row['is_active'],
            authVersion: (int) $row['auth_version'],
            createdAt: (string) $row['created_at'],
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::ADMIN;
    }

    public function isStudent(): bool
    {
        return $this->role === Role::STUDENT;
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->fullName))[0];
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->fullName)) ?: [];
        $letters = array_map(static fn (string $p): string => mb_substr($p, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters));
    }
}

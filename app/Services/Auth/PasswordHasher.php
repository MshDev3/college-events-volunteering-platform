<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Argon2id where available (PHP 8.2 on XAMPP supports it), bcrypt otherwise.
 * Legacy bcrypt hashes still verify and are upgraded on the next successful login.
 */
final class PasswordHasher
{
    private static ?string $dummyHash = null;

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, $this->algorithm());
    }

    public function verify(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(#[\SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm());
    }

    /**
     * Verify against a throwaway hash so "unknown user" takes as long as "wrong password"
     * (prevents discovering registered emails by timing).
     */
    public function burnTime(#[\SensitiveParameter] string $password): void
    {
        self::$dummyHash ??= $this->hash(bin2hex(random_bytes(16)));
        password_verify($password, self::$dummyHash);
    }

    private function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}

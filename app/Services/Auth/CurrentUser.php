<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Domain\User;

/** Holds the authenticated user for the current request (set by LoadAuthenticatedUser). */
final class CurrentUser
{
    private ?User $user = null;

    public function set(?User $user): void
    {
        $this->user = $user;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function id(): ?int
    {
        return $this->user?->id;
    }

    /** For controllers behind the `auth` middleware, where a user is guaranteed. */
    public function require(): User
    {
        if ($this->user === null) {
            throw new \App\Core\Exceptions\HttpException(401);
        }

        return $this->user;
    }
}

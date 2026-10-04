<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Role;
use App\Services\Auth\CurrentUser;

/**
 * role:ADMIN / role:STUDENT. The role comes from the users table, reloaded on every request
 * (never from the login form or a stale session value). Must run after `auth`.
 */
final class RequireRole implements Middleware
{
    public function __construct(private readonly CurrentUser $current)
    {
    }

    public function handle(Request $request, callable $next, string ...$roles): Response
    {
        $user = $this->current->require();
        $allowed = array_map(static fn (string $r): Role => Role::from($r), $roles);
        if (!in_array($user->role, $allowed, true)) {
            throw new HttpException(403);
        }

        return $next($request);
    }
}

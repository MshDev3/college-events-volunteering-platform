<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth\CurrentUser;

/** Login/register pages redirect already-authenticated users to their dashboard. */
final class RequireGuest implements Middleware
{
    public function __construct(private readonly CurrentUser $current)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $user = $this->current->user();
        if ($user !== null) {
            return Response::redirect($user->role->homePath());
        }

        return $next($request);
    }
}

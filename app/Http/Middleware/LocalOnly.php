<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

/**
 * Development-only routes (the mail viewer). They behave as if they did not exist (404) unless
 *   1. APP_ENV=local, and
 *   2. the client is on the loopback interface (127.0.0.0/8 or ::1) — so the machine's LAN IP
 *      never reaches them even when Apache listens on all interfaces.
 * Routes using this middleware must additionally require `auth` + `role:ADMIN`.
 */
final class LocalOnly implements Middleware
{
    public function handle(Request $request, callable $next, string ...$args): Response
    {
        if (config('app.env') !== 'local' || !$request->isLoopback()) {
            throw new HttpException(404);
        }

        return $next($request);
    }
}

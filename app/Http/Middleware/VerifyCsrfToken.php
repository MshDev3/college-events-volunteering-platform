<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Csrf;
use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

/** Every state-changing request must carry the session's CSRF token (form field or header). */
final class VerifyCsrfToken implements Middleware
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        if (!$request->isGet()) {
            $token = $request->input(Csrf::FIELD) ?? $request->header(Csrf::HEADER);
            if (!$this->csrf->verify(is_string($token) ? $token : null)) {
                throw new HttpException(419);
            }
        }

        return $next($request);
    }
}

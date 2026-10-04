<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth\CurrentUser;

/** Guests are redirected to /login?next=<current page>. */
final class RequireAuth implements Middleware
{
    public function __construct(private readonly CurrentUser $current, private readonly Session $session)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        if ($this->current->check()) {
            return $next($request);
        }
        if ($request->wantsJson()) {
            throw new HttpException(401, 'common.errors.http_401');
        }
        $this->session->flash('info', t('auth.login_required'));

        return Response::redirect(url('/login', $request->isGet() ? ['next' => $request->fullPath()] : []));
    }
}

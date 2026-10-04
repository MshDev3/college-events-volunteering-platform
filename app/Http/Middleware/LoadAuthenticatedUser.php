<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Auth\AuthService;
use App\Services\Auth\CurrentUser;
use App\Services\NotificationService;

/** Resolves the user (session or remember-me cookie) for every request and shares it with views. */
final class LoadAuthenticatedUser implements Middleware
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly CurrentUser $current,
        private readonly View $view,
        private readonly NotificationService $notifications,
    ) {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $user = $this->auth->resolve($request);
        $this->current->set($user);

        $this->view->share('currentUser', $user);
        $this->view->share('unreadNotifications', $user !== null ? $this->notifications->unreadCount($user->id) : 0);

        return $next($request);
    }
}

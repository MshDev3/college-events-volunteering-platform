<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\DashboardService;

/** Badge counts for the admin sidebar, shared with the admin layout (admin routes only). */
final class ShareAdminNav implements Middleware
{
    public function __construct(private readonly View $view, private readonly DashboardService $dashboard)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $this->view->share('adminNavCounts', $this->dashboard->sidebarCounts());

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\SettingsService;

/**
 * Supplies the data every layout needs (footer contact details), so templates only render
 * values they were given instead of calling services themselves.
 */
final class ShareLayoutData implements Middleware
{
    public function __construct(private readonly View $view, private readonly SettingsService $settings)
    {
    }

    public function handle(Request $request, callable $next, string ...$args): Response
    {
        $this->view->share('contactInfo', $this->settings->contactInfo());

        return $next($request);
    }
}

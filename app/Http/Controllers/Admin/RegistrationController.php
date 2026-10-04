<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\EventService;

/** Search across all event registrations. */
final class RegistrationController extends Controller
{
    public function __construct(private readonly EventService $events)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status']);
        [$page, $limit] = Paginator::params($request, 20);

        return $this->view('pages/admin/registrations', [
            'paginator' => $this->events->searchRegistrations($filters, $page, $limit),
            'filters' => $filters,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Services\EventService;

final class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard, private readonly EventService $events)
    {
    }

    public function index(Request $request): Response
    {
        return $this->view('pages/admin/dashboard', [
            'stats' => $this->dashboard->adminStats(),
            'pendingReservations' => $this->dashboard->pendingReservations(),
            'latestFeedback' => $this->dashboard->latestFeedback(),
            'upcomingEvents' => $this->events->upcoming(5),
        ]);
    }
}

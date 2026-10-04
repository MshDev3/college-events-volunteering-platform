<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Core\Request;
use App\Core\Response;
use App\Domain\ActivityStatus;
use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Services\EventService;
use App\Services\NotificationService;
use App\Services\VolunteerService;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly EventService $events,
        private readonly VolunteerService $volunteering,
        private readonly NotificationService $notifications,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();

        return $this->view('pages/student/dashboard', [
            'stats' => $this->dashboard->studentStats($user->id),
            'myUpcoming' => $this->events->forStudent($user->id, ActivityStatus::UPCOMING, 1, 4)->items,
            'suggestedEvents' => array_values(array_filter(
                $this->events->upcoming(6, $user->id),
                static fn (array $e): bool => $e['my_status'] === null || $e['my_status'] === 'CANCELLED',
            )),
            'opportunities' => $this->volunteering->upcoming(3, $user->id),
            'activity' => $this->dashboard->studentActivity($user->id),
            'notifications' => $this->notifications->forUser($user->id, 5)['items'],
        ]);
    }
}

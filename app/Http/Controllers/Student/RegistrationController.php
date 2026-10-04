<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Domain\ActivityStatus;
use App\Http\Controllers\Controller;
use App\Services\EventService;

/** /student/registrations — tabs: upcoming, ongoing, completed, cancelled. */
final class RegistrationController extends Controller
{
    public function __construct(private readonly EventService $events)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();
        $tab = ActivityStatus::tryFrom(strtoupper((string) $request->query('tab', 'UPCOMING'))) ?? ActivityStatus::UPCOMING;
        [$page, $limit] = Paginator::params($request);

        return $this->view('pages/student/registrations', [
            'tab' => $tab,
            'counts' => $this->events->studentTabCounts($user->id),
            'paginator' => $this->events->forStudent($user->id, $tab, $page, $limit),
        ]);
    }
}

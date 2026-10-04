<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\VolunteerService;

/** /student/volunteering — my volunteer registrations and awarded hours. */
final class VolunteeringController extends Controller
{
    public function __construct(private readonly VolunteerService $volunteering)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();
        $status = strtoupper((string) $request->query('status', ''));
        $status = in_array($status, ['REGISTERED', 'ATTENDED', 'COMPLETED', 'CANCELLED'], true) ? $status : null;
        [$page, $limit] = Paginator::params($request);

        return $this->view('pages/student/volunteering', [
            'hours' => $this->volunteering->hoursFor($user->id),
            'status' => $status,
            'paginator' => $this->volunteering->forStudent($user->id, $status, $page, $limit),
        ]);
    }
}

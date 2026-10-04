<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\ReservationService;

final class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['status', 'facility', 'from', 'to']);
        [$page, $limit] = Paginator::params($request);

        return $this->view('pages/student/reservations/index', [
            'paginator' => $this->reservations->list(['user' => $this->user()->id] + $filters, $page, $limit),
            'filters' => $filters,
            'facilities' => $this->reservations->facilities(),
        ]);
    }

    public function create(Request $request): Response
    {
        $facilityId = (int) $request->query('facility', 0);

        return $this->view('pages/student/reservations/create', [
            'facilities' => $this->reservations->facilities(),
            'selected' => $facilityId,
            'bookings' => $facilityId > 0 ? $this->reservations->upcomingBookings($facilityId) : [],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->reservations->request($this->user()->id, $request->only([
            'facility_id', 'date', 'start_time', 'end_time', 'purpose', 'expected_attendees', 'notes',
        ]));
        $this->success('reservations.flash.submitted');

        return $this->redirect('/student/reservations');
    }

    public function cancel(Request $request, int $id): Response
    {
        $this->reservations->cancel($this->user()->id, $id, false);
        $this->success('reservations.flash.cancelled');

        return $this->redirect('/student/reservations');
    }
}

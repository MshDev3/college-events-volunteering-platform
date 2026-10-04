<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\ReservationService;

final class FacilityController extends Controller
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function index(Request $request): Response
    {
        return $this->view('pages/public/facilities/index', ['facilities' => $this->reservations->facilities()]);
    }

    public function show(Request $request, int $id): Response
    {
        return $this->view('pages/public/facilities/show', [
            'facility' => $this->reservations->facility($id),
            'bookings' => $this->reservations->upcomingBookings($id),
        ]);
    }
}

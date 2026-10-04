<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\LookupRepository;
use App\Services\DashboardService;
use App\Services\EventService;
use App\Services\ReservationService;
use App\Services\SettingsService;
use App\Services\VolunteerService;

final class HomeController extends Controller
{
    public function __construct(
        private readonly EventService $events,
        private readonly VolunteerService $volunteering,
        private readonly ReservationService $reservations,
        private readonly DashboardService $dashboard,
        private readonly LookupRepository $lookups,
        private readonly SettingsService $settings,
    ) {
    }

    public function index(Request $request): Response
    {
        $userId = $this->optionalUser()?->id;

        return $this->view('pages/public/home', [
            'nextEvents' => $this->events->upcoming(3, $userId),
            'events' => $this->events->upcoming(6, $userId),
            'opportunities' => $this->volunteering->upcoming(3, $userId),
            'categories' => $this->lookups->all('volunteer_categories'),
            'facilities' => $this->reservations->facilities(),
            'stats' => $this->dashboard->publicStats(),
        ]);
    }

    public function about(Request $request): Response
    {
        return $this->view('pages/public/about', [
            'stats' => $this->dashboard->publicStats(),
            'categories' => $this->lookups->all('volunteer_categories'),
            'aboutImage' => $this->settings->aboutImage(),
        ]);
    }
}

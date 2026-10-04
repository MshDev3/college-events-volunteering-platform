<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

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
        $filters = $this->filters($request, ['q', 'status', 'facility', 'from', 'to']);
        [$page, $limit] = Paginator::params($request);
        $paginator = $this->reservations->list($filters, $page, $limit);

        $conflicts = [];
        foreach ($paginator->items as $row) {
            if ($row['status'] === 'PENDING' && $this->reservations->hasConflictWarning((int) $row['id'])) {
                $conflicts[(int) $row['id']] = true;
            }
        }

        return $this->view('pages/admin/reservations', [
            'paginator' => $paginator,
            'filters' => $filters,
            'facilities' => $this->reservations->facilities(false),
            'conflicts' => $conflicts,
        ]);
    }

    public function approve(Request $request, int $id): Response
    {
        $this->reservations->approve($this->user()->id, $id, $request->str('admin_note') ?: null);
        $this->success('reservations.flash.approved');

        return $this->back($request);
    }

    public function reject(Request $request, int $id): Response
    {
        $this->reservations->reject($this->user()->id, $id, $request->str('admin_note'));
        $this->success('reservations.flash.rejected');

        return $this->back($request);
    }

    public function cancel(Request $request, int $id): Response
    {
        $this->reservations->cancel($this->user()->id, $id, true, $request->str('admin_note') ?: null);
        $this->success('reservations.flash.cancelled');

        return $this->back($request);
    }
}

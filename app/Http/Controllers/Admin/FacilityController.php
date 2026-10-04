<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\ReservationService;

final class FacilityController extends Controller
{
    private const FIELDS = ['code', 'name_ar', 'name_en', 'description_ar', 'description_en', 'location_ar', 'location_en', 'capacity', 'is_active'];

    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function index(Request $request): Response
    {
        return $this->view('pages/admin/facilities/index', ['facilities' => $this->reservations->facilities(false)]);
    }

    public function create(Request $request): Response
    {
        return $this->view('pages/admin/facilities/form', ['item' => null]);
    }

    public function store(Request $request): Response
    {
        $this->reservations->saveFacility($request->only(self::FIELDS), null, $request->file('image'));
        $this->success('common.flash.saved');

        return $this->redirect('/admin/facilities');
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->view('pages/admin/facilities/form', ['item' => $this->reservations->facility($id, false)]);
    }

    public function update(Request $request, int $id): Response
    {
        $this->reservations->saveFacility($request->only(self::FIELDS), $id, $request->file('image'), $request->boolean('remove_image'));
        $this->success('common.flash.saved');

        return $this->redirect('/admin/facilities');
    }
}

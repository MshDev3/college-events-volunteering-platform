<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\LookupRepository;
use App\Services\ActivityService;
use App\Services\VolunteerService;

final class VolunteerController extends ActivityController
{
    public function __construct(LookupRepository $lookups, private readonly VolunteerService $volunteering)
    {
        parent::__construct($lookups);
    }

    protected function service(): ActivityService
    {
        return $this->volunteering;
    }

    protected function ns(): string
    {
        return 'volunteering';
    }

    protected function lookup(): array
    {
        return ['volunteer_categories', 'category_id'];
    }

    protected function fields(): array
    {
        return ['title_ar', 'title_en', 'description_ar', 'description_en', 'location_ar', 'location_en',
            'start_datetime', 'end_datetime', 'capacity', 'category_id', 'volunteer_hours'];
    }

    /** Approve completion and award hours. */
    public function complete(Request $request, int $id): Response
    {
        $opportunityId = $this->volunteering->complete($this->user()->id, $id, $request->input('hours'));
        $this->success('volunteering.flash.completed');

        return $this->redirect('/admin/volunteering/' . $opportunityId);
    }

    public function cancelRegistration(Request $request, int $id): Response
    {
        $opportunityId = $this->volunteering->cancelRegistration($this->user()->id, $id);
        $this->success('common.flash.saved');

        return $this->redirect('/admin/volunteering/' . $opportunityId);
    }
}

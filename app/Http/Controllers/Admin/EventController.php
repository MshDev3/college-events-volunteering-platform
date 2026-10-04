<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Repositories\LookupRepository;
use App\Services\ActivityService;
use App\Services\EventService;

final class EventController extends ActivityController
{
    public function __construct(LookupRepository $lookups, private readonly EventService $events)
    {
        parent::__construct($lookups);
    }

    protected function service(): ActivityService
    {
        return $this->events;
    }

    protected function ns(): string
    {
        return 'events';
    }

    protected function lookup(): array
    {
        return ['event_types', 'event_type_id'];
    }

    protected function fields(): array
    {
        return ['title_ar', 'title_en', 'description_ar', 'description_en', 'location_ar', 'location_en',
            'start_datetime', 'end_datetime', 'capacity', 'event_type_id'];
    }
}

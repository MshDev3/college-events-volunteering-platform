<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\LookupRepository;
use App\Services\EventService;

final class EventController extends Controller
{
    public function __construct(private readonly EventService $events, private readonly LookupRepository $lookups)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'type', 'status', 'from', 'to', 'location']);
        [$page, $limit] = Paginator::params($request, 9);
        $paginator = $this->events->list(
            ['lookup' => $filters['type']] + $filters,
            $page,
            $limit,
            $this->optionalUser()?->id,
        );

        if ($request->wantsJson()) {
            // The raw row also holds internal columns; the account id of the administrator who created it is not public.
            return Response::success($paginator->toArray(['created_by']));
        }

        return $this->view('pages/public/events/index', [
            'paginator' => $paginator,
            'filters' => $filters,
            'types' => $this->lookups->options('event_types'),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $user = $this->optionalUser();

        return $this->view('pages/public/events/show', [
            'event' => $this->events->get($id, $user?->id),
        ]);
    }

    public function register(Request $request, int $id): Response
    {
        $this->events->register($this->user()->id, $id);
        $this->success('events.flash.registered');

        return $this->redirect('/events/' . $id);
    }

    public function unregister(Request $request, int $id): Response
    {
        $this->events->unregister($this->user()->id, $id);
        $this->success('events.flash.unregistered');

        return $this->back($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\LookupRepository;
use App\Services\VolunteerService;

final class VolunteerController extends Controller
{
    public function __construct(private readonly VolunteerService $volunteering, private readonly LookupRepository $lookups)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'category', 'status', 'from', 'to', 'location']);
        [$page, $limit] = Paginator::params($request, 9);
        $paginator = $this->volunteering->list(
            ['lookup' => $filters['category']] + $filters,
            $page,
            $limit,
            $this->optionalUser()?->id,
        );

        if ($request->wantsJson()) {
            // The raw row also holds internal columns; the account id of the administrator who created it is not public.
            return Response::success($paginator->toArray(['created_by']));
        }

        return $this->view('pages/public/volunteering/index', [
            'paginator' => $paginator,
            'filters' => $filters,
            'categories' => $this->lookups->options('volunteer_categories'),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        return $this->view('pages/public/volunteering/show', [
            'opportunity' => $this->volunteering->get($id, $this->optionalUser()?->id),
        ]);
    }

    public function register(Request $request, int $id): Response
    {
        $this->volunteering->apply($this->user()->id, $id, $request->only(['motivation']));
        $this->success('volunteering.flash.registered');

        return $this->redirect('/volunteering/' . $id);
    }

    public function unregister(Request $request, int $id): Response
    {
        $this->volunteering->unregister($this->user()->id, $id);
        $this->success('volunteering.flash.unregistered');

        return $this->back($request);
    }
}

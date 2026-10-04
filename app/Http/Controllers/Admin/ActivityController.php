<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\LookupRepository;
use App\Services\ActivityService;

/**
 * CRUD + registrations management shared by /admin/events and /admin/volunteering.
 */
abstract class ActivityController extends Controller
{
    public function __construct(protected readonly LookupRepository $lookups)
    {
    }

    abstract protected function service(): ActivityService;

    /** "events" | "volunteering" — URL segment, view folder and translation namespace. */
    abstract protected function ns(): string;

    /** Lookup table + form field for the type/category select. @return array{0:string,1:string} */
    abstract protected function lookup(): array;

    /** @return list<string> fields accepted from the form */
    abstract protected function fields(): array;

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'lookup', 'status', 'from', 'to']);
        [$page, $limit] = Paginator::params($request);

        return $this->view('pages/admin/activities/index', [
            'ns' => $this->ns(),
            'paginator' => $this->service()->list($filters, $page, $limit, null, true),
            'filters' => $filters,
            'lookupOptions' => $this->lookups->options($this->lookup()[0]),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $id = $this->service()->save($request->only($this->fields()), $this->user()->id, null, $this->image($request));
        $this->success($this->ns() . '.flash.created');

        return $this->redirect('/admin/' . $this->ns() . '/' . $id);
    }

    public function show(Request $request, int $id): Response
    {
        [$page, $limit] = Paginator::params($request, 20);
        $q = trim((string) $request->query('q', ''));

        return $this->view('pages/admin/activities/show', [
            'ns' => $this->ns(),
            'item' => $this->service()->get($id),
            'registrations' => $this->service()->registrations($id, $page, $limit, $q),
            'q' => $q,
        ]);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->form($this->service()->get($id));
    }

    public function update(Request $request, int $id): Response
    {
        $this->service()->save($request->only($this->fields()), $this->user()->id, $id, $this->image($request), $request->boolean('remove_image'));
        $this->success($this->ns() . '.flash.updated');

        return $this->redirect('/admin/' . $this->ns() . '/' . $id);
    }

    public function cancel(Request $request, int $id): Response
    {
        $this->service()->cancel($this->user()->id, $id, $request->str('reason'));
        $this->success($this->ns() . '.flash.cancelled');

        return $this->redirect('/admin/' . $this->ns() . '/' . $id);
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->service()->delete($this->user()->id, $id);
        $this->success($this->ns() . '.flash.deleted');

        return $this->redirect('/admin/' . $this->ns());
    }

    public function attendance(Request $request, int $id): Response
    {
        $activityId = $this->service()->markAttended($id, $request->boolean('attended'));
        $this->success('common.flash.saved');

        return $this->redirect('/admin/' . $this->ns() . '/' . $activityId);
    }

    /** Optional cover image (events and volunteering). @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null */
    private function image(Request $request): ?array
    {
        return $request->file('image');
    }

    /** @param array<string, mixed>|null $item */
    private function form(?array $item): Response
    {
        return $this->view('pages/admin/activities/form', [
            'ns' => $this->ns(),
            'item' => $item,
            'lookupField' => $this->lookup()[1],
            'lookupOptions' => $this->lookups->options($this->lookup()[0]),
        ]);
    }
}

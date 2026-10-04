<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\ContactService;

final class MessageController extends Controller
{
    public function __construct(private readonly ContactService $contact)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status', 'from', 'to']);
        [$page, $limit] = Paginator::params($request);

        return $this->view('pages/admin/messages/index', [
            'paginator' => $this->contact->list($filters, $page, $limit),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        return $this->view('pages/admin/messages/show', ['message' => $this->contact->open($id)]);
    }

    public function status(Request $request, int $id): Response
    {
        $this->contact->setStatus($id, $request->str('status'));
        $this->success('common.flash.saved');

        return $request->str('return') === 'list' ? $this->redirect('/admin/messages') : $this->redirect('/admin/messages/' . $id);
    }
}

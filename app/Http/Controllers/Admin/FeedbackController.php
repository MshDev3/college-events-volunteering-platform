<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\LookupRepository;
use App\Services\FeedbackService;

final class FeedbackController extends Controller
{
    public function __construct(private readonly FeedbackService $feedback, private readonly LookupRepository $lookups)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'type', 'status', 'category', 'from', 'to', 'archived']);
        [$page, $limit] = Paginator::params($request);

        return $this->view('pages/admin/feedback/index', [
            'paginator' => $this->feedback->list($filters, $page, $limit),
            'filters' => $filters,
            'categories' => $this->lookups->options('feedback_categories'),
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        return $this->view('pages/admin/feedback/show', ['item' => $this->feedback->getFor($this->user(), $id)]);
    }

    public function status(Request $request, int $id): Response
    {
        $this->feedback->updateStatus($this->user()->id, $id, $request->str('status'));
        $this->success('common.flash.saved');

        return $this->back($request);
    }

    public function reply(Request $request, int $id): Response
    {
        $this->feedback->reply($this->user()->id, $id, $request->only(['admin_reply', 'status']));
        $this->success('feedback.flash.replied');

        return $this->redirect('/admin/feedback/' . $id);
    }

    public function archive(Request $request, int $id): Response
    {
        $archive = $request->boolean('archive');
        $this->feedback->setArchived($this->user()->id, $id, $archive);
        $this->success($archive ? 'feedback.flash.archived' : 'feedback.flash.unarchived');

        return $this->redirect('/admin/feedback');
    }
}

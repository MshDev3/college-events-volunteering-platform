<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\LookupRepository;
use App\Services\FeedbackService;

/** Suggestions & complaints for students (+ the shared attachment download). */
final class FeedbackController extends Controller
{
    public function __construct(private readonly FeedbackService $feedback, private readonly LookupRepository $lookups)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['type', 'status']);
        [$page, $limit] = Paginator::params($request);

        return $this->view('pages/student/feedback/index', [
            'paginator' => $this->feedback->list(['user' => $this->user()->id] + $filters, $page, $limit),
            'filters' => $filters,
        ]);
    }

    public function create(Request $request): Response
    {
        $type = strtoupper((string) $request->query('type', ''));

        return $this->view('pages/student/feedback/create', [
            'categories' => $this->lookups->options('feedback_categories'),
            'type' => in_array($type, ['SUGGESTION', 'COMPLAINT'], true) ? $type : '',
        ]);
    }

    public function store(Request $request): Response
    {
        $id = $this->feedback->submit(
            $this->user()->id,
            $request->only(['type', 'category_id', 'subject', 'message']),
            $request->file('attachment'),
        );
        $this->success('feedback.flash.submitted');

        return $this->redirect('/student/feedback/' . $id);
    }

    public function show(Request $request, int $id): Response
    {
        return $this->view('pages/student/feedback/show', ['item' => $this->feedback->getFor($this->user(), $id)]);
    }

    /** Owner or admin; streamed as a download so the browser never renders it inline. */
    public function attachment(Request $request, int $id): Response
    {
        $file = $this->feedback->attachmentFor($this->user(), $id);
        $name = str_replace(['"', "\r", "\n"], '', $file['name']);

        return Response::html((string) file_get_contents($file['path']))
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Disposition', 'attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($name))
            ->withHeader('Content-Security-Policy', "default-src 'none'")
            ->withHeader('Cache-Control', 'private, no-store');
    }
}

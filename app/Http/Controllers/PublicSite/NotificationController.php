<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\NotificationService;

final class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $limit] = Paginator::params($request, 15);
        $unreadOnly = $request->query('filter') === 'unread';
        $result = $this->notifications->forUser($this->user()->id, $limit, Paginator::offset($page, $limit), $unreadOnly);

        return $this->view('pages/notifications', [
            'paginator' => new Paginator($result['items'], $result['total'], $page, $limit),
            'unreadOnly' => $unreadOnly,
        ]);
    }

    public function read(Request $request, int $id): Response
    {
        $link = $this->notifications->markRead($this->user()->id, $id);

        // Only follow internal links stored by the app itself.
        return $this->redirect(($link !== null ? Response::localPath($link) : null) ?? '/notifications');
    }

    public function readAll(Request $request): Response
    {
        $this->notifications->markAllRead($this->user()->id);
        $this->success('notifications.flash.all_read');

        return $this->back($request);
    }

    public function unreadCount(Request $request): Response
    {
        return Response::success(['count' => $this->notifications->unreadCount($this->user()->id)]);
    }
}

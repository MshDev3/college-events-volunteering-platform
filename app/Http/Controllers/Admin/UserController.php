<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Core\Exceptions\HttpException;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Services\UserService;

final class UserController extends Controller
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'role', 'status']);
        [$page, $limit] = Paginator::params($request, 15);

        return $this->view('pages/admin/users', [
            'paginator' => $this->users->list($filters, $page, $limit),
            'filters' => $filters,
        ]);
    }

    public function role(Request $request, int $id): Response
    {
        $this->users->changeRole($this->user(), $id, $request->str('role'));
        $this->success('users.flash.role_changed');

        return $this->back($request);
    }

    public function active(Request $request, int $id): Response
    {
        // A request without the flag must not be read as "deactivate".
        $active = $request->explicitBoolean('active') ?? throw new HttpException(422);
        $this->users->setActive($this->user(), $id, $active);
        $this->success($active ? 'users.flash.activated' : 'users.flash.deactivated');

        return $this->back($request);
    }
}

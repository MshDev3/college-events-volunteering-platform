<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\LookupRepository;
use App\Services\Auth\AuthService;
use App\Services\UserService;

final class RegisterController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly AuthService $auth,
        private readonly LookupRepository $lookups,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->view('pages/auth/register', ['departments' => $this->lookups->options('departments')]);
    }

    public function register(Request $request): Response
    {
        $user = $this->users->register($request->only([
            'full_name', 'student_id', 'email', 'phone', 'department_id', 'password', 'password_confirmation',
        ]), $request);
        $this->auth->login($user, false, $request);
        $this->success('auth.flash.registered', ['name' => $user->firstName()]);

        return $this->redirect('/student/dashboard');
    }
}

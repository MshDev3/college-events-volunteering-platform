<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Domain\Role;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;

final class LoginController extends Controller
{
    public function __construct(private readonly AuthService $auth, private readonly Validator $validator)
    {
    }

    public function show(Request $request): Response
    {
        return $this->view('pages/auth/login', [
            'next' => $this->safeNext((string) $request->query('next', '')),
            'role' => in_array($request->query('role'), ['STUDENT', 'ADMIN'], true) ? $request->query('role') : 'STUDENT',
        ]);
    }

    public function login(Request $request): Response
    {
        $data = $this->validator->validate($request->all(), [
            'identifier' => 'required|string|max:190',
            'password' => 'required|string|max:128',
            'role' => 'required|in:STUDENT,ADMIN',
            'remember' => 'boolean',
        ]);

        $user = $this->auth->attempt(
            (string) $data['identifier'],
            (string) $data['password'],
            Role::from((string) $data['role']),
            (bool) $data['remember'],
            $request,
        );

        $next = $this->safeNext((string) $request->input('next', ''));
        // Never send a student into /admin (or an admin into /student) via ?next.
        $allowedNext = $next !== '' && !str_starts_with($next, $user->isAdmin() ? '/student' : '/admin');
        $this->success('auth.flash.welcome', ['name' => $user->firstName()]);

        return $this->redirect($allowedNext ? $next : $user->role->homePath());
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request);
        $this->success('auth.flash.logged_out');

        return $this->redirect('/login');
    }

    /** Only app-relative paths ("/events/3"), never "//evil.com", absolute URLs or control characters. */
    private function safeNext(string $next): string
    {
        return Response::localPath($next) ?? '';
    }
}

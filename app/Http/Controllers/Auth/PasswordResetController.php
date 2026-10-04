<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Http\Controllers\Controller;
use App\Services\Auth\PasswordResetService;

final class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $resets,
        private readonly Validator $validator,
        private readonly Session $session,
    ) {
    }

    public function requestForm(Request $request): Response
    {
        return $this->view('pages/auth/forgot-password', ['sent' => (bool) $this->session->getFlash('reset_link_sent', false)]);
    }

    public function sendLink(Request $request): Response
    {
        $data = $this->validator->validate($request->all(), ['email' => 'required|email']);
        $this->resets->request((string) $data['email'], $request);
        // Same response whether or not the email exists.
        $this->session->flash('reset_link_sent', true);

        return $this->redirect('/forgot-password');
    }

    public function resetForm(Request $request, #[\SensitiveParameter] string $token): Response
    {
        return $this->view('pages/auth/reset-password', [
            'token' => $token,
            'valid' => $this->resets->isValid($token),
        ])->withHeader('Referrer-Policy', 'no-referrer'); // the token is in the URL
    }

    public function reset(Request $request, #[\SensitiveParameter] string $token): Response
    {
        $this->validator->validate($request->all(), [
            'password' => 'required|password|max:128|confirmed',
        ]);
        $this->resets->reset($token, (string) $request->input('password'));
        $this->success('auth.reset.success');

        return $this->redirect('/login');
    }
}

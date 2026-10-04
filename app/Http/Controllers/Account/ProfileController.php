<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Core\Request;
use App\Core\Response;
use App\Http\Controllers\Controller;
use App\Repositories\LookupRepository;
use App\Services\Auth\EmailChangeService;
use App\Services\UserService;

/** /profile — account page for any signed-in user (students and admins). */
final class ProfileController extends Controller
{
    public function __construct(
        private readonly UserService $users,
        private readonly LookupRepository $lookups,
        private readonly EmailChangeService $emailChanges,
    ) {
    }

    public function edit(Request $request): Response
    {
        return $this->view('pages/account/profile', [
            'user' => $this->user(),
            'departments' => $this->lookups->options('departments'),
            'pendingEmail' => $this->emailChanges->pending($this->user()->id),
        ]);
    }

    public function update(Request $request): Response
    {
        $verificationSent = $this->users->updateProfile($this->user(), $request->only([
            'full_name', 'email', 'phone', 'department_id', 'preferred_locale', 'student_id', 'email_password',
        ]));
        $this->success($verificationSent ? 'profile.flash.email_verification_sent' : 'profile.flash.updated');

        return $this->redirect('/profile');
    }

    public function password(Request $request): Response
    {
        $this->users->changePassword(
            $this->user(),
            $request->only(['current_password', 'password', 'password_confirmation']),
            $request,
        );
        $this->success('profile.flash.password_changed');

        return $this->redirect('/profile');
    }

    /** The link from the verification email. Shows a confirm button; a GET never changes anything. */
    public function confirmEmailForm(Request $request, #[\SensitiveParameter] string $token): Response
    {
        return $this->view('pages/account/confirm-email', [
            'token' => $token,
            'newEmail' => $this->emailChanges->newEmailFor($this->user(), $token),
            'currentEmail' => $this->user()->email,
        ])->withHeader('Referrer-Policy', 'no-referrer'); // the token is in the URL
    }

    public function confirmEmail(Request $request, #[\SensitiveParameter] string $token): Response
    {
        $this->emailChanges->confirm($this->user(), $token);
        $this->success('profile.flash.email_changed');

        return $this->redirect('/profile');
    }

    public function cancelEmailChange(Request $request): Response
    {
        $this->emailChanges->cancel($this->user()->id);
        $this->success('profile.flash.email_change_cancelled');

        return $this->redirect('/profile');
    }
}

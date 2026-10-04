<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\ValidationException;
use App\Core\Config;
use App\Core\Paginator;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\Role;
use App\Domain\User;
use App\Repositories\LookupRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;
use App\Services\Auth\EmailChangeService;
use App\Services\Auth\PasswordHasher;
use App\Services\Auth\RememberMeService;

final class UserService
{
    /** Saudi TVTC trainee numbers are numeric; allow 6–12 digits to cover other colleges. */
    public const STUDENT_ID_RULE = 'regex:/^\d{6,12}$/';

    public function __construct(
        private readonly UserRepository $users,
        private readonly Validator $validator,
        private readonly PasswordHasher $hasher,
        private readonly LookupRepository $lookups,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
        private readonly RememberMeService $remember,
        private readonly Session $session,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly EmailChangeService $emailChanges,
    ) {
    }

    /**
     * Self-registration always creates a STUDENT.
     * Throttled per client IP: every submission counts toward an attempts window (stops scripted
     * email/student-ID probing), and created accounts count toward a separate hourly cap.
     * @param array<string, mixed> $input
     */
    public function register(array $input, Request $request): User
    {
        $accountKey = 'register-account:' . $request->ip();
        $maxAccounts = (int) $this->config->get('auth.register_max_accounts_per_ip', 30);
        if (!$this->limiter->attempt('register-attempt:' . $request->ip(), (int) $this->config->get('auth.register_max_attempts_per_ip', 60), (int) $this->config->get('auth.register_attempt_decay_seconds', 900))
            || $this->limiter->tooManyAttempts($accountKey, $maxAccounts)) {
            throw ValidationException::withMessage('email', t('auth.errors.register_throttled'));
        }

        $data = $this->validator->validate($input, [
            'full_name' => 'required|string|min:3|max:120',
            'student_id' => ['required', self::STUDENT_ID_RULE, 'unique:users,student_id'],
            'email' => 'required|email|max:190|unique:users,email',
            'phone' => 'required|phone',
            'department_id' => 'nullable|integer|in:' . $this->lookups->idList('departments'),
            'password' => 'required|password|max:128|confirmed|different:email',
        ]);

        // Reserve the account slot atomically before creating it (the check above is only a fast path).
        if (!$this->limiter->attempt($accountKey, $maxAccounts, (int) $this->config->get('auth.register_account_decay_seconds', 3600))) {
            throw ValidationException::withMessage('email', t('auth.errors.register_throttled'));
        }
        try {
            $id = $this->users->create([
                'full_name' => $data['full_name'],
                'student_id' => $data['student_id'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'department_id' => $data['department_id'],
                'preferred_locale' => locale(),
            ], $this->hasher->hash((string) $data['password']));
        } catch (\Throwable $e) {
            $this->limiter->release($accountKey);
            throw $e;
        }

        $this->notifications->notify($id, NotificationService::WELCOME, [], '/student/dashboard');

        return $this->users->find($id) ?? throw new \RuntimeException('User not found after insert.');
    }

    /**
     * Creates the FIRST administrator (CLI only: `php bin/console admin:create`).
     * Refuses when an active admin already exists — further admins are granted from
     * Admin › Users by an existing admin, so this can never be used to take over a running system.
     * The email must be new: an existing (student) account is never promoted here.
     * @param array{full_name?:mixed, email?:mixed, password?:mixed, password_confirmation?:mixed} $input
     */
    public function createInitialAdmin(array $input): User
    {
        if ($this->users->countActiveAdmins() > 0) {
            throw new BusinessRuleException('users.errors.admin_exists');
        }
        $data = $this->validator->validate($input, [
            'full_name' => 'required|string|min:3|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|password|max:128|confirmed|different:email',
        ]);

        $id = $this->users->create([
            'full_name' => $data['full_name'],
            'student_id' => null,
            'email' => $data['email'],
            'phone' => null,
            'department_id' => null,
            'preferred_locale' => (string) $this->config->get('app.default_locale', 'ar'),
        ], $this->hasher->hash((string) $data['password']), Role::ADMIN);
        $this->audit->log(null, 'user.initial_admin_created', 'user', $id);

        return $this->users->find($id) ?? throw new \RuntimeException('User not found after insert.');
    }

    /**
     * Saves the profile. A new login email is NOT applied here: it needs the current password and is
     * then confirmed by a link sent to the new address (EmailChangeService).
     * @param array<string, mixed> $input
     * @return bool true when a confirmation link was sent to a new email address
     */
    public function updateProfile(User $user, array $input): bool
    {
        $rules = [
            'full_name' => 'required|string|min:3|max:120',
            'email' => 'required|email|max:190|unique:users,email,' . $user->id,
            'phone' => ($user->isStudent() ? 'required' : 'nullable') . '|phone',
            'department_id' => 'nullable|integer|in:' . $this->lookups->idList('departments'),
            'preferred_locale' => 'required|in:ar,en',
        ];
        // Student ID can be set once (legacy accounts have none); after that only an admin changes it.
        if ($user->studentId === null && $user->isStudent()) {
            $rules['student_id'] = ['required', self::STUDENT_ID_RULE, 'unique:users,student_id'];
        }
        $data = $this->validator->validate($input, $rules);

        // Changing the login email requires the current password, then proof of the new address.
        $newEmail = (string) $data['email'];
        $emailChanged = $newEmail !== $user->email;
        if ($emailChanged) {
            $this->verifyCurrentPassword($user, (string) ($input['email_password'] ?? ''), 'email_password', 'profile.errors.password_required_for_email');
        }
        unset($data['email']);

        $this->users->updateProfile($user->id, $data);
        if ($emailChanged) {
            $this->emailChanges->request($user, $newEmail);
        }

        return $emailChanged;
    }

    /** "mohammed@college.edu" → "m*******@college.edu": enough for the owner to recognise, not a full address. */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1) . str_repeat('*', max(3, mb_strlen($local) - 1)) . '@' . $domain;
    }

    /** @param array<string, mixed> $input */
    public function changePassword(User $user, array $input, Request $request): void
    {
        $data = $this->validator->validate($input, [
            'current_password' => 'required|string',
            'password' => 'required|password|max:128|confirmed|different:current_password',
        ]);
        $this->verifyCurrentPassword($user, (string) $data['current_password'], 'current_password', 'profile.errors.wrong_password');

        $this->users->changePassword($user->id, $this->hasher->hash((string) $data['password']));
        $this->remember->revokeAll($user->id);
        // auth_version was bumped: keep THIS session valid, all others are now logged out.
        $this->session->regenerate();
        $this->session->put(AuthService::SESSION_VERSION, $user->authVersion + 1);
        $this->notifications->notify($user->id, NotificationService::PASSWORD_CHANGED);
    }

    /**
     * Current-password check for sensitive profile changes, throttled per account (5 wrong passwords
     * per 15 minutes) so a hijacked session cannot be used to guess the password. A correct password
     * refunds its attempt.
     */
    private function verifyCurrentPassword(User $user, #[\SensitiveParameter] string $password, string $field, string $errorKey): void
    {
        $key = 'pw-check:' . $user->id;
        if (!$this->limiter->attempt($key, 5, 900)) {
            $minutes = max(1, (int) ceil($this->limiter->availableIn($key) / 60));
            throw ValidationException::withMessage($field, t('profile.errors.too_many_password_checks', ['minutes' => $minutes]));
        }
        if ($password === '' || !$this->hasher->verify($password, (string) $this->users->passwordHash($user->id))) {
            throw ValidationException::withMessage($field, t($errorKey));
        }
        $this->limiter->release($key);
    }

    /** @param array<string, mixed> $filters */
    public function list(array $filters, int $page, int $limit): Paginator
    {
        $result = $this->users->paginate($filters, $limit, Paginator::offset($page, $limit));

        return new Paginator($result['items'], $result['total'], $page, $limit);
    }

    /**
     * Role changes are protected:
     *  - an admin can never change their own role (no accidental self-lockout);
     *  - the last active admin can never be demoted.
     */
    public function changeRole(User $actor, int $targetId, string $roleValue): void
    {
        $role = Role::tryFrom($roleValue) ?? throw new HttpException(422);

        // The admin set is locked before the target is read and the admins are counted, so two admins acting at the
        // same moment queue up and the second one sees the first one's result (see UserRepository::lockAdminSetAndCount()).
        $this->users->transaction(function () use ($actor, $targetId, $role): void {
            $activeAdmins = $role !== Role::ADMIN ? $this->users->lockAdminSetAndCount() : null;
            $target = $this->users->find($targetId) ?? throw new HttpException(404);

            if ($target->id === $actor->id) {
                throw new BusinessRuleException('users.errors.self_role');
            }
            if ($target->role === $role) {
                return;
            }
            if ($target->isAdmin() && $role !== Role::ADMIN && $target->isActive && $activeAdmins <= 1) {
                throw new BusinessRuleException('users.errors.last_admin');
            }

            $this->users->setRole($target->id, $role);
            $this->audit->log($actor->id, 'user.role_changed', 'user', $target->id, ['from' => $target->role->value, 'to' => $role->value]);
        });
    }

    public function setActive(User $actor, int $targetId, bool $active): void
    {
        $this->users->transaction(function () use ($actor, $targetId, $active): void {
            $activeAdmins = !$active ? $this->users->lockAdminSetAndCount() : null;
            $target = $this->users->find($targetId) ?? throw new HttpException(404);
            if ($target->id === $actor->id) {
                throw new BusinessRuleException('users.errors.self_deactivate');
            }
            if (!$active && $target->isAdmin() && $target->isActive && $activeAdmins <= 1) {
                throw new BusinessRuleException('users.errors.last_admin');
            }
            $this->users->setActive($target->id, $active);
            if (!$active) {
                $this->remember->revokeAll($target->id);
            }
            $this->audit->log($actor->id, $active ? 'user.activated' : 'user.deactivated', 'user', $target->id);
        });
    }

    public function setLocale(User $user, string $locale): void
    {
        $this->users->setLocale($user->id, $locale);
    }

    public function find(int $id): User
    {
        return $this->users->find($id) ?? throw new HttpException(404);
    }
}

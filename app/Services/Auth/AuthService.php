<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Exceptions\ValidationException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Domain\Role;
use App\Domain\User;
use App\Repositories\UserRepository;

/**
 * Session authentication.
 *
 * The role chosen on the login form is only a claim: the login succeeds only if it
 * equals the role stored in the database. It never grants privileges by itself.
 */
final class AuthService
{
    public const SESSION_USER = 'auth.user_id';
    public const SESSION_VERSION = 'auth.version';
    public const SESSION_LOGIN_AT = 'auth.login_at';
    public const SESSION_LAST_SEEN = 'auth.last_seen';

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly RememberMeService $remember,
        private readonly RateLimiter $limiter,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly CurrentUser $current,
        private readonly Config $config,
    ) {
    }

    /**
     * @throws ValidationException with a translated message on any failure
     */
    public function attempt(string $identifier, #[\SensitiveParameter] string $password, Role $requestedRole, bool $remember, Request $request): User
    {
        $identifier = trim($identifier);
        $normalized = mb_strtolower($identifier);
        $limits = [
            // key => [max, window seconds]
            'login:' . $normalized . '|' . $request->ip() => [(int) $this->config->get('auth.login_max_attempts', 5), (int) $this->config->get('auth.login_decay_seconds', 900)],
            'login-ip:' . $request->ip() => [(int) $this->config->get('auth.login_max_attempts_per_ip', 30), (int) $this->config->get('auth.login_decay_seconds', 900)],
            'login-account:' . $normalized => [(int) $this->config->get('auth.login_max_attempts_per_account', 20), (int) $this->config->get('auth.login_account_decay_seconds', 3600)],
        ];
        $accountKey = array_key_first($limits);

        // Count first (atomically), then decide: parallel requests cannot all pass a check-then-increment gap.
        // Every limit is counted, even when an earlier one already failed.
        $exceeded = [];
        foreach ($limits as $key => [$max, $window]) {
            if (!$this->limiter->attempt($key, $max, $window)) {
                $exceeded[] = $key;
            }
        }
        if ($exceeded !== []) {
            $seconds = max(array_map(fn (string $key): int => $this->limiter->availableIn($key), $exceeded));
            throw ValidationException::withMessage('identifier', t('auth.errors.throttled', ['minutes' => max(1, (int) ceil($seconds / 60))]));
        }

        $record = $this->users->findForLogin($identifier);

        // The keys above follow the text the client typed, but the database matches accounts case- and
        // accent-insensitively ("user@x" and "u\u{0301}ser@x" are one account). Count again per account, so
        // another spelling cannot start a fresh set of attempts. Unknown identifiers go through the same
        // statements, so the work is the same for both and nothing reveals which accounts exist.
        $subject = $record === null ? 'unknown:' . $normalized : (string) $record['user']->id;
        $accountLimits = [
            'login-user-ip:' . $subject . '|' . $request->ip() => $limits[$accountKey],
            'login-user:' . $subject => $limits['login-account:' . $normalized],
        ];
        $accountExceeded = false;
        foreach ($accountLimits as $key => [$max, $window]) {
            $accountExceeded = !$this->limiter->attempt($key, $max, $window) || $accountExceeded;
        }

        if ($record === null || $accountExceeded) {
            $this->hasher->burnTime($password);
        }

        if ($record === null || $accountExceeded || !$this->hasher->verify($password, $record['password_hash'])) {
            // The attempt stays counted. One generic message: never reveal whether the email/ID exists,
            // nor (when $accountExceeded) that the account has used up its attempts under other spellings.
            throw ValidationException::withMessage('identifier', t('auth.errors.failed'));
        }

        // Correct password: this attempt was not a guess, so it does not count against any limit.
        foreach ([...array_keys($limits), ...array_keys($accountLimits)] as $key) {
            $this->limiter->release($key);
        }

        $user = $record['user'];

        if (!$user->isActive) {
            throw ValidationException::withMessage('identifier', t('auth.errors.inactive'));
        }

        // The password is correct, so telling the owner which portal to use leaks nothing.
        if ($user->role !== $requestedRole) {
            throw ValidationException::withMessage('role', t('auth.errors.role_mismatch_' . strtolower($requestedRole->value)));
        }

        $this->limiter->clear($accountKey);
        $this->limiter->clear(array_key_first($accountLimits));

        if ($this->hasher->needsRehash($record['password_hash'])) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->login($user, $remember, $request);

        return $user;
    }

    /** Establish an authenticated session (also used after registration and remember-me). */
    public function login(User $user, bool $remember, Request $request): void
    {
        $this->session->regenerate();           // prevents session fixation
        $this->csrf->rotate();
        $this->session->put(self::SESSION_USER, $user->id);
        $this->session->put(self::SESSION_VERSION, $user->authVersion);
        $this->session->put(self::SESSION_LOGIN_AT, time());
        $this->session->put(self::SESSION_LAST_SEEN, time());
        $this->users->touchLogin($user->id);
        $this->current->set($user);

        if ($remember) {
            $this->remember->issue($user->id, $request);
        }
    }

    public function logout(Request $request): void
    {
        $this->remember->forgetCurrent($request);
        $this->session->invalidate();
        $this->csrf->rotate();
        $this->current->set(null);
    }

    /**
     * Resolve the user for this request from the session (with idle/absolute timeouts and
     * auth_version check), falling back to the remember-me cookie.
     */
    public function resolve(Request $request): ?User
    {
        $userId = $this->session->get(self::SESSION_USER);
        if (is_int($userId)) {
            $user = $this->users->find($userId);
            $now = time();
            $idle = (int) $this->config->get('session.idle_minutes', 120) * 60;
            $absolute = (int) $this->config->get('session.absolute_minutes', 720) * 60;
            $valid = $user !== null
                && $user->isActive
                && $user->authVersion === (int) $this->session->get(self::SESSION_VERSION)
                && $now - (int) $this->session->get(self::SESSION_LAST_SEEN, 0) <= $idle
                && $now - (int) $this->session->get(self::SESSION_LOGIN_AT, 0) <= $absolute;

            if ($valid) {
                $this->session->put(self::SESSION_LAST_SEEN, $now);

                return $user;
            }

            // Expired or revoked session: drop it (a valid remember cookie may still restore it below).
            $this->session->invalidate();
        }

        $rememberedId = $this->remember->consume($request);
        if ($rememberedId !== null) {
            $user = $this->users->find($rememberedId);
            if ($user !== null && $user->isActive) {
                $this->login($user, false, $request);

                return $user;
            }
            $this->remember->revokeAll($rememberedId);
        }

        return null;
    }
}

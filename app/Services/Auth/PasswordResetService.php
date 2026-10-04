<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Repositories\UserRepository;
use App\Services\EmailService;
use App\Services\MailQueue;
use App\Services\NotificationService;

/**
 * Forgot / reset password.
 *
 *  - Token: 32 random bytes (hex) sent by email; only sha256(token) is stored.
 *  - Valid for PASSWORD_RESET_MINUTES (default 60), single use, older tokens deleted on new request.
 *  - The response never reveals whether an email is registered.
 *  - A successful reset bumps auth_version (ends other sessions) and revokes remember-me tokens.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly RememberMeService $remember,
        private readonly RateLimiter $limiter,
        private readonly EmailService $email,
        private readonly NotificationService $notifications,
        private readonly Config $config,
        private readonly MailQueue $queue,
    ) {
    }

    public function request(string $email, Request $request): void
    {
        $decay = (int) $this->config->get('auth.reset_decay_seconds', 3600);
        if (!$this->limiter->attempt('reset-ip:' . $request->ip(), (int) $this->config->get('auth.reset_max_per_ip', 10), $decay)) {
            throw ValidationException::withMessage('email', t('auth.errors.too_many_requests'));
        }
        if (!$this->limiter->attempt('reset-email:' . mb_strtolower($email), (int) $this->config->get('auth.reset_max_per_email', 3), $decay)) {
            return; // silently: same response as success, no enumeration
        }

        $user = $this->users->findByEmail($email);
        // The limit above follows the text typed, but the database matches addresses case- and accent-insensitively:
        // count per account as well, so another spelling cannot flood one inbox with reset emails.
        // Unknown addresses run the same statement, so both cases do the same work.
        $accountKey = $user !== null ? 'reset-user:' . $user->id : 'reset-unknown:' . mb_strtolower($email);
        if (!$this->limiter->attempt($accountKey, (int) $this->config->get('auth.reset_max_per_email', 3), $decay)) {
            return;
        }
        if ($user === null || !$user->isActive) {
            return;
        }

        // Only a queue entry (one INSERT) happens here; the token and the email are produced after the
        // response is sent, so a registered address is not measurably slower than an unknown one.
        $this->queue->push(MailQueue::PASSWORD_RESET, $user->id, $request->ip());
    }

    /**
     * Create a fresh token and email the link (run from the mail queue).
     * @return bool true when the job is finished: sent, or no longer applicable (inactive user, stale request)
     */
    public function sendResetLink(int $userId, ?string $ip, string $requestedAt): bool
    {
        $user = $this->users->find($userId);
        $minutes = (int) $this->config->get('auth.reset_minutes', 60);
        if ($user === null || !$user->isActive || strtotime($requestedAt) < time() - $minutes * 60) {
            return true;
        }

        // Housekeeping: drop expired/used tokens of all users whenever a new reset is issued.
        $this->purgeExpired();

        $token = bin2hex(random_bytes(32));
        $this->db->transaction(function (Database $db) use ($user, $token, $minutes, $ip): void {
            $db->query('DELETE FROM password_reset_tokens WHERE user_id = ?', [$user->id]);
            $db->query(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, requested_ip)
                 VALUES (?, ?, NOW() + INTERVAL ? MINUTE, ?)',
                [$user->id, hash('sha256', $token), $minutes, $ip],
            );
        });

        return $this->email->send($user->email, $user->preferredLocale, 'password-reset', 'emails.password_reset.subject', [
            'name' => $user->fullName,
            'link' => absolute_url('/reset-password/' . $token),
            'minutes' => $minutes,
        ]);
    }

    public function isValid(#[\SensitiveParameter] string $token): bool
    {
        return $this->findUserId($token) !== null;
    }

    /** @throws ValidationException when the token is invalid/expired */
    public function reset(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $newPassword): int
    {
        $hash = $this->hasher->hash($newPassword);

        $userId = $this->db->transaction(function (Database $db) use ($token, $hash): int {
            $row = $db->fetch(
                'SELECT id, user_id FROM password_reset_tokens
                 WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE',
                [hash('sha256', $token)],
            );
            if ($row === null) {
                throw ValidationException::withMessage('password', t('auth.reset.invalid_link'));
            }
            $userId = (int) $row['user_id'];
            $this->users->changePassword($userId, $hash);
            $db->query('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);
            $db->query('DELETE FROM password_reset_tokens WHERE user_id = ? AND id <> ?', [$userId, (int) $row['id']]);

            return $userId;
        });

        $this->remember->revokeAll($userId);
        $this->notifications->notify($userId, NotificationService::PASSWORD_CHANGED);

        return $userId;
    }

    public function purgeExpired(): int
    {
        return $this->db->query('DELETE FROM password_reset_tokens WHERE expires_at <= NOW() OR used_at IS NOT NULL')->rowCount();
    }

    private function findUserId(#[\SensitiveParameter] string $token): ?int
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $id = $this->db->value(
            'SELECT user_id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()',
            [hash('sha256', $token)],
        );

        return $id === null ? null : (int) $id;
    }
}

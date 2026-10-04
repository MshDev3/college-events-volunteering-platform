<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ValidationException;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Domain\User;
use App\Repositories\UserRepository;
use App\Services\AuditLogger;
use App\Services\EmailService;
use App\Services\UserService;

/**
 * Changing the login email requires proof that the user controls the NEW address.
 *
 *  1. request(): after the current password was checked, the new address is stored in
 *     `email_change_requests` with sha256(token) and a link is emailed to that address.
 *     users.email is not touched. One pending request per user; a new one replaces it.
 *  2. confirm(): the owner opens the link while signed in to the same account and confirms with a
 *     POST (so mail scanners that prefetch links cannot confirm it). Only then does the email change,
 *     every other session and remembered device end, and the OLD address is told.
 *
 * Password change/reset and deactivation delete a pending request (UserRepository).
 */
final class EmailChangeService
{
    /** Verification emails per account per hour (the site must not become a way to spam arbitrary addresses). */
    public const MAX_REQUESTS_PER_HOUR = 3;

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly EmailService $email,
        private readonly RememberMeService $remember,
        private readonly RateLimiter $limiter,
        private readonly Session $session,
        private readonly AuditLogger $audit,
        private readonly Config $config,
    ) {
    }

    /** Store the pending change and email the confirmation link to the new address. */
    public function request(User $user, string $newEmail): void
    {
        if (!$this->limiter->attempt('email-change:' . $user->id, self::MAX_REQUESTS_PER_HOUR, 3600)) {
            throw ValidationException::withMessage('email', t('profile.errors.too_many_email_changes'));
        }

        $token = bin2hex(random_bytes(32));
        $minutes = $this->minutes();
        $this->db->transaction(function (Database $db) use ($user, $newEmail, $token, $minutes): void {
            $db->query('DELETE FROM email_change_requests WHERE user_id = ?', [$user->id]);
            $db->query(
                'INSERT INTO email_change_requests (user_id, new_email, token_hash, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ? MINUTE)',
                [$user->id, mb_strtolower($newEmail), hash('sha256', $token), $minutes],
            );
        });

        $sent = $this->email->send($newEmail, $user->preferredLocale, 'verify-email', 'emails.verify_email.subject', [
            'name' => $user->fullName,
            'link' => absolute_url('/profile/email/confirm/' . $token),
            'minutes' => $minutes,
        ]);
        if (!$sent) {
            $this->cancel($user->id);
            throw ValidationException::withMessage('email', t('profile.errors.verification_not_sent'));
        }
        $this->audit->log($user->id, 'user.email_change_requested', 'user', $user->id);
    }

    /** The user's pending, unexpired request. @return array{new_email:string, expires_at:string}|null */
    public function pending(int $userId): ?array
    {
        $row = $this->db->fetch(
            'SELECT new_email, expires_at FROM email_change_requests WHERE user_id = ? AND expires_at > NOW()',
            [$userId],
        );

        return $row === null ? null : ['new_email' => (string) $row['new_email'], 'expires_at' => (string) $row['expires_at']];
    }

    /** The new address a valid link would switch to, or null (unknown, expired, used, or another user's link). */
    public function newEmailFor(User $user, #[\SensitiveParameter] string $token): ?string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $email = $this->db->value(
            'SELECT new_email FROM email_change_requests WHERE token_hash = ? AND user_id = ? AND expires_at > NOW()',
            [hash('sha256', $token), $user->id],
        );

        return $email === null ? null : (string) $email;
    }

    /** @throws BusinessRuleException when the link is invalid/expired/used, or the address was taken meanwhile */
    public function confirm(User $user, #[\SensitiveParameter] string $token): string
    {
        // Returns the new address, '' when the link is not valid, or null when the address was taken
        // (the used request is still deleted, so the transaction commits in that case).
        $newEmail = $this->db->transaction(function (Database $db) use ($user, $token): ?string {
            $row = preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? $db->fetch(
                'SELECT id, new_email FROM email_change_requests WHERE token_hash = ? AND user_id = ? AND expires_at > NOW() FOR UPDATE',
                [hash('sha256', $token), $user->id],
            ) : null;
            if ($row === null) {
                return '';
            }
            $db->query('DELETE FROM email_change_requests WHERE id = ?', [(int) $row['id']]);
            // The address may have been registered by someone else since the request was made.
            if ($db->value('SELECT id FROM users WHERE email = ? AND id <> ?', [(string) $row['new_email'], $user->id]) !== null) {
                return null;
            }
            $this->users->changeEmail($user->id, (string) $row['new_email']);

            return (string) $row['new_email'];
        });
        if ($newEmail === '') {
            throw new BusinessRuleException('profile.email_change.invalid');
        }
        if ($newEmail === null) {
            throw new BusinessRuleException('profile.errors.email_taken');
        }

        // The login identity changed: end every other session and remembered device (this one stays
        // signed in), and tell the OLD address, so a hijacked session can't quietly take the account over.
        $this->remember->revokeAll($user->id);
        $this->session->regenerate();
        $this->session->put(AuthService::SESSION_VERSION, (int) $this->db->value('SELECT auth_version FROM users WHERE id = ?', [$user->id]));
        $this->audit->log($user->id, 'user.email_changed', 'user', $user->id);
        $this->email->send($user->email, $user->preferredLocale, 'email-changed', 'emails.email_changed.subject', [
            'name' => $user->fullName,
            'new_email' => UserService::maskEmail($newEmail),
        ]);

        return $newEmail;
    }

    public function cancel(int $userId): void
    {
        $this->db->query('DELETE FROM email_change_requests WHERE user_id = ?', [$userId]);
    }

    public function purgeExpired(): int
    {
        return $this->db->query('DELETE FROM email_change_requests WHERE expires_at <= NOW()')->rowCount();
    }

    public function minutes(): int
    {
        return (int) $this->config->get('auth.email_change_minutes', 60);
    }
}

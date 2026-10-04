<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\CookieJar;
use App\Core\Database;
use App\Core\Request;

/**
 * "Remember me" using split selector/validator tokens.
 *
 *  - Cookie:  tvtc_remember = <selector>:<validator>   (HttpOnly, SameSite=Lax, Secure on HTTPS)
 *  - DB:      selector + sha256(validator)             (a DB leak cannot be replayed)
 *  - Each use rotates the validator; a known selector with a wrong validator means the
 *    cookie was stolen and replayed, so every remember token of that user is revoked
 *    (except the just-rotated validator within GRACE_SECONDS: parallel requests of one browser).
 *  - Password change/reset and logout revoke tokens.
 */
final class RememberMeService
{
    /** How long the previous validator stays valid after a rotation. */
    public const GRACE_SECONDS = 30;

    public function __construct(
        private readonly Database $db,
        private readonly CookieJar $cookies,
        private readonly Config $config,
    ) {
    }

    public function issue(int $userId, Request $request): void
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $days = (int) $this->config->get('auth.remember_days', 30);

        // Housekeeping without a background job: logins are frequent, the delete uses the expires_at index.
        $this->purgeExpired();

        $this->db->query(
            'INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, user_agent, expires_at)
             VALUES (?, ?, ?, ?, NOW() + INTERVAL ? DAY)',
            [$userId, $selector, hash('sha256', $validator), $request->userAgent(), $days],
        );
        $this->cookies->queue($this->cookieName(), $selector . ':' . $validator, time() + $days * 86400, $request->cookieSecure());
    }

    /**
     * Validate the cookie and return the user id (rotating the token), or null.
     *
     * Runs under a row lock, so simultaneous requests with the same cookie are handled one after
     * the other. The first rotates the validator; the others present the previous validator within
     * GRACE_SECONDS and are accepted without rotating again (the browser keeps the cookie from the
     * rotating response). Any other known-selector/wrong-validator combination is a replayed stolen
     * cookie and revokes every token of that user.
     */
    public function consume(Request $request): ?int
    {
        $parsed = $this->parseCookie($request);
        if ($parsed === null) {
            return null;
        }
        [$selector, $validator] = $parsed;

        [$outcome, $userId, $newValidator, $expires] = $this->db->transaction(function (Database $db) use ($selector, $validator): array {
            $row = $db->fetch(
                'SELECT id, user_id, validator_hash, previous_hash, expires_at > NOW() AS valid,
                        (rotated_at IS NOT NULL AND rotated_at > NOW() - INTERVAL ? SECOND) AS in_grace
                 FROM auth_remember_tokens WHERE selector = ? FOR UPDATE',
                [self::GRACE_SECONDS, $selector],
            );
            if ($row === null) {
                return ['forget', null, null, null];
            }
            $hash = hash('sha256', $validator);
            $current = hash_equals((string) $row['validator_hash'], $hash);
            $recent = !$current && (bool) $row['in_grace'] && $row['previous_hash'] !== null && hash_equals((string) $row['previous_hash'], $hash);
            if (!$current && !$recent) {
                // Selector known but secret wrong → the token was stolen/replayed. Revoke everything.
                $this->revokeAll((int) $row['user_id']);

                return ['forget', null, null, null];
            }
            if (!(bool) $row['valid']) {
                $db->query('DELETE FROM auth_remember_tokens WHERE id = ?', [(int) $row['id']]);

                return ['forget', null, null, null];
            }
            if ($recent) {
                // A parallel request with the cookie that was just rotated: accept, don't rotate again.
                $db->query('UPDATE auth_remember_tokens SET last_used_at = NOW() WHERE id = ?', [(int) $row['id']]);

                return ['keep', (int) $row['user_id'], null, null];
            }

            // Rotate: new validator for the same selector; expiry stays (absolute 30-day limit).
            $newValidator = bin2hex(random_bytes(32));
            $db->query(
                'UPDATE auth_remember_tokens
                 SET previous_hash = validator_hash, validator_hash = ?, rotated_at = NOW(), last_used_at = NOW()
                 WHERE id = ?',
                [hash('sha256', $newValidator), (int) $row['id']],
            );
            $expires = (int) $db->value('SELECT UNIX_TIMESTAMP(expires_at) FROM auth_remember_tokens WHERE id = ?', [(int) $row['id']]);

            return ['rotate', (int) $row['user_id'], $newValidator, $expires];
        });

        if ($outcome === 'forget') {
            $this->cookies->forget($this->cookieName(), $request->cookieSecure());
        } elseif ($outcome === 'rotate') {
            $this->cookies->queue($this->cookieName(), $selector . ':' . $newValidator, $expires, $request->cookieSecure());
        }

        return $userId;
    }

    /** Remove the token belonging to this browser (on logout). */
    public function forgetCurrent(Request $request): void
    {
        $parsed = $this->parseCookie($request);
        if ($parsed !== null) {
            $this->db->query('DELETE FROM auth_remember_tokens WHERE selector = ?', [$parsed[0]]);
        }
        if ($request->cookie($this->cookieName()) !== null) {
            $this->cookies->forget($this->cookieName(), $request->cookieSecure());
        }
    }

    public function revokeAll(int $userId): void
    {
        $this->db->query('DELETE FROM auth_remember_tokens WHERE user_id = ?', [$userId]);
    }

    public function purgeExpired(): int
    {
        return $this->db->query('DELETE FROM auth_remember_tokens WHERE expires_at <= NOW()')->rowCount();
    }

    public function cookieName(): string
    {
        return (string) $this->config->get('auth.remember_cookie', 'remember');
    }

    /** @return array{0:string,1:string}|null */
    private function parseCookie(Request $request): ?array
    {
        $value = $request->cookie($this->cookieName());
        if ($value === null || preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $value, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }
}

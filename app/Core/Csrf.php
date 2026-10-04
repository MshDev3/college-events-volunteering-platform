<?php

declare(strict_types=1);

namespace App\Core;

/** Synchronizer-token CSRF protection (one token per session, compared in constant time). */
final class Csrf
{
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-Token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->put('_csrf', $token);
        }

        return $token;
    }

    public function verify(#[\SensitiveParameter] ?string $token): bool
    {
        $expected = $this->session->get('_csrf');

        return is_string($token) && is_string($expected) && hash_equals($expected, $token);
    }

    /** Called after login/logout so a token captured before authentication is useless. */
    public function rotate(): void
    {
        $this->session->put('_csrf', bin2hex(random_bytes(32)));
    }
}

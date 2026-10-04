<?php

declare(strict_types=1);

namespace App\Core;

/** Cookies queued by services during a request; the kernel attaches them to the final response. */
final class CookieJar
{
    /** @var array<string, array{value:string, options:array<string,mixed>}> */
    private array $queued = [];

    public function queue(string $name, string $value, int $expiresAt, bool $secure): void
    {
        $this->queued[$name] = [
            'value' => $value,
            'options' => [
                'expires' => $expiresAt,
                'path' => cookie_path(),
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ],
        ];
    }

    public function forget(string $name, bool $secure): void
    {
        $this->queue($name, '', time() - 3600, $secure);
    }

    public function applyTo(Response $response): Response
    {
        foreach ($this->queued as $name => $cookie) {
            $response->withCookie($name, $cookie['value'], $cookie['options']);
        }
        $this->queued = [];

        return $response;
    }
}

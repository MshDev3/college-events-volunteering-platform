<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Work to run AFTER the response has been sent to the client (see App::terminate()), so its
 * duration never shows in response times — e.g. sending a password-reset email, whose SMTP
 * round-trip would otherwise reveal that the address is registered.
 */
final class Deferred
{
    /** @var list<callable(): void> */
    private array $callbacks = [];

    /** @param callable(): void $callback */
    public function push(callable $callback): void
    {
        $this->callbacks[] = $callback;
    }

    public function isEmpty(): bool
    {
        return $this->callbacks === [];
    }

    /** Run and clear the callbacks; a failing one is logged and does not stop the others. */
    public function run(Logger $logger): void
    {
        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                $logger->error('Deferred task failed', ['error' => $e->getMessage()]);
            }
        }
    }
}

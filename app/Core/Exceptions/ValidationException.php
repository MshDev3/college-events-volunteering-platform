<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/** Thrown by the Validator; the kernel redirects back with errors + old input (or returns 422 JSON). */
final class ValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors field => translated messages */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The given data was invalid.');
    }

    public static function withMessage(string $field, string $message): self
    {
        return new self([$field => [$message]]);
    }
}

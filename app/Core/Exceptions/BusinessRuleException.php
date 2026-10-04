<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * A domain rule was violated (event full, duplicate registration, reservation conflict...).
 * Carries a translation key so the message is shown in the user's language.
 */
final class BusinessRuleException extends RuntimeException
{
    /** @param array<string, string|int|float> $params */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}

<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/** An HTTP error with a status code; rendered as a translated error page or JSON. */
class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly ?string $messageKey = null)
    {
        parent::__construct($messageKey ?? "HTTP $status", $status);
    }
}

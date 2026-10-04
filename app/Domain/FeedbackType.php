<?php

declare(strict_types=1);

namespace App\Domain;

enum FeedbackType: string implements Status
{
    case SUGGESTION = 'SUGGESTION';
    case COMPLAINT = 'COMPLAINT';

    public function labelKey(): string
    {
        return 'common.feedback_type.' . $this->value;
    }

    public function tone(): string
    {
        return $this === self::SUGGESTION ? 'info' : 'warning';
    }
}

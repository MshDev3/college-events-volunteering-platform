<?php

declare(strict_types=1);

namespace App\Domain;

/** A status enum that can render itself as a translated, colored badge. */
interface Status
{
    /** Translation key, e.g. "common.status.activity.UPCOMING". */
    public function labelKey(): string;

    /** Bootstrap contextual color: primary, success, warning, danger, secondary, info. */
    public function tone(): string;
}

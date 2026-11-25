<?php

namespace App\Data\NightAudit;

use Spatie\LaravelData\Data;

/**
 * The night audit sheet's note on Native POS work still open: register shifts that are not closed and orders still open.
 * A note only: it never blocks the audit, because a bar can trade through 2 AM. `url` is null when the user cannot open it.
 */
class NativePosReviewData extends Data
{
    public function __construct(
        public string $message,
        public ?string $url,
    ) {}
}

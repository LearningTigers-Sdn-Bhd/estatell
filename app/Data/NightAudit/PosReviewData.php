<?php

namespace App\Data\NightAudit;

use Spatie\LaravelData\Data;

/**
 * The night audit sheet's note on POS receipts that need review. `url` is null when the user cannot open them.
 */
class PosReviewData extends Data
{
    public function __construct(
        public string $message,
        public ?string $url,
    ) {}
}

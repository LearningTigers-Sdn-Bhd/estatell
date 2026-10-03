<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * The answer of the quote route: the quote, and a message when it cannot be applied (no eligible charge, or a
 * refused amount). A refused quote still carries its charges so the Dialog can show them.
 */
class FolioDiscountQuoteResponseData extends Data
{
    public function __construct(
        public DiscountQuoteData $quote,
        public ?string $error,
    ) {}
}

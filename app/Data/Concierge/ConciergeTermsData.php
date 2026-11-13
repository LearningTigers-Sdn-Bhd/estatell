<?php

namespace App\Data\Concierge;

use Spatie\LaravelData\Data;

/**
 * The registration terms a guest reads on the Concierge page, in the language the card will be signed in.
 */
class ConciergeTermsData extends Data
{
    public function __construct(
        public int $version,
        public string $text,
        public string $locale,
    ) {}
}

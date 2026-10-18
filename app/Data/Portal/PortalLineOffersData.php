<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;

/**
 * The offers for one search line, or the `lineError` that stops the line (too many adults or children).
 */
class PortalLineOffersData extends Data
{
    /**
     * @param  list<PortalOfferData>  $offers
     */
    public function __construct(
        public array $offers,
        public ?string $lineError,
    ) {}
}

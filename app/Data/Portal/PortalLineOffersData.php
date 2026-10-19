<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;

/**
 * The room types offered for one line, or the `lineError` that stops the line (no rate plan, or a plan the company
 * cannot see).
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

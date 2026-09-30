<?php

namespace App\Data\Rates;

use Spatie\LaravelData\Data;

/** One room type in the derivation preview: the parent's price today and the price after the derivation. */
class RatePlanDerivationPreviewRowData extends Data
{
    public function __construct(
        public int $roomTypeId,
        public string $name,
        public ?string $parentAmount,
        public ?string $derivedAmount,
    ) {}
}

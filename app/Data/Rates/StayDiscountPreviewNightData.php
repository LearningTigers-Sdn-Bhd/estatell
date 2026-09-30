<?php

namespace App\Data\Rates;

use Spatie\LaravelData\Data;

/** One night of the By night view: the room's price after discount, the discount, and the amount per unit. */
class StayDiscountPreviewNightData extends Data
{
    public function __construct(
        public string $amount,
        public string $discount,
        public string $unitAmount,
    ) {}
}

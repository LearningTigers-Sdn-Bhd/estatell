<?php

namespace App\Data\Rates;

use Spatie\LaravelData\Data;

/** Marks that the plan a derived plan follows has long-stay rules the Discounts tab can copy. */
class RatePlanParentDiscountsData extends Data
{
    public function __construct(
        public string $parentName,
    ) {}
}

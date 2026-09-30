<?php

namespace App\Data\Rates;

use Spatie\LaravelData\Data;

/** The long-stay rules of the plan a derived plan follows, offered on the Discounts tab to copy. */
class RatePlanParentDiscountsData extends Data
{
    /**
     * @param  list<RatePlanStayDiscountData>  $discounts
     */
    public function __construct(
        public string $parentName,
        public array $discounts,
    ) {}
}

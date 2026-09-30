<?php

namespace App\Services\Rates\Pricing;

/**
 * The nights of a stay priced by a strategy, before the long-stay discount.
 */
class PricedNights
{
    /**
     * @param  non-empty-list<NightPrice>  $nights
     * @param  int  $discountUnits  How many units an amount discount is taken off, from `StayPricing::discountUnits()`
     */
    public function __construct(
        public readonly array $nights,
        public readonly int $discountUnits,
    ) {}
}

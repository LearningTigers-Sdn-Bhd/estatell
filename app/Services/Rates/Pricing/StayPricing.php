<?php

namespace App\Services\Rates\Pricing;

use App\Models\Rate;

/**
 * Prices one night of a stay for one pricing basis. Strategies are chosen by `PricingBasis::strategy()`.
 */
interface StayPricing
{
    /**
     * @throws QuoteRefused when the night cannot be priced
     */
    public function priceNight(StayContext $stay, Rate $rate): NightPrice;

    /**
     * How many units a long-stay discount amount is taken off each night: guests, beds or rooms.
     */
    public function discountUnits(StayContext $stay): int;
}

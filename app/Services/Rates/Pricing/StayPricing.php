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
}

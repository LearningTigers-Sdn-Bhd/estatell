<?php

namespace App\Services\Rates\Pricing;

use Carbon\CarbonImmutable;

/**
 * Prices the nights of a stay for one pricing basis. Strategies are chosen by `PricingBasis::strategy()`.
 */
interface StayPricing
{
    /**
     * One `NightPrice` for each night from arrival up to, not including, departure.
     *
     * @return non-empty-list<NightPrice>
     *
     * @throws QuoteRefused when the stay cannot be priced
     */
    public function priceStay(StayContext $stay, CarbonImmutable $arrival, CarbonImmutable $departure): array;

    /**
     * How many units a long-stay discount amount is taken off each night: guests, beds or rooms.
     */
    public function discountUnits(StayContext $stay): int;
}

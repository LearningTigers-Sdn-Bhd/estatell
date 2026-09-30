<?php

namespace App\Services\Rates\Pricing;

use App\Models\Rate;
use Brick\Money\Money;

/**
 * The bed rate times the number of beds.
 */
class PerBedPricing implements StayPricing
{
    public function priceNight(StayContext $stay, Rate $rate): NightPrice
    {
        $currency = $stay->currency;
        $base = Money::of($rate->amount, $currency)->multipliedBy($stay->beds);

        return NightPrice::of($rate->date, $base, Money::zero($currency), Money::zero($currency));
    }

    public function discountUnits(StayContext $stay): int
    {
        return $stay->beds;
    }
}

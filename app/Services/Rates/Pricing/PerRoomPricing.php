<?php

namespace App\Services\Rates\Pricing;

use App\Models\Rate;
use Brick\Money\Money;

/**
 * The occupancy price for the adult count if set, else the room price plus extra adults above the base occupancy.
 * Children pay the plan's flat child amount either way.
 */
class PerRoomPricing implements StayPricing
{
    public function priceNight(StayContext $stay, Rate $rate): NightPrice
    {
        $currency = $stay->currency;
        $plan = $stay->ratePlan;
        $childFee = Money::of($plan->extra_child_amount, $currency)->multipliedBy(count($stay->childAgeGroupIds));
        $occupancy = $rate->occupancyPrices->firstWhere('adults', $stay->adults);

        if ($occupancy !== null) {
            return NightPrice::of($rate->date, Money::of($occupancy->amount, $currency), $childFee, Money::zero($currency));
        }

        $extraAdults = max(0, $stay->adults - $stay->roomType->base_occupancy);
        $extra = Money::of($plan->extra_adult_amount, $currency)->multipliedBy($extraAdults)->plus($childFee);

        return NightPrice::of($rate->date, Money::of($rate->amount, $currency), $extra, Money::zero($currency));
    }
}

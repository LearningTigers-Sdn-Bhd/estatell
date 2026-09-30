<?php

namespace App\Services\Rates\Pricing;

use App\Enums\AgeBandPricing;
use App\Models\Rate;
use App\Services\Rates\RateQuote;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * The occupancy price for the adults if set, else the adult rate times the adults, plus the single supplement for
 * one adult without an occupancy price. Each child pays by age group.
 */
class PerGuestPricing extends NightlyPricing
{
    protected function priceNight(StayContext $stay, Rate $rate): NightPrice
    {
        $currency = $stay->currency;
        $plan = $stay->ratePlan;
        $occupancy = $rate->occupancyPrices->firstWhere('adults', $stay->adults);

        $base = $occupancy !== null
            ? Money::of($occupancy->amount, $currency)
            : Money::of($rate->amount, $currency)->multipliedBy($stay->adults);
        $supplement = $occupancy === null && $stay->adults === 1
            ? Money::of($plan->single_supplement, $currency)
            : Money::zero($currency);

        $oneAdult = Money::of($rate->occupancyPrices->firstWhere('adults', 1)->amount ?? $rate->amount, $currency);
        $childPrices = $plan->childPrices->keyBy('property_age_group_id');
        $extra = Money::zero($currency);

        foreach ($stay->childAgeGroupIds as $groupId) {
            $childPrice = $childPrices->get($groupId);

            if ($childPrice === null || ($childPrice->pricing !== AgeBandPricing::Free && $childPrice->value === null)) {
                throw new QuoteRefused(RateQuote::REFUSAL_CHILD_PRICE_MISSING, $rate->date);
            }

            $extra = $extra->plus(match ($childPrice->pricing) {
                AgeBandPricing::Free => Money::zero($currency),
                AgeBandPricing::Percent => $oneAdult->multipliedBy(BigDecimal::of($childPrice->value)->dividedBy(100, 8), RoundingMode::HalfUp),
                AgeBandPricing::Fixed => Money::of($childPrice->value, $currency),
            });
        }

        return NightPrice::of($rate->date, $base, $extra, $supplement);
    }

    public function discountUnits(StayContext $stay): int
    {
        return $stay->adults + count($stay->childAgeGroupIds);
    }
}

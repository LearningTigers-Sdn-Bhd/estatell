<?php

namespace App\Services\Rates;

use App\Models\PropertyAgeGroup;
use App\Services\Rates\Pricing\PricedNights;
use App\Services\Rates\Pricing\QuoteRefused;
use App\Services\Rates\Pricing\StayContext;
use InvalidArgumentException;

/**
 * Prices a stay night by night with the strategy of the plan's pricing basis. A derived plan is priced as its parent,
 * then adjusted by `DerivedRateResolver`. The strategy then fits the nights to the plan's inclusions. It reads only. Restrictions are
 * checked by `RestrictionCheck`, not here.
 */
class RateQuoteService
{
    public function __construct(private StayDiscount $stayDiscount, private DerivedRateResolver $derivedRateResolver) {}

    public function quote(RateQuoteRequest $request): RateQuote
    {
        try {
            $priced = $this->priceNights($request);
        } catch (QuoteRefused $refused) {
            return RateQuote::refused($refused->reason, $refused->date);
        }

        $request->ratePlan->loadMissing('stayDiscounts');

        return RateQuote::quoted($this->stayDiscount->apply($request->ratePlan->stayDiscounts, $priced->nights, $priced->discountUnits));
    }

    /**
     * Prices every night of the stay before the long-stay discount, so a caller can try other discount rules.
     *
     * @throws QuoteRefused when the stay cannot be priced
     */
    public function priceNights(RateQuoteRequest $request): PricedNights
    {
        $plan = $request->ratePlan;
        $roomType = $request->roomType;
        $arrival = $request->arrival->toImmutable()->startOfDay();
        $departure = $request->departure->toImmutable()->startOfDay();

        if ($arrival->diffInDays($departure) < 1) {
            throw new QuoteRefused(RateQuote::REFUSAL_INVALID_STAY);
        }

        if ($request->adults < 1) {
            throw new QuoteRefused(RateQuote::REFUSAL_ADULTS_REQUIRED);
        }

        if ($request->beds < 1) {
            throw new QuoteRefused(RateQuote::REFUSAL_BEDS_REQUIRED);
        }

        if ($plan->archived_at !== null) {
            throw new QuoteRefused(RateQuote::REFUSAL_PLAN_ARCHIVED);
        }

        $pricingPlan = $plan->parent_rate_plan_id === null ? $plan : $plan->loadMissing('parent')->parent;

        if ($pricingPlan->archived_at !== null) {
            throw new QuoteRefused(RateQuote::REFUSAL_PLAN_ARCHIVED);
        }

        if (! $plan->roomTypes()->whereKey($roomType->id)->exists()) {
            throw new QuoteRefused(RateQuote::REFUSAL_ROOM_TYPE_NOT_SOLD);
        }

        $currency = $plan->property->settings?->currency->value
            ?? throw new InvalidArgumentException('The property needs settings with a currency to quote a stay.');

        $pricingPlan->loadMissing('childPrices');
        [$adults, $childAgeGroupIds] = $this->splitGuests($request);
        $stay = new StayContext($pricingPlan, $roomType, $adults, $childAgeGroupIds, $request->beds, $currency);
        $strategy = app($pricingPlan->pricing_basis->strategy());
        $nights = $strategy->priceStay($stay, $arrival, $departure);

        if ($pricingPlan !== $plan) {
            $units = $strategy->discountUnits($stay);
            $nights = array_map(fn ($night) => $this->derivedRateResolver->resolve($night, $plan, $units), $nights);
        }

        $nights = $strategy->fitInclusions($nights, $plan->loadMissing('inclusions')->inclusions, $adults + count($childAgeGroupIds));

        return new PricedNights($nights, $strategy->discountUnits($stay));
    }

    /**
     * Maps each child age to the property's age group. An age past the last group is an adult.
     *
     * @return array{0: int, 1: list<int>}
     */
    private function splitGuests(RateQuoteRequest $request): array
    {
        $adults = $request->adults;
        $groupIds = [];

        if ($request->childAges === []) {
            return [$adults, $groupIds];
        }

        $groups = PropertyAgeGroup::query()->where('property_id', $request->ratePlan->property_id)->get();

        foreach ($request->childAges as $age) {
            $group = $groups->first(fn (PropertyAgeGroup $group): bool => $age >= $group->min_age && $age <= $group->max_age);

            if ($group === null) {
                $adults++;
            } else {
                $groupIds[] = $group->id;
            }
        }

        return [$adults, $groupIds];
    }
}

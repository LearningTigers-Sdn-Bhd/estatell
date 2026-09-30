<?php

namespace App\Services\Rates;

use App\Models\PropertyAgeGroup;
use App\Models\Rate;
use App\Services\Rates\Pricing\PricedNights;
use App\Services\Rates\Pricing\QuoteRefused;
use App\Services\Rates\Pricing\StayContext;
use InvalidArgumentException;

/**
 * Prices a stay night by night with the strategy of the plan's pricing basis. It reads only. Restrictions are
 * checked by `RestrictionCheck`, not here.
 */
class RateQuoteService
{
    public function __construct(private StayDiscount $stayDiscount) {}

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

        $strategyClass = $plan->pricing_basis->strategy();

        if ($strategyClass === null) {
            throw new QuoteRefused(RateQuote::REFUSAL_BASIS_UNSUPPORTED);
        }

        if (! $plan->roomTypes()->whereKey($roomType->id)->exists()) {
            throw new QuoteRefused(RateQuote::REFUSAL_ROOM_TYPE_NOT_SOLD);
        }

        $currency = $plan->property->settings?->currency->value
            ?? throw new InvalidArgumentException('The property needs settings with a currency to quote a stay.');

        $plan->loadMissing('childPrices');
        [$adults, $childAgeGroupIds] = $this->splitGuests($request);
        $stay = new StayContext($plan, $roomType, $adults, $childAgeGroupIds, $request->beds, $currency);
        $strategy = app($strategyClass);

        $rates = Rate::query()
            ->with('occupancyPrices')
            ->where('rate_plan_id', $plan->id)
            ->where('room_type_id', $roomType->id)
            ->where('date', '>=', $arrival->toDateString())
            ->where('date', '<', $departure->toDateString())
            ->get()
            ->keyBy(fn (Rate $rate): string => $rate->date->toDateString());

        $nights = [];

        for ($date = $arrival; $date->lt($departure); $date = $date->addDay()) {
            $rate = $rates->get($date->toDateString());

            if ($rate === null || $rate->amount === null) {
                throw new QuoteRefused(RateQuote::REFUSAL_RATE_MISSING, $date);
            }

            $nights[] = $strategy->priceNight($stay, $rate);
        }

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

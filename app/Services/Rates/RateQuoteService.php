<?php

namespace App\Services\Rates;

use App\Enums\PricingBasis;
use App\Models\PropertyAgeGroup;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\NightAudit\FirstBusinessDate;
use App\Services\Rates\Pricing\DayUsePricing;
use App\Services\Rates\Pricing\NightPrice;
use App\Services\Rates\Pricing\PricedNights;
use App\Services\Rates\Pricing\QuoteRefused;
use App\Services\Rates\Pricing\StayContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Prices a stay night by night with the strategy of the plan's pricing basis. A derived plan is priced as its parent,
 * then adjusted by `DerivedRateResolver`. The strategy then fits the nights to the plan's inclusions. It reads only. Restrictions are
 * checked by `RestrictionCheck`, not here. A night before the property's go-live date is never charged, so when one of
 * them has no rate it needs none: every night before go-live then prices at 0.00 and only the nights from go-live are
 * priced and fitted (a missing rate from go-live on is still refused).
 */
class RateQuoteService
{
    public function __construct(private StayDiscount $stayDiscount, private DerivedRateResolver $derivedRateResolver, private DayUsePricing $dayUsePricing, private FirstBusinessDate $firstBusinessDate) {}

    public function quote(RateQuoteRequest $request): RateQuote
    {
        try {
            $priced = $this->priceNights($request);
        } catch (QuoteRefused $refused) {
            return RateQuote::refused($refused->reason, $refused->date);
        }

        $request->ratePlan->loadMissing('stayDiscounts');

        $nights = $this->stayDiscount->apply($request->ratePlan->stayDiscounts, $priced->nights, $priced->discountUnits);

        if ($nights === []) {
            return RateQuote::refused(RateQuote::REFUSAL_INVALID_STAY);
        }

        return RateQuote::quoted($nights);
    }

    /**
     * Prices one day use block with `DayUsePricing`. Days and hours only: no nights, no long-stay discount and no
     * derived plan. `$ignoreStopSell` is for a caller that has accepted a restriction override.
     */
    public function quoteDayUse(RoomType $roomType, RatePlan $plan, CarbonInterface $date, int $hours, bool $ignoreStopSell = false): DayUseQuote
    {
        $currency = $plan->property->settings?->currency->getCurrencyCode()
            ?? throw new InvalidArgumentException('The property needs settings with a currency to quote a day use.');

        try {
            return DayUseQuote::quoted($this->dayUsePricing->price($plan, $roomType, $date, $hours, $currency, $ignoreStopSell));
        } catch (QuoteRefused $refused) {
            return DayUseQuote::refused($refused->reason, $refused->date);
        }
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

        if ($plan->pricing_basis === PricingBasis::DayUse) {
            throw new QuoteRefused(RateQuote::REFUSAL_PLAN_NOT_NIGHTLY);
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

        $currency = $plan->property->settings?->currency->getCurrencyCode()
            ?? throw new InvalidArgumentException('The property needs settings with a currency to quote a stay.');

        $pricingPlan->loadMissing('childPrices');
        [$adults, $childAgeGroupIds] = $this->splitGuests($request);
        $stay = new StayContext($pricingPlan, $roomType, $adults, $childAgeGroupIds, $request->beds, $currency);
        $strategy = app($pricingPlan->pricing_basis->strategy());
        $unpriced = [];

        try {
            $nights = $strategy->priceStay($stay, $arrival, $departure);
        } catch (QuoteRefused $refused) {
            $goLive = $this->goLiveFor($pricingPlan, $refused);

            if ($goLive === null) {
                throw $refused;
            }

            $zero = Money::zero($currency);

            for ($date = $arrival; $date->lt($departure) && $date->lt($goLive); $date = $date->addDay()) {
                $unpriced[] = NightPrice::of($date, $zero, $zero, $zero);
            }

            $nights = $goLive->lt($departure) ? $strategy->priceStay($stay, $goLive, $departure) : [];
        }

        if ($nights !== [] && $pricingPlan !== $plan) {
            $units = $strategy->discountUnits($stay);
            $nights = array_map(fn ($night) => $this->derivedRateResolver->resolve($night, $plan, $units), $nights);
        }

        if ($nights !== []) {
            $nights = $strategy->fitInclusions($nights, $plan->loadMissing('inclusions')->inclusions, $adults + count($childAgeGroupIds));
        }

        $all = array_values([...$unpriced, ...$nights]);

        if ($all === []) {
            throw new QuoteRefused(RateQuote::REFUSAL_INVALID_STAY);
        }

        return new PricedNights($all, $strategy->discountUnits($stay));
    }

    /**
     * The go-live date when the refusal is a missing rate on a night before it, so those nights need no rate. Null for
     * any other refusal, and for a missing rate from go-live on.
     */
    private function goLiveFor(RatePlan $pricingPlan, QuoteRefused $refused): ?CarbonImmutable
    {
        if ($refused->reason !== RateQuote::REFUSAL_RATE_MISSING || $refused->date === null) {
            return null;
        }

        $goLive = $this->firstBusinessDate->date($pricingPlan->property);

        return $goLive !== null && $refused->date->toImmutable()->startOfDay()->lt($goLive) ? $goLive : null;
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

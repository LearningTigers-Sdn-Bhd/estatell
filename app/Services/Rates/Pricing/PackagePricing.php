<?php

namespace App\Services\Rates\Pricing;

use App\Enums\AgeBandPricing;
use App\Enums\PackageUnit;
use App\Enums\SellUnit;
use App\Models\PackagePrice;
use App\Models\RatePlanInclusion;
use App\Services\Packages\InclusionAllocator;
use App\Services\Rates\RateQuote;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\AllocationMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The stay price of a package for the arrival date, split across the nights so the cents always add up.
 *
 * - Charged per guest: the price for each adult, plus each child by age group. A Percent child price is a percent of the
 *   adult price, a Fixed one is an amount for the stay.
 * - Charged per room: the room price, plus the plan's extra adult and extra child amounts for the stay.
 * - A Bed room type: the price for each bed.
 *
 * The adults or room part goes to `base` and the children or extra guests part goes to `extra`, each split on its own.
 * `fitInclusions()` then moves price onto the nights that post more inclusions.
 */
class PackagePricing implements StayPricing
{
    public function __construct(private InclusionAllocator $inclusionAllocator) {}

    public function priceStay(StayContext $stay, CarbonImmutable $arrival, CarbonImmutable $departure): array
    {
        $plan = $stay->ratePlan;
        $nights = (int) $arrival->diffInDays($departure);

        if ($nights !== $plan->fixed_nights) {
            throw new QuoteRefused(RateQuote::REFUSAL_STAY_LENGTH_MISMATCH);
        }

        $price = PackagePrice::query()
            ->where('rate_plan_id', $plan->id)
            ->where('room_type_id', $stay->roomType->id)
            ->where('arrival_from', '<=', $arrival->toDateString())
            ->where('arrival_to', '>=', $arrival->toDateString())
            ->first();

        if ($price === null) {
            throw new QuoteRefused(RateQuote::REFUSAL_PACKAGE_PRICE_MISSING, $arrival);
        }

        [$base, $extra] = $this->stayAmounts($stay, $price, $arrival);

        $ratios = array_fill(0, $nights, 1);
        $bases = $base->allocate($ratios, AllocationMode::FloorToFirst);
        $extras = $extra->allocate($ratios, AllocationMode::FloorToFirst);
        $zero = Money::zero($base->getCurrency());

        return array_map(
            fn (int $index): NightPrice => NightPrice::of($arrival->addDays($index), $bases[$index], $extras[$index], $zero),
            array_keys($bases),
        );
    }

    /**
     * Splits the stay again so each night covers the inclusions it posts: its inclusions plus an even share of the room
     * part. The stay total does not change. A plan with no inclusions keeps the even split.
     */
    public function fitInclusions(array $nights, iterable $inclusions, int $guests): array
    {
        $currency = $nights[0]->base->getCurrency();
        $inclusions = collect($inclusions);
        $base = Money::sum(...array_map(fn (NightPrice $night): Money => $night->base, $nights));
        $extra = Money::sum(...array_map(fn (NightPrice $night): Money => $night->extra, $nights));
        $nightTotals = $this->spreadByInclusions($base->plus($extra), array_map(fn (int $index): bool => $index === 0, array_keys($nights)), $inclusions, $guests);

        if ($this->inclusionAllocator->total($currency, $inclusions, $guests, true)->isZero()) {
            return $nights;
        }

        // Largest remainder never rounds a night's extra above its total, so the base is never negative.
        $extras = $extra->isZero()
            ? array_fill(0, count($nights), $extra)
            : $extra->allocate(array_map(fn (Money $total): string => (string) $total->getMinorAmount(), $nightTotals), AllocationMode::FloorToLargestRemainder);

        return array_map(
            fn (NightPrice $night, Money $total, Money $nightExtra): NightPrice => NightPrice::of($night->date, $total->minus($nightExtra), $nightExtra, $night->supplement),
            $nights,
            $nightTotals,
            $extras,
        );
    }

    /**
     * @param  list<bool>  $firstNight  Whether each night is the stay's real first night
     * @param  iterable<RatePlanInclusion>  $inclusions
     * @return list<Money>
     */
    public function spreadByInclusions(Money $total, array $firstNight, iterable $inclusions, int $guests): array
    {
        if ($firstNight === []) {
            return [];
        }

        $inclusions = collect($inclusions);
        $nightInclusions = array_map(
            fn (bool $first): Money => $this->inclusionAllocator->total($total->getCurrency(), $inclusions, $guests, $first),
            $firstNight,
        );
        $room = $total->minus(Money::sum(...$nightInclusions));

        if ($room->isNegative()) {
            throw new QuoteRefused(RateQuote::REFUSAL_PACKAGE_BELOW_INCLUSIONS);
        }

        $roomShares = $room->allocate(array_fill(0, count($firstNight), 1), AllocationMode::FloorToFirst);

        return array_map(fn (Money $included, Money $share): Money => $included->plus($share), $nightInclusions, $roomShares);
    }

    /**
     * A discount never applies to a package, so the units do not matter.
     */
    public function discountUnits(StayContext $stay): int
    {
        return 1;
    }

    /**
     * @return array{0: Money, 1: Money} The base and the extra for the whole stay
     */
    private function stayAmounts(StayContext $stay, PackagePrice $price, CarbonImmutable $arrival): array
    {
        $plan = $stay->ratePlan;
        $amount = Money::of($price->amount, $stay->currency);
        $zero = Money::zero($stay->currency);

        if ($stay->roomType->sell_unit === SellUnit::Bed) {
            return [$amount->multipliedBy($stay->beds), $zero];
        }

        if ($plan->package_unit === PackageUnit::PerRoom) {
            $extraAdults = max(0, $stay->adults - $stay->roomType->base_occupancy);

            return [
                $amount,
                Money::of($plan->extra_adult_amount, $stay->currency)->multipliedBy($extraAdults)
                    ->plus(Money::of($plan->extra_child_amount, $stay->currency)->multipliedBy(count($stay->childAgeGroupIds))),
            ];
        }

        $childPrices = $plan->childPrices->keyBy('property_age_group_id');
        $children = $zero;

        foreach ($stay->childAgeGroupIds as $groupId) {
            $childPrice = $childPrices->get($groupId);

            if ($childPrice === null || ($childPrice->pricing !== AgeBandPricing::Free && $childPrice->value === null)) {
                throw new QuoteRefused(RateQuote::REFUSAL_CHILD_PRICE_MISSING, $arrival);
            }

            $children = $children->plus(match ($childPrice->pricing) {
                AgeBandPricing::Free => $zero,
                AgeBandPricing::Percent => $amount->multipliedBy(BigDecimal::of($childPrice->value)->dividedBy(100, 8), RoundingMode::HalfUp),
                AgeBandPricing::Fixed => Money::of($childPrice->value, $stay->currency),
            });
        }

        return [$amount->multipliedBy($stay->adults), $children];
    }
}

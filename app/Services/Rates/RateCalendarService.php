<?php

namespace App\Services\Rates;

use App\Data\Rates\RateCalendarCellData;
use App\Data\Rates\RateCalendarData;
use App\Data\Rates\RateCalendarEndingData;
use App\Data\Rates\RateCalendarPlanData;
use App\Data\Rates\RateCalendarRoomTypeData;
use App\Enums\PricingBasis;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Rates\Pricing\NightPrice;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the rate calendar for a date range: a group per room type, a row per plan that sells it, and a cell per date
 * that has a price or a restriction. A derived plan shows its parent's price after the derivation. It reads only.
 */
class RateCalendarService
{
    public function __construct(
        private DerivedRateResolver $resolver,
        private RatesEndingCheck $ratesEndingCheck,
    ) {}

    public function forRange(Property $property, CarbonInterface $from, CarbonInterface $to, CarbonInterface $today, string $currency): RateCalendarData
    {
        $start = $from->startOfDay();
        $end = $to->startOfDay();

        $roomTypes = $property->roomTypes()->active()->ordered()->get();
        $plans = $property->ratePlans()->active()->ordered()->with(['roomTypes', 'parent'])->get();

        $rates = Rate::query()
            ->whereIn('rate_plan_id', $plans->modelKeys())
            ->whereIn('room_type_id', $roomTypes->modelKeys())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('occupancyPrices')
            ->orderBy('date')
            ->get()
            ->groupBy(fn (Rate $rate): string => $rate->rate_plan_id.'-'.$rate->room_type_id);

        $groups = $roomTypes->map(fn (RoomType $roomType): RateCalendarRoomTypeData => RateCalendarRoomTypeData::fromModel(
            $roomType,
            $plans
                ->filter(fn (RatePlan $plan): bool => $plan->roomTypes->contains('id', $roomType->id))
                ->map(fn (RatePlan $plan): RateCalendarPlanData => RateCalendarPlanData::fromModel(
                    $plan,
                    $this->cells($plan, $roomType, $rates, $currency),
                ))
                ->values()
                ->all(),
        ))->filter(fn (RateCalendarRoomTypeData $group): bool => $group->plans !== [])->values();

        return new RateCalendarData(
            from: $start->toDateString(),
            to: $end->toDateString(),
            dates: $this->datesOf($start, $end),
            roomTypes: $groups->all(),
            ending: $this->ending($plans, $roomTypes, $today),
        );
    }

    /**
     * @param  Collection<string, Collection<int, Rate>>  $rates  Keyed by `{plan id}-{room type id}`.
     * @return list<RateCalendarCellData>
     */
    private function cells(RatePlan $plan, RoomType $roomType, Collection $rates, string $currency): array
    {
        $own = $rates->get($plan->id.'-'.$roomType->id, new Collection);

        if ($plan->parent_rate_plan_id === null) {
            return $own->map(fn (Rate $rate): RateCalendarCellData => RateCalendarCellData::fromRate(
                $rate,
                $plan->pricing_basis === PricingBasis::Package ? null : $rate->amount,
            ))->values()->all();
        }

        $cells = [];

        foreach ($rates->get($plan->parent_rate_plan_id.'-'.$roomType->id, new Collection) as $parentRate) {
            if ($parentRate->amount !== null) {
                $cells[$parentRate->date->toDateString()] = RateCalendarCellData::priceOnly(
                    $parentRate->date->toDateString(),
                    $this->derivedAmount($plan, $parentRate, $currency),
                );
            }
        }

        foreach ($own as $rate) {
            $date = $rate->date->toDateString();
            $cells[$date] = RateCalendarCellData::fromRate($rate, ($cells[$date] ?? null)?->amount);
        }

        ksort($cells);

        return array_values($cells);
    }

    /**
     * The parent's room price for the date after the derivation, as the preview on the Pricing tab shows it.
     */
    private function derivedAmount(RatePlan $plan, Rate $parentRate, string $currency): string
    {
        $zero = Money::zero($currency);
        $night = NightPrice::of($parentRate->date, Money::of($parentRate->amount, $currency), $zero, $zero);

        return (string) $this->resolver->resolve($night, $plan, 1)->total()->getAmount();
    }

    /**
     * @param  Collection<int, RatePlan>  $plans
     * @param  Collection<int, RoomType>  $roomTypes
     * @return list<RateCalendarEndingData>
     */
    private function ending(Collection $plans, Collection $roomTypes, CarbonInterface $today): array
    {
        $ownPricePlans = $plans->filter(fn (RatePlan $plan): bool => $plan->parent_rate_plan_id === null && $plan->pricing_basis !== PricingBasis::Package);
        $pricedUntil = $this->ratesEndingCheck->pricedUntilByPlan($ownPricePlans->modelKeys());

        return $ownPricePlans->map(function (RatePlan $plan) use ($roomTypes, $pricedUntil, $today): ?RateCalendarEndingData {
            $names = $roomTypes
                ->filter(fn (RoomType $roomType): bool => $plan->roomTypes->contains('id', $roomType->id)
                    && $this->ratesEndingCheck->isEnding($pricedUntil[$plan->id][$roomType->id] ?? null, $today))
                ->pluck('name')
                ->all();

            return $names === [] ? null : RateCalendarEndingData::fromModel($plan, $names);
        })->filter()->values()->all();
    }

    /**
     * @return list<string>
     */
    private function datesOf(CarbonInterface $start, CarbonInterface $end): array
    {
        $dates = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }
}

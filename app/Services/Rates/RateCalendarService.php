<?php

namespace App\Services\Rates;

use App\Data\Rates\RateCalendarAvailabilityData;
use App\Data\Rates\RateCalendarCellData;
use App\Data\Rates\RateCalendarData;
use App\Data\Rates\RateCalendarEndingData;
use App\Data\Rates\RateCalendarPlanData;
use App\Data\Rates\RateCalendarRoomTypeData;
use App\Data\Rates\RatePlanSavedPricesData;
use App\Enums\PricingBasis;
use App\Enums\Weekday;
use App\Models\PackagePrice;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use App\Services\Rates\Pricing\NightPrice;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
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
        private AvailabilityService $availabilityService,
    ) {}

    public function forRange(Property $property, CarbonInterface $from, CarbonInterface $to, CarbonInterface $today, string $currency): RateCalendarData
    {
        $start = $from->startOfDay();
        $end = $to->startOfDay();

        [$roomTypes, $plans, $rows] = $this->planRows($property, $start, $end, $currency);

        $groups = $roomTypes->map(fn (RoomType $roomType): ?RateCalendarRoomTypeData => ($rows[$roomType->id] ?? []) === []
            ? null
            : RateCalendarRoomTypeData::fromModel($roomType, $rows[$roomType->id], $this->availability($roomType, $start, $end)))
            ->filter()->values();

        return new RateCalendarData(
            from: $start->toDateString(),
            to: $end->toDateString(),
            dates: $this->datesOf($start, $end),
            roomTypes: array_values($groups->all()),
            ending: $this->ending($plans, $roomTypes, $today),
        );
    }

    /**
     * The saved prices of one plan, even an archived one: a group for each active room type it sells, holding only
     * that plan. A derived plan reads its parent's rates, so they are loaded too.
     */
    public function forPlan(Property $property, RatePlan $ratePlan, CarbonInterface $from, CarbonInterface $to, string $currency): RatePlanSavedPricesData
    {
        $start = $from->startOfDay();
        $end = $to->startOfDay();

        [$roomTypes, , $rows] = $this->planRows($property, $start, $end, $currency, $ratePlan);

        return new RatePlanSavedPricesData(
            from: $start->toDateString(),
            to: $end->toDateString(),
            dates: $this->datesOf($start, $end),
            roomTypes: array_values($roomTypes
                ->filter(fn (RoomType $roomType): bool => ($rows[$roomType->id] ?? []) !== [])
                ->map(fn (RoomType $roomType): RateCalendarRoomTypeData => RateCalendarRoomTypeData::fromModel($roomType, $rows[$roomType->id], []))
                ->all()),
        );
    }

    /**
     * The plans that sell each active room type with their cells, without availability or ending notices. A room
     * type no plan sells is left out. The Stay View reads its rate row from this.
     *
     * @return list<array{roomType: RoomType, plans: list<RateCalendarPlanData>}>
     */
    public function prices(Property $property, CarbonInterface $from, CarbonInterface $to, string $currency): array
    {
        [$roomTypes, , $rows] = $this->planRows($property, $from->startOfDay(), $to->startOfDay(), $currency);

        return array_values($roomTypes
            ->filter(fn (RoomType $roomType): bool => ($rows[$roomType->id] ?? []) !== [])
            ->map(fn (RoomType $roomType): array => ['roomType' => $roomType, 'plans' => $rows[$roomType->id]])
            ->all());
    }

    /**
     * @return array{0: Collection<int, RoomType>, 1: Collection<int, RatePlan>, 2: array<int, list<RateCalendarPlanData>>}
     */
    private function planRows(Property $property, CarbonInterface $start, CarbonInterface $end, string $currency, ?RatePlan $only = null): array
    {
        $roomTypes = $property->roomTypes()->active()->ordered()->get();
        $plans = $only === null
            ? $property->ratePlans()->active()->ordered()->with(['roomTypes', 'parent', 'arrivalWeekdays', 'inclusions.chargeItem'])->get()
            : new Collection([$only->loadMissing(['roomTypes', 'parent', 'arrivalWeekdays', 'inclusions.chargeItem'])]);
        $rateSourceIds = $only === null ? $plans->modelKeys() : array_values(array_filter([$only->id, $only->parent_rate_plan_id]));
        $packageSourceIds = $only === null
            ? $plans->where('pricing_basis', PricingBasis::Package)->modelKeys()
            : ($only->pricing_basis === PricingBasis::Package ? $rateSourceIds : []);

        $rates = Rate::query()
            ->whereIn('rate_plan_id', $rateSourceIds)
            ->whereIn('room_type_id', $roomTypes->modelKeys())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with('occupancyPrices', 'hourPrices')
            ->orderBy('date')
            ->get()
            ->groupBy(fn (Rate $rate): string => $rate->rate_plan_id.'-'.$rate->room_type_id);

        $packagePrices = PackagePrice::query()
            ->whereIn('rate_plan_id', $packageSourceIds)
            ->whereIn('room_type_id', $roomTypes->modelKeys())
            ->whereDate('arrival_from', '<=', $end->toDateString())
            ->whereDate('arrival_to', '>=', $start->toDateString())
            ->get()
            ->groupBy(fn (PackagePrice $price): string => $price->rate_plan_id.'-'.$price->room_type_id);

        $rows = [];

        foreach ($roomTypes as $roomType) {
            $rows[$roomType->id] = array_values($plans
                ->filter(fn (RatePlan $plan): bool => $plan->roomTypes->contains('id', $roomType->id))
                ->map(fn (RatePlan $plan): RateCalendarPlanData => RateCalendarPlanData::fromModel(
                    $plan,
                    $this->cells($plan, $roomType, $rates, $packagePrices, $start, $end, $currency),
                ))
                ->all());
        }

        return [$roomTypes, $plans, $rows];
    }

    /**
     * The units of the room type on each date of the range.
     *
     * @return list<RateCalendarAvailabilityData>
     */
    private function availability(RoomType $roomType, CarbonInterface $start, CarbonInterface $end): array
    {
        return array_map(
            RateCalendarAvailabilityData::fromNight(...),
            $this->availabilityService->nights($roomType, $start, $end->addDay()),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Collection<int, Rate>>  $rates  Keyed by `{plan id}-{room type id}`.
     * @param  \Illuminate\Support\Collection<string, Collection<int, PackagePrice>>  $packagePrices  Keyed the same way.
     * @return list<RateCalendarCellData>
     */
    private function cells(RatePlan $plan, RoomType $roomType, \Illuminate\Support\Collection $rates, \Illuminate\Support\Collection $packagePrices, CarbonInterface $start, CarbonInterface $end, string $currency): array
    {
        $own = $rates->get($plan->id.'-'.$roomType->id, new Collection);

        if ($plan->pricing_basis === PricingBasis::DayUse) {
            return array_values($own->map(fn (Rate $rate): RateCalendarCellData => RateCalendarCellData::forDayUse($rate))->all());
        }

        if ($plan->pricing_basis === PricingBasis::Package) {
            return $this->packageCells($plan, $own, $packagePrices->get(($plan->parent_rate_plan_id ?? $plan->id).'-'.$roomType->id, new Collection), $start, $end, $currency);
        }

        if ($plan->parent_rate_plan_id === null) {
            return array_values($own->map(fn (Rate $rate): RateCalendarCellData => RateCalendarCellData::fromRate($rate, $rate->amount))->all());
        }

        $cells = [];

        foreach ($rates->get($plan->parent_rate_plan_id.'-'.$roomType->id, new Collection) as $parentRate) {
            if ($parentRate->amount !== null) {
                $cells[$parentRate->date->toDateString()] = RateCalendarCellData::priceOnly(
                    $parentRate->date->toDateString(),
                    $this->derivedAmount($plan, $parentRate->date, $parentRate->amount, $currency),
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
     * A package plan shows the price of the whole stay on every date in a price period, and the restrictions of the
     * dates that have a rate row. A date on a weekday the package cannot start on is marked.
     *
     * @param  Collection<int, Rate>  $rates
     * @param  Collection<int, PackagePrice>  $prices
     * @return list<RateCalendarCellData>
     */
    private function packageCells(RatePlan $plan, Collection $rates, Collection $prices, CarbonInterface $start, CarbonInterface $end, string $currency): array
    {
        $byDate = $rates->keyBy(fn (Rate $rate): string => $rate->date->toDateString());
        $allowed = $plan->arrivalWeekdays->map(fn ($row): Weekday => $row->weekday);
        $cells = $byDate->map(fn (Rate $rate): RateCalendarCellData => RateCalendarCellData::fromRate($rate, null))->all();

        foreach ($prices as $price) {
            $last = min($price->arrival_to->toDateString(), $end->toDateString());

            for ($date = CarbonImmutable::parse(max($price->arrival_from->toDateString(), $start->toDateString())); $date->toDateString() <= $last; $date = $date->addDay()) {
                $key = $date->toDateString();
                $cells[$key] = RateCalendarCellData::forPackage(
                    $byDate->get($key),
                    $key,
                    $plan->parent_rate_plan_id === null ? $price->amount : $this->derivedAmount($plan, $date, $price->amount, $currency),
                    $allowed->isNotEmpty() && ! $allowed->contains(Weekday::forDate($date)),
                );
            }
        }

        ksort($cells);

        return array_values($cells);
    }

    /**
     * The parent's price for the date after the derivation, as the preview on the Pricing tab shows it.
     */
    private function derivedAmount(RatePlan $plan, CarbonInterface $date, string $amount, string $currency): string
    {
        $zero = Money::zero($currency);
        $night = NightPrice::of($date, Money::of($amount, $currency), $zero, $zero);

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
        $pricedUntil = $this->ratesEndingCheck->pricedUntilByPlan(array_values(array_map(intval(...), $ownPricePlans->modelKeys())));

        return array_values($ownPricePlans->map(function (RatePlan $plan) use ($roomTypes, $pricedUntil, $today): ?RateCalendarEndingData {
            $names = array_values(array_map(strval(...), $roomTypes
                ->filter(fn (RoomType $roomType): bool => $plan->roomTypes->contains('id', $roomType->id)
                    && $this->ratesEndingCheck->isEnding($pricedUntil[$plan->id][$roomType->id] ?? null, $today))
                ->pluck('name')
                ->all()));

            return $names === [] ? null : RateCalendarEndingData::fromModel($plan, $names);
        })->filter()->all());
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

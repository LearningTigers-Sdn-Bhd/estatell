<?php

namespace App\Services\Channels;

use App\Enums\ChannelSellMode;
use App\Enums\PackageUnit;
use App\Enums\PricingBasis;
use App\Enums\Weekday;
use App\Models\PackagePrice;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\NightAvailability;
use App\Services\Rates\DerivedRateResolver;
use App\Services\Rates\Pricing\NightPrice;
use App\Services\Rates\Pricing\QuoteRefused;
use App\Services\Rates\Pricing\StayContext;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Calculates what the channels should see, at send time: free units of a room type, and the price and selling
 * restrictions of a room type and rate plan pair. It reads only and keeps no copy.
 *
 * - A date with no price, or a price of zero, is sent as stop-sell with no rate, because estatell cannot sell it.
 * - Min stay is sent as min stay on arrival, which is how estatell checks it. A missing max stay is sent as 0 (none).
 * - The price is the nightly price before any long-stay discount. A flat package is its stay price over its nights.
 */
class ChannelAriValues
{
    public function __construct(
        private AvailabilityService $availability,
        private DerivedRateResolver $resolver,
        private ChannelCapability $capability,
    ) {}

    /**
     * Free units by date, for each room type of one property.
     *
     * @param  iterable<RoomType>  $roomTypes
     * @return array<int, array<string, array<string, int>>> `[room type id][date] => ['availability' => units]`
     */
    public function availability(iterable $roomTypes, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = [];

        foreach ($this->availability->nightsForRoomTypes($roomTypes, $from, $to->addDay()) as $roomTypeId => $nights) {
            foreach ($nights as $night) {
                /** @var NightAvailability $night */
                $result[$roomTypeId][$night->date->toDateString()] = ['availability' => $night->available];
            }
        }

        return $result;
    }

    /**
     * The values of one pair by date: `rate` (per room) or `rates` (per person, by adults), and the restrictions. `$sellMode` is how the pair was sent to the channel manager (the mapping's), not what the plan would be sent as today.
     *
     * @return array<string, array<string, mixed>>
     */
    public function restrictions(RatePlan $plan, RoomType $roomType, CarbonImmutable $from, CarbonImmutable $to, string $currency, ChannelSellMode $sellMode = ChannelSellMode::PerRoom): array
    {
        $plan->loadMissing(['parent.childPrices', 'childPrices', 'arrivalWeekdays']);
        $isPackage = $plan->pricing_basis === PricingBasis::Package;
        $flatNights = $isPackage ? (int) $plan->fixed_nights : null;
        $allowedDays = $isPackage ? $this->capability->allowedArrivalDays($plan) : null;
        $own = Rate::query()
            ->where('rate_plan_id', $plan->id)
            ->where('room_type_id', $roomType->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn (Rate $rate): string => $rate->date->toDateString());
        $occupancies = $this->occupancies($sellMode, $roomType);
        $prices = $isPackage ? $this->packagePrices($plan, $roomType, $from, $to, $currency, $occupancies) : $this->nightlyPrices($plan, $roomType, $from, $to, $currency, $occupancies);
        $values = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $row = $own->get($key);
            $price = $prices[$key] ?? null;
            $price = $price !== null && count($price) === count($occupancies) ? $price : null;
            $closedByWeekday = $allowedDays !== null && ! in_array(Weekday::forDate($date), $allowedDays, true);

            $value = [
                'stop_sell' => $price === null || (bool) $row?->stop_sell,
                'closed_to_arrival' => $closedByWeekday || (bool) $row?->closed_to_arrival,
                'closed_to_departure' => (bool) $row?->closed_to_departure,
                'min_stay_arrival' => $flatNights ?? max(1, (int) $row?->min_stay),
                'max_stay' => $flatNights ?? (int) $row?->max_stay,
            ];

            if ($price !== null) {
                $value += $sellMode === ChannelSellMode::PerPerson
                    ? ['rates' => array_map(fn (int $adults): array => ['occupancy' => $adults, 'rate' => $price[$adults]], $occupancies)]
                    : ['rate' => $price[$roomType->max_adults]];
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Prices of an own-rate or derived plan, by date then adults. A date without a rate amount has none.
     *
     * @param  list<int>  $occupancies
     * @return array<string, array<int, string>>
     */
    private function nightlyPrices(RatePlan $plan, RoomType $roomType, CarbonImmutable $from, CarbonImmutable $to, string $currency, array $occupancies): array
    {
        $pricingPlan = $plan->parent ?? $plan;
        $dates = Rate::query()
            ->where('rate_plan_id', $pricingPlan->id)
            ->where('room_type_id', $roomType->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('amount')
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date): CarbonImmutable => CarbonImmutable::parse($date));
        $strategy = app($pricingPlan->pricing_basis->strategy());
        $prices = [];

        foreach ($this->runs($dates->values()->all()) as [$start, $end]) {
            foreach ($occupancies as $adults) {
                $stay = new StayContext($pricingPlan, $roomType, $adults, [], 1, $currency);

                try {
                    $nights = $strategy->priceStay($stay, $start, $end->addDay());
                } catch (QuoteRefused) {
                    continue;
                }

                foreach ($nights as $night) {
                    $night = $plan->parent === null ? $night : $this->resolver->resolve($night, $plan, $strategy->discountUnits($stay));
                    $this->put($prices, $night->date->toDateString(), $adults, $night->total());
                }
            }
        }

        return $prices;
    }

    /**
     * Prices of a flat package: its stay price over its nights, on every date inside a price period.
     *
     * @param  list<int>  $occupancies
     * @return array<string, array<int, string>>
     */
    private function packagePrices(RatePlan $plan, RoomType $roomType, CarbonImmutable $from, CarbonImmutable $to, string $currency, array $occupancies): array
    {
        $pricingPlan = $plan->parent ?? $plan;
        $nights = max(1, (int) $plan->fixed_nights);
        $periods = PackagePrice::query()
            ->where('rate_plan_id', $pricingPlan->id)
            ->where('room_type_id', $roomType->id)
            ->whereDate('arrival_from', '<=', $to->toDateString())
            ->whereDate('arrival_to', '>=', $from->toDateString())
            ->get();
        $prices = [];

        foreach ($periods as $period) {
            $start = CarbonImmutable::parse($period->arrival_from)->max($from);
            $end = CarbonImmutable::parse($period->arrival_to)->min($to);

            foreach ($occupancies as $adults) {
                $stayTotal = $this->packageStay($pricingPlan, $roomType, $period, $adults, $currency, $plan);

                for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                    $perNight = $stayTotal->dividedBy($nights, RoundingMode::HalfUp);
                    $this->put($prices, $date->toDateString(), $adults, $perNight);
                }
            }
        }

        return $prices;
    }

    private function packageStay(RatePlan $pricingPlan, RoomType $roomType, PackagePrice $period, int $adults, string $currency, RatePlan $plan): Money
    {
        $amount = Money::of($period->amount, $currency);
        $extra = Money::zero($currency);

        if ($pricingPlan->package_unit === PackageUnit::PerRoom) {
            $base = $amount;
            $extra = Money::of($pricingPlan->extra_adult_amount, $currency)->multipliedBy(max(0, $adults - $roomType->base_occupancy));
        } else {
            $base = $amount->multipliedBy($adults);
        }

        $night = NightPrice::of(CarbonImmutable::parse($period->arrival_from), $base, $extra, Money::zero($currency));

        return ($plan->parent === null ? $night : $this->resolver->resolve($night, $plan, 1))->total();
    }

    /**
     * The adult counts Channex gets a price for: every count for a per-person plan, else the top one.
     *
     * @return list<int>
     */
    private function occupancies(ChannelSellMode $sellMode, RoomType $roomType): array
    {
        return $sellMode === ChannelSellMode::PerPerson ? range(1, $roomType->max_adults) : [$roomType->max_adults];
    }

    /**
     * Set a price. A zero or negative price is no price, so the date is sent as stop-sell.
     *
     * @param  array<string, array<int, string>>  $prices
     */
    private function put(array &$prices, string $date, int $adults, Money $price): void
    {
        if ($price->isPositive()) {
            $prices[$date][$adults] = (string) $price->getAmount();
        }
    }

    /**
     * Groups consecutive dates into `[start, end]` runs.
     *
     * @param  array<int, CarbonImmutable>  $dates
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function runs(array $dates): array
    {
        $runs = [];

        foreach ($dates as $date) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][1]->addDay()->isSameDay($date)) {
                $runs[$last][1] = $date;

                continue;
            }

            $runs[] = [$date, $date];
        }

        return $runs;
    }
}

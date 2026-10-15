<?php

namespace App\Services\Onboarding;

use App\Data\Onboarding\OnboardingBasicRateRowData;
use App\Enums\PricingBasis;
use App\Enums\SellUnit;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Support\Collection;

/**
 * Reads the Basic rate table back from the saved rates: one row per active room type sold by room, in Room types order.
 * The weekday and weekend price come from the first week of rates, from today in the property time zone. It only reads.
 */
class BasicRateRows
{
    public function __construct(private BasicRatePlans $basicRatePlans) {}

    /**
     * The rows, and whether "Same every night" starts selected: every saved row has one price for all 7 nights.
     *
     * @return array{rows: Collection<int, OnboardingBasicRateRowData>, sameEveryNight: bool}
     */
    public function forProperty(Property $property): array
    {
        $weekendDays = $property->settings?->weekend_nights->isoWeekdays() ?? [5, 6];
        $roomTypes = $property->roomTypes()->active()->where('sell_unit', SellUnit::Room)->ordered()->with('ratePlans')->get();
        $plans = $this->basicRatePlans->forProperty($property);
        $from = now($property->settings->time_zone ?? config('app.timezone'))->startOfDay();

        $rates = Rate::query()
            ->whereIn('room_type_id', $roomTypes->modelKeys())
            ->whereIn('rate_plan_id', $plans->pluck('id')->all())
            ->whereBetween('date', [$from->toDateString(), $from->addDays(6)->toDateString()])
            ->whereNotNull('amount')
            ->orderBy('date')
            ->get();

        $same = true;

        $rows = $roomTypes->map(function (RoomType $roomType) use ($plans, $rates, $weekendDays, &$same): OnboardingBasicRateRowData {
            $plan = $plans->first(fn (RatePlan $candidate): bool => $roomType->ratePlans->contains('id', $candidate->id));
            $own = $rates->where('room_type_id', $roomType->id)->where('rate_plan_id', $plan instanceof RatePlan ? $plan->id : 0);
            $weekday = $own->first(fn (Rate $rate): bool => ! in_array($rate->date->dayOfWeekIso, $weekendDays, true));
            $weekend = $own->first(fn (Rate $rate): bool => in_array($rate->date->dayOfWeekIso, $weekendDays, true));

            if ($own->isNotEmpty() && $own->pluck('amount')->unique()->count() > 1) {
                $same = false;
            }

            return new OnboardingBasicRateRowData(
                roomTypeId: $roomType->id,
                name: $roomType->name,
                priceBy: $plan instanceof RatePlan ? $plan->pricing_basis : PricingBasis::PerRoom,
                weekday: $weekday?->amount,
                weekend: $weekend?->amount,
                hasSavedPrices: $own->isNotEmpty(),
                baseOccupancy: $roomType->base_occupancy,
            );
        })->values();

        return ['rows' => $rows, 'sameEveryNight' => $same];
    }
}

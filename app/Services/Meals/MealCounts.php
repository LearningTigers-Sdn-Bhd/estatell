<?php

namespace App\Services\Meals;

use App\Data\Meals\MealInclusionData;
use App\Enums\UnitStatus;
use App\Models\MealPeriod;
use App\Models\Property;
use App\Models\RatePlanInclusion;
use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The meals the kitchen must prepare on one date, calculated from the units that are in house or still to arrive
 * and the meal inclusions of their rate plans. It reads only and stores nothing. A unit counts its adults and
 * children, and `MealEntitlementResolver` says which dates and periods it is owed.
 */
class MealCounts
{
    public function __construct(private MealEntitlementResolver $resolver) {}

    /**
     * One row for each meal period with guests on the date, in the order of the meal start times.
     *
     * @return Collection<int, MealCount>
     */
    public function forDate(Property $property, CarbonImmutable $date): Collection
    {
        $day = $date->toDateString();

        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [UnitStatus::CheckedIn->value, UnitStatus::Confirmed->value])
            ->whereDate('arrival_date', '<=', $day)
            ->whereDate('departure_date', '>=', $day)
            ->whereHas('ratePlan.inclusions', fn ($inclusions) => $inclusions->whereNotNull('meal_period_id'))
            ->with(['ratePlan.inclusions' => fn ($inclusions) => $inclusions->whereNotNull('meal_period_id')->with('mealPeriod')])
            ->get();

        $guests = [];

        foreach ($units as $unit) {
            $inclusions = $unit->ratePlan->inclusions->map(fn (RatePlanInclusion $inclusion): MealInclusionData => MealInclusionData::fromInclusion($inclusion));

            $entitlements = $this->resolver->resolve(
                CarbonImmutable::parse($unit->arrival_date->toDateString()),
                CarbonImmutable::parse($unit->departure_date->toDateString()),
                $unit->adults + $unit->children,
                $inclusions,
            );

            foreach ($entitlements->where('date', $day) as $entitlement) {
                $guests[$entitlement->mealPeriodId] = ($guests[$entitlement->mealPeriodId] ?? 0) + $entitlement->guests;
            }
        }

        return MealPeriod::query()
            ->where('property_id', $property->id)
            ->whereKey(array_keys($guests))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (MealPeriod $period): MealCount => new MealCount($period->id, $period->name, $guests[$period->id]))
            ->values();
    }
}

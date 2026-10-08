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
 * The meals the kitchen must prepare, calculated from the units that are in house, still to arrive or already gone
 * and the meal inclusions of their rate plans. It reads only and stores nothing. A unit counts its adults and
 * children, and `MealEntitlementResolver` says which dates and periods it is owed. Confirmed, Checked-in and
 * Checked-out units count (an early check-out has already moved the departure date); Cancelled and No-show units do not.
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
        return $this->between($property, $date, $date);
    }

    /**
     * One row for each date and meal period with guests from `$from` to `$to`, both included, sorted by date and then
     * by meal start time. The units are loaded once for the whole range.
     *
     * @return Collection<int, MealCount>
     */
    public function between(Property $property, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $units = ReservationUnit::query()
            ->where('property_id', $property->id)
            ->whereIn('status', [UnitStatus::CheckedIn->value, UnitStatus::Confirmed->value, UnitStatus::CheckedOut->value])
            ->whereDate('arrival_date', '<=', $to->toDateString())
            ->whereDate('departure_date', '>=', $from->toDateString())
            ->whereHas('ratePlan.inclusions', fn ($inclusions) => $inclusions->whereNotNull('meal_period_id'))
            ->with(['ratePlan.inclusions' => fn ($inclusions) => $inclusions->whereNotNull('meal_period_id')->with('mealPeriod')])
            ->get();

        $first = $from->toDateString();
        $last = $to->toDateString();

        /** @var array<string, array{date: string, periodId: int, adults: int, children: int}> $totals */
        $totals = [];

        foreach ($units as $unit) {
            $inclusions = $unit->ratePlan->inclusions->map(fn (RatePlanInclusion $inclusion): MealInclusionData => MealInclusionData::fromInclusion($inclusion));

            $entitlements = $this->resolver->resolve(
                CarbonImmutable::parse($unit->arrival_date->toDateString()),
                CarbonImmutable::parse($unit->departure_date->toDateString()),
                $unit->adults + $unit->children,
                $inclusions,
            );

            foreach ($entitlements as $entitlement) {
                if ($entitlement->date < $first || $entitlement->date > $last) {
                    continue;
                }

                $key = $entitlement->date.'|'.$entitlement->mealPeriodId;
                $total = $totals[$key] ?? ['date' => $entitlement->date, 'periodId' => $entitlement->mealPeriodId, 'adults' => 0, 'children' => 0];
                $totals[$key] = [
                    ...$total,
                    'adults' => $total['adults'] + $unit->adults,
                    'children' => $total['children'] + $unit->children,
                ];
            }
        }

        $periods = MealPeriod::query()
            ->where('property_id', $property->id)
            ->whereKey(array_unique(array_column($totals, 'periodId')))
            ->get()
            ->keyBy('id');

        return collect($totals)
            ->filter(fn (array $total): bool => $periods->has($total['periodId']))
            ->sortBy(fn (array $total): array => [$total['date'], $periods->get($total['periodId'])->starts_at, $total['periodId']])
            ->map(fn (array $total): MealCount => new MealCount(
                $total['date'],
                $total['periodId'],
                $periods->get($total['periodId'])->name,
                $total['adults'],
                $total['children'],
            ))
            ->values();
    }

    /**
     * Whether any rate plan of the property includes a meal. Without one, every meal count is empty.
     */
    public function propertyHasMealInclusions(Property $property): bool
    {
        return RatePlanInclusion::query()
            ->whereNotNull('meal_period_id')
            ->whereHas('ratePlan', fn ($plans) => $plans->where('property_id', $property->id))
            ->exists();
    }
}

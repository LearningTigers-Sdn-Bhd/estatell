<?php

namespace App\Services\Meals;

use App\Data\Meals\MealEntitlementData;
use App\Data\Meals\MealInclusionData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MealEntitlementResolver
{
    /**
     * The meals a unit is owed, one row per date and meal period, sorted by date then meal start time.
     * Every guest gets every meal. Two inclusions for one period never feed a guest twice.
     * The resolver never filters by whether a period is active, so old bookings keep their meals.
     *
     * @param  iterable<MealInclusionData>  $inclusions
     * @return Collection<int, MealEntitlementData>
     */
    public function resolve(CarbonImmutable $arrival, CarbonImmutable $departure, int $guests, iterable $inclusions): Collection
    {
        if ($guests < 1) {
            return collect();
        }

        /** @var array<string, array{startsAt: string, date: string, periodId: int}> $rows */
        $rows = [];

        foreach ($inclusions as $inclusion) {
            $last = $inclusion->endsOn->lastDate($departure);

            for ($date = $inclusion->startsOn->firstDate($arrival); $date->lte($last); $date = $date->addDay()) {
                $day = $date->toDateString();
                $rows[$day.'|'.$inclusion->mealPeriodId] = [
                    'startsAt' => $inclusion->mealPeriodStartsAt,
                    'date' => $day,
                    'periodId' => $inclusion->mealPeriodId,
                ];
            }
        }

        return collect($rows)
            ->sortBy([['date', 'asc'], ['startsAt', 'asc'], ['periodId', 'asc']])
            ->map(fn (array $row): MealEntitlementData => new MealEntitlementData($row['date'], $row['periodId'], $guests))
            ->values();
    }
}

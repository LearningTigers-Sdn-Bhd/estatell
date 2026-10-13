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
     * With the unit's times of day it also trims the first and last day: a meal on the arrival date counts only when
     * `$arrivalTime` is before the meal's end, and a meal on the departure date only when `$departureTime` is after
     * the meal's start. Days in between are not changed. Without times nothing is trimmed.
     *
     * @param  iterable<MealInclusionData>  $inclusions
     * @return Collection<int, MealEntitlementData>
     */
    public function resolve(CarbonImmutable $arrival, CarbonImmutable $departure, int $guests, iterable $inclusions, ?string $arrivalTime = null, ?string $departureTime = null): Collection
    {
        if ($guests < 1) {
            return collect();
        }

        /** @var array<string, array{startsAt: string, date: string, periodId: int}> $rows */
        $rows = [];
        $arrivalDay = $arrival->toDateString();
        $departureDay = $departure->toDateString();

        foreach ($inclusions as $inclusion) {
            $last = $inclusion->endsOn->lastDate($departure);

            for ($date = $inclusion->startsOn->firstDate($arrival); $date->lte($last); $date = $date->addDay()) {
                $day = $date->toDateString();

                if ($arrivalTime !== null && $day === $arrivalDay && substr($arrivalTime, 0, 5) >= substr($inclusion->mealPeriodEndsAt, 0, 5)) {
                    continue;
                }

                if ($departureTime !== null && $day === $departureDay && substr($departureTime, 0, 5) <= substr($inclusion->mealPeriodStartsAt, 0, 5)) {
                    continue;
                }

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

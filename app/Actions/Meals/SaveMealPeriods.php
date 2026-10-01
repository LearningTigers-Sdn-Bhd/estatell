<?php

namespace App\Actions\Meals;

use App\Models\MealPeriod;
use App\Models\Property;
use App\Models\User;
use App\Services\Meals\MealPeriodOverlap;
use Illuminate\Support\Facades\DB;

class SaveMealPeriods
{
    public function __construct(private MealPeriodOverlap $overlap) {}

    /**
     * Save the property's meal periods from one list.
     *
     * A row with an id keeps that id, so package inclusions and the kitchen report keep their reference.
     * A row without one is new. Periods are never deleted, so every existing period must be in the list.
     *
     * Returns false, without writing, when the list does not match the property's current periods
     * or when two active periods overlap. A save that changes nothing is not logged.
     *
     * @param  list<array{id: int|null, name: string, starts_at: string, ends_at: string, active: bool}>  $periods  Times are `HH:MM`.
     */
    public function __invoke(Property $property, User $actor, array $periods): bool
    {
        return DB::transaction(function () use ($property, $actor, $periods): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $existing = $property->mealPeriods()->get()->keyBy('id');
            $keptIds = array_values(array_filter(array_column($periods, 'id')));

            if (
                array_diff($keptIds, $existing->keys()->all()) !== []
                || array_diff($existing->keys()->all(), $keptIds) !== []
                || $this->overlap->overlappingPositions($periods) !== []
            ) {
                return false;
            }

            $before = $existing->sortBy(['starts_at', 'id'])->map(fn (MealPeriod $period): array => $this->snapshot($period))->values()->all();
            $after = array_map(fn (array $row): array => [
                'name' => $row['name'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'active' => $row['active'],
            ], $periods);
            usort($after, fn (array $a, array $b): int => $a['starts_at'] <=> $b['starts_at']);

            if ($before === $after && count($keptIds) === count($periods)) {
                return true;
            }

            // Free every name that changes first, so names can move between rows in one save.
            foreach ($periods as $row) {
                if ($row['id'] !== null && $existing[$row['id']]->name !== $row['name']) {
                    $property->mealPeriods()->whereKey($row['id'])->update(['name' => 'moving-'.$row['id']]);
                }
            }

            foreach ($periods as $row) {
                $attributes = ['name' => $row['name'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'active' => $row['active']];

                if ($row['id'] === null) {
                    $property->mealPeriods()->create($attributes);
                } else {
                    $property->mealPeriods()->whereKey($row['id'])->update($attributes);
                }
            }

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('meal_periods.updated')
                ->withProperties(['property_id' => $property->id, 'before' => $before, 'after' => $after])
                ->log('Meal periods updated');

            return true;
        });
    }

    /** @return array{name: string, starts_at: string, ends_at: string, active: bool} */
    private function snapshot(MealPeriod $period): array
    {
        return [
            'name' => $period->name,
            'starts_at' => substr($period->starts_at, 0, 5),
            'ends_at' => substr($period->ends_at, 0, 5),
            'active' => $period->active,
        ];
    }
}

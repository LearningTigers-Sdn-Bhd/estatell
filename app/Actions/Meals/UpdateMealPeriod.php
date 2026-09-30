<?php

namespace App\Actions\Meals;

use App\Models\MealPeriod;
use App\Models\Property;
use App\Models\User;
use App\Services\Meals\MealPeriodOverlap;
use Illuminate\Support\Facades\DB;

class UpdateMealPeriod
{
    public function __construct(private MealPeriodOverlap $overlap) {}

    /**
     * Save a meal period's name, times and availability.
     *
     * Returns false when an active period now overlaps it. A no-op save is not logged.
     *
     * @param  array{name: string, starts_at: string, ends_at: string, active: bool}  $attributes  Validated fields.
     */
    public function __invoke(User $actor, MealPeriod $mealPeriod, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $mealPeriod, $attributes): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($mealPeriod->property_id);
            $mealPeriod = MealPeriod::query()->lockForUpdate()->findOrFail($mealPeriod->id);

            if ($attributes['active'] && $this->overlap->exists($property, $attributes['starts_at'], $attributes['ends_at'], $mealPeriod->id)) {
                return false;
            }

            foreach (['starts_at', 'ends_at'] as $time) {
                if (substr($mealPeriod->{$time}, 0, 5) === $attributes[$time]) {
                    unset($attributes[$time]);
                }
            }

            $mealPeriod->fill($attributes);
            $changed = array_keys($mealPeriod->getDirty());

            if ($changed === []) {
                return true;
            }

            $mealPeriod->save();

            activity()
                ->performedOn($mealPeriod)
                ->causedBy($actor)
                ->event('meal_period.updated')
                ->withProperties(['property_id' => $property->id, 'changed' => $changed])
                ->log('Meal period updated');

            return true;
        });
    }
}

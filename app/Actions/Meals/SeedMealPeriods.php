<?php

namespace App\Actions\Meals;

use App\Enums\DefaultMealPeriod;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SeedMealPeriods
{
    /**
     * Create Breakfast, Lunch and Dinner when the property has no meal periods yet.
     * A property that already has periods keeps every property-owned change.
     */
    public function __invoke(Property $property, string $countryCode): void
    {
        if ($countryCode !== 'MY') {
            throw new InvalidArgumentException("Meal-period presets are not available for country [{$countryCode}].");
        }

        DB::transaction(function () use ($property): void {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($property->mealPeriods()->exists()) {
                return;
            }

            foreach (DefaultMealPeriod::cases() as $default) {
                $property->mealPeriods()->create([
                    'name' => $default->label(),
                    'starts_at' => $default->startsAt(),
                    'ends_at' => $default->endsAt(),
                    'active' => true,
                ]);
            }
        });
    }
}

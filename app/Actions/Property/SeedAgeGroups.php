<?php

namespace App\Actions\Property;

use App\Models\Property;
use Illuminate\Support\Facades\DB;

class SeedAgeGroups
{
    /**
     * Create Infant 0-3 and Child 4-11 when the property has no age groups yet.
     * A property that already has groups keeps every property-owned change.
     */
    public function __invoke(Property $property): void
    {
        DB::transaction(function () use ($property): void {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            if ($property->ageGroups()->exists()) {
                return;
            }

            $property->ageGroups()->createMany([
                ['label' => 'Infant', 'min_age' => 0, 'max_age' => 3],
                ['label' => 'Child', 'min_age' => 4, 'max_age' => 11],
            ]);
        });
    }
}

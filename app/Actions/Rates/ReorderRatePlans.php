<?php

namespace App\Actions\Rates;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderRatePlans
{
    /**
     * Set the display order of the property's active rate plans.
     *
     * Returns false unless the ids are exactly the property's active plans.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Property $property, User $actor, array $orderedIds): bool
    {
        return DB::transaction(function () use ($property, $actor, $orderedIds): bool {
            $active = $property->ratePlans()->active()->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($active) || array_diff($orderedIds, $active) !== []) {
                return false;
            }

            foreach (array_values($orderedIds) as $index => $id) {
                $property->ratePlans()->whereKey($id)->update(['position' => $index + 1]);
            }

            activity()
                ->causedBy($actor)
                ->event('rate_plan.reordered')
                ->withProperties(['property_id' => $property->id, 'order' => array_values($orderedIds)])
                ->log('Rate plans reordered');

            return true;
        });
    }
}

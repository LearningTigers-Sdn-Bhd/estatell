<?php

namespace App\Actions\Catalogues;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderDiscounts
{
    /**
     * Set the display order of the property's active discounts.
     *
     * Returns false unless the ids are exactly the active discounts.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Property $property, User $actor, array $orderedIds): bool
    {
        return DB::transaction(function () use ($property, $actor, $orderedIds): bool {
            $active = $property->discounts()->active()->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($active) || array_diff($orderedIds, $active) !== [] || array_diff($active, $orderedIds) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                $property->discounts()->whereKey($id)->update(['position' => $index + 1]);
            }

            activity()
                ->causedBy($actor)
                ->event('discount.reordered')
                ->withProperties(['property_id' => $property->id, 'order' => $orderedIds])
                ->log('Discounts reordered');

            return true;
        });
    }
}

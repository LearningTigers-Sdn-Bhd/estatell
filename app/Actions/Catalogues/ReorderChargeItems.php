<?php

namespace App\Actions\Catalogues;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderChargeItems
{
    /**
     * Set the display order of the property's active charge items.
     *
     * Returns false unless the ids are exactly the active charge items.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Property $property, User $actor, array $orderedIds): bool
    {
        return DB::transaction(function () use ($property, $actor, $orderedIds): bool {
            $active = $property->chargeItems()->active()->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($active) || array_diff($orderedIds, $active) !== [] || array_diff($active, $orderedIds) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                $property->chargeItems()->whereKey($id)->update(['position' => $index + 1]);
            }

            activity()
                ->causedBy($actor)
                ->event('charge_item.reordered')
                ->withProperties(['property_id' => $property->id, 'order' => $orderedIds])
                ->log('Charge items reordered');

            return true;
        });
    }
}

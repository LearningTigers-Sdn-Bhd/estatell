<?php

namespace App\Actions\BookingEngine;

use App\Models\Amenity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderAmenities
{
    /**
     * Set the display order of every amenity. Returns false unless the ids are exactly the amenities that exist now.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(User $admin, array $orderedIds): bool
    {
        return DB::transaction(function () use ($admin, $orderedIds): bool {
            $existing = Amenity::query()->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($existing) || array_diff($orderedIds, $existing) !== [] || array_diff($existing, $orderedIds) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                Amenity::query()->whereKey($id)->update(['position' => $index + 1]);
            }

            activity()
                ->causedBy($admin)
                ->event('amenity.reordered')
                ->log('Amenities reordered');

            return true;
        });
    }
}

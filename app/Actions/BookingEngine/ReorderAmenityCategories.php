<?php

namespace App\Actions\BookingEngine;

use App\Models\AmenityCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderAmenityCategories
{
    /**
     * Set the display order of every category. Returns false unless the ids are exactly the categories that exist now.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(User $admin, array $orderedIds): bool
    {
        return DB::transaction(function () use ($admin, $orderedIds): bool {
            $existing = AmenityCategory::query()->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($existing) || array_diff($orderedIds, $existing) !== [] || array_diff($existing, $orderedIds) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                AmenityCategory::query()->whereKey($id)->update(['position' => $index + 1]);
            }

            activity()
                ->causedBy($admin)
                ->event('amenity_category.reordered')
                ->log('Amenity categories reordered');

            return true;
        });
    }
}

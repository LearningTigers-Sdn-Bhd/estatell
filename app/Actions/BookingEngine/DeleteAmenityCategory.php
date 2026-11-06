<?php

namespace App\Actions\BookingEngine;

use App\Models\AmenityCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteAmenityCategory
{
    /**
     * Delete an empty category. Returns false, and writes nothing, while any amenity (retired ones too) is in it.
     */
    public function __invoke(User $admin, AmenityCategory $category): bool
    {
        return DB::transaction(function () use ($admin, $category): bool {
            $category = AmenityCategory::query()->lockForUpdate()->findOrFail($category->id);

            if ($category->amenities()->exists()) {
                return false;
            }

            activity()
                ->performedOn($category)
                ->causedBy($admin)
                ->event('amenity_category.deleted')
                ->withProperties(['name' => $category->name, 'type' => $category->type->value])
                ->log('Amenity category deleted');

            $category->delete();

            return true;
        });
    }
}

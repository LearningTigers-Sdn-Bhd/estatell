<?php

namespace App\Actions\BookingEngine;

use App\Models\AmenityCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateAmenityCategory
{
    /**
     * Rename a category. The type never changes, because its amenities have that type.
     * Returns false when another category of the same type has this name (ignoring case).
     */
    public function __invoke(User $admin, AmenityCategory $category, string $name): bool
    {
        return DB::transaction(function () use ($admin, $category, $name): bool {
            $category = AmenityCategory::query()->lockForUpdate()->findOrFail($category->id);

            $taken = AmenityCategory::query()->where('type', $category->type->value)->whereKeyNot($category->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();

            if ($taken) {
                return false;
            }

            if ($category->name === $name) {
                return true;
            }

            $before = $category->name;
            $category->update(['name' => $name]);

            activity()
                ->performedOn($category)
                ->causedBy($admin)
                ->event('amenity_category.updated')
                ->withProperties(['from' => $before, 'to' => $name])
                ->log('Amenity category renamed');

            return true;
        });
    }
}

<?php

namespace App\Actions\BookingEngine;

use App\Models\Amenity;
use App\Models\AmenityCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateAmenity
{
    /**
     * Change the name and category of an amenity. The type never changes, because properties and room types already link to it,
     * so the category must be of the same type (the request checks this).
     * Returns false when another amenity of the same type has this name (ignoring case).
     */
    public function __invoke(User $admin, Amenity $amenity, string $name, AmenityCategory $category): bool
    {
        return DB::transaction(function () use ($admin, $amenity, $name, $category): bool {
            $amenity = Amenity::query()->with('category')->lockForUpdate()->findOrFail($amenity->id);
            $category = AmenityCategory::query()->lockForUpdate()->findOrFail($category->id);

            $taken = Amenity::query()->ofType($amenity->type)->whereKeyNot($amenity->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();

            if ($taken) {
                return false;
            }

            if ($amenity->name === $name && $amenity->amenity_category_id === $category->id) {
                return true;
            }

            $before = ['name' => $amenity->name, 'category' => $amenity->category->name];
            $amenity->update(['name' => $name, 'amenity_category_id' => $category->id]);

            activity()
                ->performedOn($amenity)
                ->causedBy($admin)
                ->event('amenity.updated')
                ->withProperties(['from' => $before, 'to' => ['name' => $name, 'category' => $category->name]])
                ->log('Amenity changed');

            return true;
        });
    }
}

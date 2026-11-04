<?php

namespace App\Actions\BookingEngine;

use App\Models\Amenity;
use App\Models\AmenityCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateAmenity
{
    /**
     * Add an amenity at the end of its category. The type comes from the category. Returns null when the type already has
     * an amenity with this name (ignoring case).
     */
    public function __invoke(User $admin, string $name, AmenityCategory $category): ?Amenity
    {
        return DB::transaction(function () use ($admin, $name, $category): ?Amenity {
            $category = AmenityCategory::query()->lockForUpdate()->findOrFail($category->id);

            if (Amenity::query()->ofType($category->type)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                return null;
            }

            $amenity = Amenity::query()->create([
                'name' => $name,
                'type' => $category->type,
                'amenity_category_id' => $category->id,
                'position' => ((int) Amenity::query()->max('position')) + 1,
                'active' => true,
            ]);

            activity()
                ->performedOn($amenity)
                ->causedBy($admin)
                ->event('amenity.created')
                ->withProperties(['name' => $name, 'type' => $category->type->value, 'category' => $category->name])
                ->log('Amenity added');

            return $amenity;
        });
    }
}

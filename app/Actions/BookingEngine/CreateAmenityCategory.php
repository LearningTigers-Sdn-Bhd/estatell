<?php

namespace App\Actions\BookingEngine;

use App\Enums\AmenityType;
use App\Models\AmenityCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateAmenityCategory
{
    /**
     * Add a category at the end of the list. Returns null when the type already has a category with this name (ignoring case).
     */
    public function __invoke(User $admin, string $name, AmenityType $type): ?AmenityCategory
    {
        return DB::transaction(function () use ($admin, $name, $type): ?AmenityCategory {
            if (AmenityCategory::query()->where('type', $type->value)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                return null;
            }

            $category = AmenityCategory::query()->create([
                'name' => $name,
                'type' => $type,
                'position' => ((int) AmenityCategory::query()->max('position')) + 1,
            ]);

            activity()
                ->performedOn($category)
                ->causedBy($admin)
                ->event('amenity_category.created')
                ->withProperties(['name' => $name, 'type' => $type->value])
                ->log('Amenity category added');

            return $category;
        });
    }
}

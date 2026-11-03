<?php

namespace App\Actions\BookingEngine;

use App\Enums\AmenityType;
use App\Models\Amenity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateAmenity
{
    /**
     * Add an amenity at the end of the list. Returns null when the type already has an amenity with this name (ignoring case).
     */
    public function __invoke(User $admin, string $name, AmenityType $type, string $category): ?Amenity
    {
        return DB::transaction(function () use ($admin, $name, $type, $category): ?Amenity {
            if (Amenity::query()->ofType($type)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                return null;
            }

            $amenity = Amenity::query()->create([
                'name' => $name,
                'type' => $type,
                'category' => $category,
                'position' => ((int) Amenity::query()->max('position')) + 1,
                'active' => true,
            ]);

            activity()
                ->performedOn($amenity)
                ->causedBy($admin)
                ->event('amenity.created')
                ->withProperties(['name' => $name, 'type' => $type->value, 'category' => $category])
                ->log('Amenity added');

            return $amenity;
        });
    }
}

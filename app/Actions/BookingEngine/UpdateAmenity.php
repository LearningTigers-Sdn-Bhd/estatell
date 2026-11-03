<?php

namespace App\Actions\BookingEngine;

use App\Models\Amenity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateAmenity
{
    /**
     * Change the name and category of an amenity. The type never changes, because properties and room types already link to it.
     * Returns false when another amenity of the same type has this name (ignoring case).
     */
    public function __invoke(User $admin, Amenity $amenity, string $name, string $category): bool
    {
        return DB::transaction(function () use ($admin, $amenity, $name, $category): bool {
            $amenity = Amenity::query()->lockForUpdate()->findOrFail($amenity->id);

            $taken = Amenity::query()->ofType($amenity->type)->whereKeyNot($amenity->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists();

            if ($taken) {
                return false;
            }

            if ($amenity->name === $name && $amenity->category === $category) {
                return true;
            }

            $before = ['name' => $amenity->name, 'category' => $amenity->category];
            $amenity->update(['name' => $name, 'category' => $category]);

            activity()
                ->performedOn($amenity)
                ->causedBy($admin)
                ->event('amenity.updated')
                ->withProperties(['from' => $before, 'to' => ['name' => $name, 'category' => $category]])
                ->log('Amenity changed');

            return true;
        });
    }
}

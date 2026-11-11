<?php

namespace App\Actions\BookingEngine;

use App\Enums\AmenityType;
use App\Models\Amenity;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetPropertyAmenity
{
    /**
     * Turn one amenity on or off for the property. The id must be a Property amenity, and a retired one can only be turned off.
     * Returns false, and writes nothing, otherwise. Asking for the state it already has changes and logs nothing.
     */
    public function __invoke(User $actor, Property $property, int $amenityId, bool $on): bool
    {
        return DB::transaction(function () use ($actor, $property, $amenityId, $on): bool {
            $amenity = Amenity::query()->ofType(AmenityType::Property)->lockForUpdate()->find($amenityId);

            if ($amenity === null) {
                return false;
            }

            if ($property->amenities()->whereKey($amenity->id)->exists() === $on) {
                return true;
            }

            if ($on && ! $amenity->active) {
                return false;
            }

            $on ? $property->amenities()->attach($amenity->id) : $property->amenities()->detach($amenity->id);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('property.amenities_changed')
                ->withProperties(['added' => $on ? [$amenity->id] : [], 'removed' => $on ? [] : [$amenity->id]])
                ->log('Property amenities changed');

            return true;
        });
    }
}

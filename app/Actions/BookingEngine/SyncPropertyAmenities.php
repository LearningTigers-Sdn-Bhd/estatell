<?php

namespace App\Actions\BookingEngine;

use App\Enums\AmenityType;
use App\Models\Amenity;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SyncPropertyAmenities
{
    /**
     * Set the property amenities. Every id must be a Property amenity that is active or already ticked on this property
     * (a retired amenity stays until the owner unticks it). Returns false, and writes nothing, otherwise.
     *
     * @param  list<int>  $amenityIds
     */
    public function __invoke(User $actor, Property $property, array $amenityIds): bool
    {
        return DB::transaction(function () use ($actor, $property, $amenityIds): bool {
            $amenityIds = array_values(array_unique($amenityIds));
            $current = $property->amenities()->pluck('amenities.id')->all();

            $allowed = Amenity::query()->ofType(AmenityType::Property)
                ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $current))
                ->whereIn('id', $amenityIds)
                ->count();

            if ($allowed !== count($amenityIds)) {
                return false;
            }

            $changes = $property->amenities()->sync($amenityIds);

            if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                activity()
                    ->performedOn($property)
                    ->causedBy($actor)
                    ->event('property.amenities_changed')
                    ->withProperties(['added' => $changes['attached'], 'removed' => $changes['detached']])
                    ->log('Property amenities changed');
            }

            return true;
        });
    }
}

<?php

namespace App\Actions\Rooms;

use App\Enums\AmenityType;
use App\Models\Amenity;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetRoomTypeAmenity
{
    /**
     * Turn one amenity on or off for the room type. The id must be a Room amenity, a retired one can only be turned off,
     * and an archived room type refuses every change. Returns false, and writes nothing, otherwise.
     * Asking for the state it already has changes and logs nothing.
     */
    public function __invoke(User $actor, RoomType $roomType, int $amenityId, bool $on): bool
    {
        return DB::transaction(function () use ($actor, $roomType, $amenityId, $on): bool {
            if ($roomType->archived_at !== null) {
                return false;
            }

            $amenity = Amenity::query()->ofType(AmenityType::Room)->lockForUpdate()->find($amenityId);

            if ($amenity === null) {
                return false;
            }

            if ($roomType->amenities()->whereKey($amenity->id)->exists() === $on) {
                return true;
            }

            if ($on && ! $amenity->active) {
                return false;
            }

            $on ? $roomType->amenities()->attach($amenity->id) : $roomType->amenities()->detach($amenity->id);

            activity()
                ->performedOn($roomType)
                ->causedBy($actor)
                ->event('room_type.amenities_changed')
                ->withProperties(['added' => $on ? [$amenity->id] : [], 'removed' => $on ? [] : [$amenity->id]])
                ->log('Room type amenities changed');

            return true;
        });
    }
}

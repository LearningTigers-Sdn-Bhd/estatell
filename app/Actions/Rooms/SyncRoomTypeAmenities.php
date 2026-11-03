<?php

namespace App\Actions\Rooms;

use App\Enums\AmenityType;
use App\Models\Amenity;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SyncRoomTypeAmenities
{
    /**
     * Set the room type amenities. Every id must be a Room amenity that is active or already ticked on this room type
     * (a retired amenity stays until it is unticked). Returns false, and writes nothing, otherwise or when the room type is archived.
     *
     * @param  list<int>  $amenityIds
     */
    public function __invoke(User $actor, RoomType $roomType, array $amenityIds): bool
    {
        return DB::transaction(function () use ($actor, $roomType, $amenityIds): bool {
            if ($roomType->archived_at !== null) {
                return false;
            }

            $amenityIds = array_values(array_unique($amenityIds));
            $current = $roomType->amenities()->pluck('amenities.id')->all();

            $allowed = Amenity::query()->ofType(AmenityType::Room)
                ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $current))
                ->whereIn('id', $amenityIds)
                ->count();

            if ($allowed !== count($amenityIds)) {
                return false;
            }

            $changes = $roomType->amenities()->sync($amenityIds);

            if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                activity()
                    ->performedOn($roomType)
                    ->causedBy($actor)
                    ->event('room_type.amenities_changed')
                    ->withProperties(['added' => $changes['attached'], 'removed' => $changes['detached']])
                    ->log('Room type amenities changed');
            }

            return true;
        });
    }
}

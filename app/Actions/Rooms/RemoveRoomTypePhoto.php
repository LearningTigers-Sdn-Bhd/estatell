<?php

namespace App\Actions\Rooms;

use App\Models\RoomType;
use App\Models\User;

class RemoveRoomTypePhoto
{
    /**
     * Delete one photo. Returns false when the room type is archived or has no such photo.
     */
    public function __invoke(User $actor, RoomType $roomType, int $mediaId): bool
    {
        $photo = $roomType->getMedia(RoomType::PHOTOS)->firstWhere('id', $mediaId);

        if ($roomType->archived_at !== null || $photo === null) {
            return false;
        }

        $photo->delete();

        activity()
            ->performedOn($roomType)
            ->causedBy($actor)
            ->event('room_type.updated')
            ->withProperties(['property_id' => $roomType->property_id, 'changed' => ['photos'], 'removed' => 1])
            ->log('Room type photo removed');

        return true;
    }
}

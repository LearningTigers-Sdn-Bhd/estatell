<?php

namespace App\Actions\Rooms;

use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ReorderRoomTypePhotos
{
    /**
     * Set the photo order; the first photo is the cover.
     *
     * Returns false unless the ids are exactly the room type's photos.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(User $actor, RoomType $roomType, array $orderedIds): bool
    {
        return DB::transaction(function () use ($actor, $roomType, $orderedIds): bool {
            $roomType = RoomType::query()->lockForUpdate()->findOrFail($roomType->id);
            $current = $roomType->getMedia(RoomType::PHOTOS)->pluck('id')->all();

            if ($roomType->archived_at !== null || count($orderedIds) !== count($current) || array_diff($orderedIds, $current) !== []) {
                return false;
            }

            Media::setNewOrder($orderedIds);

            activity()
                ->performedOn($roomType)
                ->causedBy($actor)
                ->event('room_type.updated')
                ->withProperties(['property_id' => $roomType->property_id, 'changed' => ['photo_order']])
                ->log('Room type photos reordered');

            return true;
        });
    }
}

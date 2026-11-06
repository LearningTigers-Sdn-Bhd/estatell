<?php

namespace App\Actions\Rooms;

use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UploadRoomTypePhotos
{
    /**
     * Add photos after the existing ones. Returns the reason when nothing was stored, or null.
     *
     * @param  list<UploadedFile>  $files
     */
    public function __invoke(User $actor, RoomType $roomType, array $files): ?string
    {
        return DB::transaction(function () use ($actor, $roomType, $files): ?string {
            $roomType = RoomType::query()->lockForUpdate()->findOrFail($roomType->id);

            if ($roomType->archived_at !== null) {
                return __(':name is archived. Restore it before editing.', ['name' => $roomType->name]);
            }

            if ($roomType->getMedia(RoomType::PHOTOS)->count() + count($files) > RoomType::PHOTO_LIMIT) {
                return __('A room type can have up to :count photos.', ['count' => RoomType::PHOTO_LIMIT]);
            }

            foreach ($files as $file) {
                $roomType->addMedia($file)
                    ->usingFileName(Str::uuid().'.'.$file->guessExtension())
                    ->toMediaCollection(RoomType::PHOTOS);
            }

            activity()
                ->performedOn($roomType)
                ->causedBy($actor)
                ->event('room_type.updated')
                ->withProperties(['property_id' => $roomType->property_id, 'changed' => ['photos'], 'added' => count($files)])
                ->log('Room type photos uploaded');

            return null;
        });
    }
}

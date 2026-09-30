<?php

namespace App\Actions\Rooms;

use App\Actions\Rates\ReconcileRoomTypeRatePlans;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreRoomType
{
    public function __construct(private ReconcileRoomTypeRatePlans $reconcileRatePlans) {}

    /**
     * Bring an archived room type back at the end of the list. It is linked to a rate plan again when it has no active one.
     *
     * Returns false when it is not archived, or an active room type already has the same name.
     */
    public function __invoke(User $actor, RoomType $roomType): bool
    {
        return DB::transaction(function () use ($actor, $roomType): bool {
            $roomType = RoomType::query()->lockForUpdate()->findOrFail($roomType->id);

            if ($roomType->archived_at === null) {
                return false;
            }

            $nameTaken = RoomType::query()
                ->where('property_id', $roomType->property_id)
                ->active()
                ->whereRaw('lower(name) = ?', [mb_strtolower($roomType->name)])
                ->exists();

            if ($nameTaken) {
                return false;
            }

            $roomType->forceFill([
                'archived_at' => null,
                'position' => ((int) RoomType::query()->where('property_id', $roomType->property_id)->max('position')) + 1,
            ])->save();

            ($this->reconcileRatePlans)($roomType);

            activity()
                ->performedOn($roomType)
                ->causedBy($actor)
                ->event('room_type.restored')
                ->withProperties(['property_id' => $roomType->property_id])
                ->log('Room type restored');

            return true;
        });
    }
}

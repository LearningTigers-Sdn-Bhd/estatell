<?php

namespace App\Actions\Rooms;

use App\Actions\Rates\ReconcileRoomTypeRatePlans;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateRoomType
{
    public function __construct(private ReconcileRoomTypeRatePlans $reconcileRatePlans) {}

    /**
     * Save changes to an active room type. A new sell unit re-checks its rate plan links.
     *
     * Returns false for an archived room type.
     *
     * @param  array<string, mixed>  $attributes  Validated room type fields.
     */
    public function __invoke(User $actor, RoomType $roomType, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $roomType, $attributes): bool {
            $roomType = RoomType::query()->lockForUpdate()->findOrFail($roomType->id);

            if ($roomType->archived_at !== null) {
                return false;
            }

            $roomType->fill($attributes);
            $changes = $roomType->getDirty();

            if ($changes === []) {
                return true;
            }

            $roomType->save();

            if ($roomType->wasChanged('sell_unit')) {
                ($this->reconcileRatePlans)($roomType);
            }

            activity()
                ->performedOn($roomType)
                ->causedBy($actor)
                ->event('room_type.updated')
                ->withProperties(['property_id' => $roomType->property_id, 'changed' => array_keys($changes)])
                ->log('Room type updated');

            return true;
        });
    }
}

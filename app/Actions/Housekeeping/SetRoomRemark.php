<?php

namespace App\Actions\Housekeeping;

use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetRoomRemark
{
    /**
     * Set the remark of a room, or clear it with null or blank text. A remark stays until someone removes it, so
     * changing the room status never touches it.
     */
    public function __invoke(User $actor, Room $room, ?string $remark): void
    {
        DB::transaction(function () use ($actor, $room, $remark): void {
            $roomStatus = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->firstOrFail();
            $remark = $remark === null || trim($remark) === '' ? null : trim($remark);

            if ($roomStatus->remark === $remark) {
                return;
            }

            $from = $roomStatus->remark;
            $roomStatus->forceFill(['remark' => $remark])->save();

            activity()
                ->performedOn($roomStatus)
                ->causedBy($actor)
                ->event('room_status.remark_set')
                ->withProperties([
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                    'from' => $from,
                    'to' => $remark,
                ])
                ->log('Room remark set');
        });
    }
}

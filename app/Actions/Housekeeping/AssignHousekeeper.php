<?php

namespace App\Actions\Housekeeping;

use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignHousekeeper
{
    /**
     * Assign a room to a housekeeper, or clear the assignment with null. The caller checks that the assignee is
     * active staff of the property.
     */
    public function __invoke(User $actor, Room $room, ?User $assignee): void
    {
        DB::transaction(function () use ($actor, $room, $assignee): void {
            $roomStatus = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->firstOrFail();

            if ($roomStatus->assigned_to === $assignee?->id) {
                return;
            }

            $from = $roomStatus->assigned_to;
            $roomStatus->forceFill(['assigned_to' => $assignee?->id])->save();

            activity()
                ->performedOn($roomStatus)
                ->causedBy($actor)
                ->event('room_status.assigned')
                ->withProperties([
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                    'from' => $from,
                    'to' => $assignee?->id,
                ])
                ->log('Room assigned');
        });
    }
}

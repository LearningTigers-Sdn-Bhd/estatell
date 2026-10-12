<?php

namespace App\Actions\Housekeeping;

use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetNeedsMaintenance
{
    /**
     * Raise or dismiss the "needs maintenance" flag of a room. The flag changes neither the status nor availability.
     */
    public function __invoke(User $actor, Room $room, bool $needsMaintenance): void
    {
        DB::transaction(function () use ($actor, $room, $needsMaintenance): void {
            $roomStatus = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->firstOrFail();

            if ($roomStatus->needs_maintenance === $needsMaintenance) {
                return;
            }

            $roomStatus->forceFill(['needs_maintenance' => $needsMaintenance])->save();

            activity()
                ->performedOn($roomStatus)
                ->causedBy($actor)
                ->event($needsMaintenance ? 'room_status.maintenance_flagged' : 'room_status.maintenance_cleared')
                ->withProperties([
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                ])
                ->log($needsMaintenance ? 'Room flagged for maintenance' : 'Room maintenance flag cleared');
        });
    }
}

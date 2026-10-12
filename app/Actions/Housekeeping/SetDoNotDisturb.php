<?php

namespace App\Actions\Housekeeping;

use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SetDoNotDisturb
{
    /**
     * Mark a room do-not-disturb for the given date, or clear it. The flag holds for that date only.
     */
    public function __invoke(User $actor, Room $room, bool $on, CarbonImmutable $date): void
    {
        DB::transaction(function () use ($actor, $room, $on, $date): void {
            $roomStatus = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->firstOrFail();
            $wanted = $on ? $date->toDateString() : null;

            if ($roomStatus->do_not_disturb_on?->toDateString() === $wanted) {
                return;
            }

            $roomStatus->forceFill(['do_not_disturb_on' => $wanted])->save();

            activity()
                ->performedOn($roomStatus)
                ->causedBy($actor)
                ->event('room_status.dnd_set')
                ->withProperties([
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                    'do_not_disturb' => $on,
                    'date' => $date->toDateString(),
                ])
                ->log('Room do not disturb set');
        });
    }
}

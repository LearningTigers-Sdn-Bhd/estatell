<?php

namespace App\Actions\Housekeeping;

use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetRoomPriority
{
    /**
     * Flag a room as a priority with an optional note, or clear the flag (which also clears the note).
     */
    public function __invoke(User $actor, Room $room, bool $priority, ?string $note): void
    {
        DB::transaction(function () use ($actor, $room, $priority, $note): void {
            $roomStatus = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->firstOrFail();
            $note = $priority ? $note : null;

            if ($roomStatus->is_priority === $priority && $roomStatus->priority_note === $note) {
                return;
            }

            $roomStatus->forceFill(['is_priority' => $priority, 'priority_note' => $note])->save();

            activity()
                ->performedOn($roomStatus)
                ->causedBy($actor)
                ->event('room_status.priority_set')
                ->withProperties([
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                    'priority' => $priority,
                    'note' => $note,
                ])
                ->log('Room priority set');
        });
    }
}

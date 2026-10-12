<?php

namespace App\Actions\Housekeeping;

use App\Enums\HousekeepingRefusal;
use App\Enums\HousekeepingStatus;
use App\Models\Room;
use App\Models\RoomStatus;
use App\Models\User;
use App\Services\Housekeeping\HousekeepingDate;
use App\Services\Housekeeping\RoomOutOfService;
use Illuminate\Support\Facades\DB;

class UpdateRoomStatus
{
    public function __construct(private RoomOutOfService $outOfService, private HousekeepingDate $housekeepingDate) {}

    /**
     * Move a room to its next housekeeping status. Returns the refusal, or null when the room is in the wanted
     * status afterwards. A room that is already in that status is not an error and is not logged again.
     */
    public function __invoke(User $actor, Room $room, HousekeepingStatus $status): ?HousekeepingRefusal
    {
        return DB::transaction(function () use ($actor, $room, $status): ?HousekeepingRefusal {
            $roomStatus = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->firstOrFail();
            $date = $this->housekeepingDate->for($room->property);

            if ($this->outOfService->block($room, $date) !== null) {
                return HousekeepingRefusal::OutOfService;
            }

            if ($roomStatus->status === $status) {
                return null;
            }

            if (! $roomStatus->status->canTransitionTo($status)) {
                return HousekeepingRefusal::InvalidTransition;
            }

            $from = $roomStatus->status;
            $roomStatus->forceFill([
                'status' => $status,
                'last_changed_by' => $actor->id,
                'last_changed_at' => now(),
            ])->save();

            activity()
                ->performedOn($roomStatus)
                ->causedBy($actor)
                ->event('room_status.changed')
                ->withProperties([
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                    'from' => $from->value,
                    'to' => $status->value,
                ])
                ->log('Room status changed');

            return null;
        });
    }
}

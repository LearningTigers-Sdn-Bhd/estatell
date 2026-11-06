<?php

namespace App\Actions\Housekeeping;

use App\Enums\HousekeepingRefusal;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Models\RoomStatus;
use App\Models\User;
use App\Services\Housekeeping\HousekeepingDate;
use App\Services\Housekeeping\RoomOutOfService;
use Illuminate\Support\Facades\DB;

class StartRoomCleaning
{
    public function __construct(private RoomOutOfService $outOfService, private HousekeepingDate $housekeepingDate) {}

    /**
     * Record IN: the housekeeper starts cleaning the room now. Refused while the room is blocked or already being
     * cleaned, which also makes a double tap harmless.
     */
    public function __invoke(User $actor, Room $room, User $housekeeper): ?HousekeepingRefusal
    {
        return DB::transaction(function () use ($actor, $room, $housekeeper): ?HousekeepingRefusal {
            RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->firstOrFail();
            $date = $this->housekeepingDate->for($room->property);

            if ($this->outOfService->block($room, $date) !== null) {
                return HousekeepingRefusal::OutOfService;
            }

            if (RoomCleaning::query()->where('room_id', $room->id)->whereNull('finished_at')->exists()) {
                return HousekeepingRefusal::CleaningInProgress;
            }

            $cleaning = RoomCleaning::query()->create([
                'property_id' => $room->property_id,
                'room_id' => $room->id,
                'date' => $date->toDateString(),
                'housekeeper_id' => $housekeeper->id,
                'started_at' => now(),
                'created_by' => $actor->id,
            ]);

            activity()
                ->performedOn($cleaning)
                ->causedBy($actor)
                ->event('room_cleaning.started')
                ->withProperties(['property_id' => $room->property_id, 'room_id' => $room->id, 'housekeeper_id' => $housekeeper->id])
                ->log('Room cleaning started');

            return null;
        });
    }
}

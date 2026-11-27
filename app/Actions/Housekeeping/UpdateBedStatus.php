<?php

namespace App\Actions\Housekeeping;

use App\Enums\HousekeepingRefusal;
use App\Enums\HousekeepingStatus;
use App\Models\Bed;
use App\Models\BedStatus;
use App\Models\RoomBlock;
use App\Models\User;
use App\Services\Housekeeping\HousekeepingDate;
use App\Services\Housekeeping\RoomOutOfService;
use Illuminate\Support\Facades\DB;

class UpdateBedStatus
{
    public function __construct(
        private RoomOutOfService $outOfService,
        private HousekeepingDate $housekeepingDate,
        private SyncDormRoomStatus $syncDormRoomStatus,
    ) {}

    /**
     * Move one dorm bed to its next housekeeping status, then set the dorm's room status from its beds. Returns the
     * refusal, or null when the bed is in the wanted status afterwards: a room block or a block of this bed refuses,
     * as does a move `canTransitionTo()` does not allow. See docs/19-housekeeping/04-dorm-beds.md.
     */
    public function __invoke(User $actor, Bed $bed, HousekeepingStatus $status): ?HousekeepingRefusal
    {
        return DB::transaction(function () use ($actor, $bed, $status): ?HousekeepingRefusal {
            $bedStatus = BedStatus::query()->where('bed_id', $bed->id)->lockForUpdate()->firstOrFail();
            $room = $bed->room;
            $date = $this->housekeepingDate->for($room->property);
            $bedBlocked = RoomBlock::query()->where('bed_id', $bed->id)->open()->overlapping($date, $date)->exists();

            if ($bedBlocked || $this->outOfService->block($room, $date) !== null) {
                return HousekeepingRefusal::OutOfService;
            }

            if ($bedStatus->status === $status) {
                return null;
            }

            if (! $bedStatus->status->canTransitionTo($status)) {
                return HousekeepingRefusal::InvalidTransition;
            }

            $from = $bedStatus->status;
            $bedStatus->forceFill(['status' => $status, 'last_changed_by' => $actor->id, 'last_changed_at' => now()])->save();
            ($this->syncDormRoomStatus)($room, $actor);

            activity()
                ->performedOn($bedStatus)
                ->causedBy($actor)
                ->event('bed_status.changed')
                ->withProperties([
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                    'bed_id' => $bed->id,
                    'from' => $from->value,
                    'to' => $status->value,
                ])
                ->log('Bed status changed');

            return null;
        });
    }
}

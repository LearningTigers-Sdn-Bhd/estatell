<?php

namespace App\Services\Housekeeping;

use App\Data\Housekeeping\RoomCleaningData;
use App\Data\Housekeeping\RoomCleaningStateData;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCleaning;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Reads the housekeeping IN and OUT times. It writes nothing. See docs/19-housekeeping/03-cleaning-times.md.
 */
class RoomCleanings
{
    /**
     * The cleaning to show for each room on a date, by room id: one still open (from any date), else the last of the
     * date.
     *
     * @return Collection<int, RoomCleaning>
     */
    public function latestByRoom(Property $property, string $date): Collection
    {
        return RoomCleaning::query()
            ->where('property_id', $property->id)
            ->where(fn ($query) => $query->whereDate('date', $date)->orWhereNull('finished_at'))
            ->with('housekeeper:id,name')
            ->orderByRaw('finished_at IS NULL')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->keyBy('room_id');
    }

    public function state(Room $room, ?RoomCleaning $cleaning, string $timeZone, bool $canUpdate, bool $canEdit, bool $outOfService): RoomCleaningStateData
    {
        $isOpen = $cleaning !== null && $cleaning->finished_at === null;

        return new RoomCleaningStateData(
            roomId: $room->id,
            roomNumber: $room->number,
            cleaning: $cleaning === null ? null : RoomCleaningData::fromModel($cleaning, $timeZone),
            canStart: $canUpdate && ! $isOpen && ! $outOfService,
            canFinish: $canUpdate && $isOpen,
            canEdit: $canEdit,
        );
    }

    /**
     * Who IN is recorded for. Housekeeping record their own. Front office record it for the room's assignee when
     * it has one, and can change it afterwards.
     */
    public function housekeeperFor(User $actor, Room $room, bool $canEdit): User
    {
        if (! $canEdit) {
            return $actor;
        }

        return $room->loadMissing('status.assignee')->status->assignee ?? $actor;
    }
}

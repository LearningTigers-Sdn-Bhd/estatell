<?php

namespace App\Actions\Rooms;

use App\Models\Room;
use App\Models\User;
use App\Services\Property\PropertyToday;
use Illuminate\Support\Facades\DB;

class ArchiveRoom
{
    public function __construct(private PropertyToday $today) {}

    /**
     * Hide a room from new work. Existing records keep pointing at it.
     * Its current and upcoming blocks end, so availability never subtracts them.
     *
     * Returns false when it is already archived.
     */
    public function __invoke(User $actor, Room $room): bool
    {
        return DB::transaction(function () use ($actor, $room): bool {
            $room = Room::query()->lockForUpdate()->findOrFail($room->id);

            if ($room->archived_at !== null) {
                return false;
            }

            $room->forceFill(['archived_at' => now()])->save();

            $room->blocks()->open()->whereDate('ends_on', '>=', $this->today->date($room->property)->toDateString())->update(['completed_at' => now()]);

            activity()
                ->performedOn($room)
                ->causedBy($actor)
                ->event('room.archived')
                ->withProperties(['property_id' => $room->property_id])
                ->log('Room archived');

            return true;
        });
    }
}

<?php

namespace App\Actions\Housekeeping;

use App\Models\RoomCleaning;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteRoomCleaning
{
    /**
     * Remove a cleaning recorded by mistake, such as IN tapped on the wrong room. The room's status does not change, and
     * the activity log keeps what was removed.
     */
    public function __invoke(User $actor, RoomCleaning $cleaning): void
    {
        DB::transaction(function () use ($actor, $cleaning): void {
            activity()
                ->performedOn($cleaning)
                ->causedBy($actor)
                ->event('room_cleaning.removed')
                ->withProperties([
                    'property_id' => $cleaning->property_id,
                    'room_id' => $cleaning->room_id,
                    'old' => [
                        'housekeeper_id' => $cleaning->housekeeper_id,
                        'started_at' => $cleaning->started_at->toIso8601String(),
                        'finished_at' => $cleaning->finished_at?->toIso8601String(),
                    ],
                ])
                ->log('Room cleaning removed');

            $cleaning->delete();
        });
    }
}

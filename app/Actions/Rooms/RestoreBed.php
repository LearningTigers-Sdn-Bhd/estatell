<?php

namespace App\Actions\Rooms;

use App\Models\Bed;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreBed
{
    /**
     * Bring an archived bed back at the end of its room's list.
     *
     * Returns false when it is not archived, its room is archived, or an active bed in the room has the same label.
     */
    public function __invoke(User $actor, Bed $bed): bool
    {
        return DB::transaction(function () use ($actor, $bed): bool {
            $bed = Bed::query()->lockForUpdate()->findOrFail($bed->id);

            if ($bed->archived_at === null || $bed->room->archived_at !== null) {
                return false;
            }

            $labelTaken = $bed->room->beds()
                ->active()
                ->whereRaw('lower(label) = ?', [mb_strtolower($bed->label)])
                ->exists();

            if ($labelTaken) {
                return false;
            }

            $bed->forceFill([
                'archived_at' => null,
                'position' => ((int) $bed->room->beds()->max('position')) + 1,
            ])->save();

            activity()
                ->performedOn($bed)
                ->causedBy($actor)
                ->event('bed.restored')
                ->withProperties(['property_id' => $bed->room->property_id, 'room_id' => $bed->room_id])
                ->log('Bed restored');

            return true;
        });
    }
}

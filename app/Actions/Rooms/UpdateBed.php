<?php

namespace App\Actions\Rooms;

use App\Models\Bed;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateBed
{
    /**
     * Rename an active bed. Only changed edits are logged.
     *
     * Returns false for an archived bed.
     *
     * @param  array<string, mixed>  $attributes  Validated bed fields.
     */
    public function __invoke(User $actor, Bed $bed, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $bed, $attributes): bool {
            $bed = Bed::query()->lockForUpdate()->findOrFail($bed->id);

            if ($bed->archived_at !== null) {
                return false;
            }

            $bed->fill($attributes);
            $changes = $bed->getDirty();

            if ($changes === []) {
                return true;
            }

            $bed->save();

            activity()
                ->performedOn($bed)
                ->causedBy($actor)
                ->event('bed.updated')
                ->withProperties(['property_id' => $bed->room->property_id, 'room_id' => $bed->room_id, 'changed' => array_keys($changes)])
                ->log('Bed updated');

            return true;
        });
    }
}

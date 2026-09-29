<?php

namespace App\Actions\Rooms;

use App\Models\Bed;
use App\Models\User;
use App\Services\Property\PropertyToday;
use Illuminate\Support\Facades\DB;

class ArchiveBed
{
    public function __construct(private PropertyToday $today) {}

    /**
     * Hide a bed from new work. Existing records keep pointing at it.
     * Its current and upcoming blocks end, so availability never subtracts them.
     *
     * Returns false when it is already archived.
     */
    public function __invoke(User $actor, Bed $bed): bool
    {
        return DB::transaction(function () use ($actor, $bed): bool {
            $bed = Bed::query()->lockForUpdate()->findOrFail($bed->id);

            if ($bed->archived_at !== null) {
                return false;
            }

            $bed->forceFill(['archived_at' => now()])->save();

            $bed->blocks()->open()->whereDate('ends_on', '>=', $this->today->date($bed->room->property)->toDateString())->update(['completed_at' => now()]);

            activity()
                ->performedOn($bed)
                ->causedBy($actor)
                ->event('bed.archived')
                ->withProperties(['property_id' => $bed->room->property_id, 'room_id' => $bed->room_id])
                ->log('Bed archived');

            return true;
        });
    }
}

<?php

namespace App\Actions\Boats;

use App\Enums\SailingKind;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetBoatJobDone
{
    /**
     * Tick or untick Done on a boat job, recording who and when. It works on past dates too, like the trip tick. A repeated
     * click changes and logs nothing. Logs `boat_job.done` or `boat_job.undone`. See docs/24-boats/04-sailings.md#jobs.
     */
    public function __invoke(User $actor, BoatSailing $job, bool $done): SailingResult
    {
        $property = Property::query()->findOrFail($job->property_id);

        return DB::transaction(function () use ($actor, $property, $job, $done): SailingResult {
            $locked = BoatSailing::query()
                ->where('property_id', $property->id)
                ->where('kind', SailingKind::Job->value)
                ->lockForUpdate()
                ->find($job->id);

            if ($locked === null) {
                return SailingResult::refused(SailingResult::REFUSAL_SAILING_NOT_FOUND);
            }

            if (($locked->done_at !== null) === $done) {
                return SailingResult::done($locked);
            }

            $locked->update([
                'done_at' => $done ? now() : null,
                'done_by' => $done ? $actor->id : null,
            ]);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event($done ? 'boat_job.done' : 'boat_job.undone')
                ->withProperties([
                    'property_id' => $property->id,
                    'boat_sailing_id' => $locked->id,
                    'date' => $locked->date->toDateString(),
                    'job_type' => $locked->job_type?->value,
                ])
                ->log($done ? 'Boat job done' : 'Boat job not done');

            return SailingResult::done($locked);
        });
    }
}

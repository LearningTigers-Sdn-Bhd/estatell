<?php

namespace App\Actions\Boats;

use App\Enums\SailingKind;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use Illuminate\Support\Facades\DB;

class DeleteBoatJob
{
    public function __construct(private BoatTripUsage $boatTripUsage) {}

    /**
     * Delete a boat job that is not done. Refused for a done job (`job_done`), a job that is gone (`sailing_not_found`) and
     * a date before the business date (`date_closed`). Its crew rows go with it. Logs `boat_job.deleted`. See
     * docs/24-boats/04-sailings.md#jobs.
     */
    public function __invoke(User $actor, BoatSailing $job): SailingResult
    {
        $property = Property::query()->findOrFail($job->property_id);

        if ($job->date->toDateString() < $this->boatTripUsage->businessDate($property)->toDateString()) {
            return SailingResult::refused(SailingResult::REFUSAL_DATE_CLOSED);
        }

        return DB::transaction(function () use ($actor, $property, $job): SailingResult {
            $locked = BoatSailing::query()
                ->where('property_id', $property->id)
                ->where('kind', SailingKind::Job->value)
                ->lockForUpdate()
                ->find($job->id);

            if ($locked === null) {
                return SailingResult::refused(SailingResult::REFUSAL_SAILING_NOT_FOUND);
            }

            if ($locked->done_at !== null) {
                return SailingResult::refused(SailingResult::REFUSAL_JOB_DONE);
            }

            $locked->delete();

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('boat_job.deleted')
                ->withProperties([
                    'property_id' => $property->id,
                    'boat_sailing_id' => $locked->id,
                    'date' => $locked->date->toDateString(),
                    'job_type' => $locked->job_type?->value,
                    'boat_id' => $locked->boat_id,
                ])
                ->log('Boat job deleted');

            return SailingResult::done();
        });
    }
}

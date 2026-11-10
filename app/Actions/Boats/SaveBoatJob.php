<?php

namespace App\Actions\Boats;

use App\Data\Boats\SailingData;
use App\Enums\BoatJobType;
use App\Enums\SailingKind;
use App\Models\BoatSailing;
use App\Models\Property;
use App\Models\User;
use App\Services\Boats\BoatTripUsage;
use App\Services\Boats\SailingChoices;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SaveBoatJob
{
    public function __construct(
        private SailingChoices $choices,
        private BoatTripUsage $boatTripUsage,
        private SyncBoatSailingCrew $syncCrew,
    ) {}

    /**
     * Add a boat job on a date, or change `$job`: the job type, the leave time (`H:i`), the boat, the crew and the note.
     * A job carries no guests: no direction, no arrive time, no seats and no fare. A changed job keeps its date. Refused for a
     * date before the business date (`date_closed`), a done job (`job_done`), a job that is gone (`sailing_not_found`) and
     * a boat or crew member that cannot be picked (`boat_unavailable`, `in_charge_required`). The request requires the note
     * for Other. Logs `boat_job.saved`. See docs/24-boats/04-sailings.md#jobs.
     */
    public function __invoke(User $actor, Property $property, CarbonImmutable $date, BoatJobType $type, string $leavesAt, ?string $note, SailingData $crew, ?BoatSailing $job = null): SailingResult
    {
        $date = $job?->date ?? $date;

        if ($date->toDateString() < $this->boatTripUsage->businessDate($property)->toDateString()) {
            return SailingResult::refused(SailingResult::REFUSAL_DATE_CLOSED);
        }

        $assistants = $crew->assistants();

        return DB::transaction(function () use ($actor, $property, $date, $type, $leavesAt, $note, $crew, $job, $assistants): SailingResult {
            $locked = null;

            if ($job !== null) {
                $locked = BoatSailing::query()
                    ->where('property_id', $property->id)
                    ->where('kind', SailingKind::Job->value)
                    ->with('crew')
                    ->lockForUpdate()
                    ->find($job->id);

                if ($locked === null) {
                    return SailingResult::refused(SailingResult::REFUSAL_SAILING_NOT_FOUND);
                }

                if ($locked->done_at !== null) {
                    return SailingResult::refused(SailingResult::REFUSAL_JOB_DONE);
                }
            }

            $refusal = $this->choices->refusal(
                $property->id,
                $crew->boatId,
                $crew->inChargeId,
                $assistants,
                $locked?->boat_id,
                $locked?->crew->pluck('id')->map(intval(...))->all() ?? [],
            );

            if ($refusal !== null) {
                return SailingResult::refused($refusal);
            }

            $attributes = [
                'job_type' => $type->value,
                'leaves_at' => substr($leavesAt, 0, 5).':00',
                'boat_id' => $crew->boatId,
                'note' => filled($note) ? trim((string) $note) : null,
            ];

            $sailing = $locked ?? new BoatSailing([
                'property_id' => $property->id,
                'date' => $date->toDateString(),
                'kind' => SailingKind::Job,
            ]);
            $sailing->fill($attributes)->save();

            ($this->syncCrew)($sailing, $crew->inChargeId, $assistants);

            activity()
                ->performedOn($property)
                ->causedBy($actor)
                ->event('boat_job.saved')
                ->withProperties([
                    'property_id' => $property->id,
                    'boat_sailing_id' => $sailing->id,
                    'date' => $date->toDateString(),
                    'job_type' => $type->value,
                    'leaves_at' => substr($leavesAt, 0, 5),
                    'boat_id' => $crew->boatId,
                    'in_charge' => $crew->inChargeId,
                    'assistants' => $assistants,
                    'note' => $attributes['note'],
                    'added' => $locked === null,
                ])
                ->log($locked === null ? 'Boat job added' : 'Boat job changed');

            return SailingResult::done($sailing);
        });
    }
}

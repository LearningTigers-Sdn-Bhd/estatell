<?php

namespace App\Data\Boats;

use App\Models\BoatCrewMember;
use App\Models\BoatSailing;
use Spatie\LaravelData\Data;

/**
 * One boat job on the Boat Trips board: a boat and crew going out without guests. `leavesAt` is `HH:MM`. The ids fill
 * the Edit Dialog; the names show in the row. `done` is the Done tick. See docs/24-boats/04-sailings.md#jobs.
 */
class BoatJobData extends Data
{
    /**
     * @param  list<string>  $assistants
     * @param  list<int>  $assistantIds
     */
    public function __construct(
        public int $id,
        public string $leavesAt,
        public string $jobType,
        public string $jobLabel,
        public ?int $boatId,
        public ?string $boat,
        public ?int $inChargeId,
        public ?string $inCharge,
        public array $assistantIds,
        public array $assistants,
        public ?string $note,
        public bool $done,
    ) {}

    public static function fromModel(BoatSailing $job): self
    {
        $inCharge = $job->crew->first(fn (BoatCrewMember $member): bool => (bool) $member->pivot->in_charge);
        $assistants = $job->crew->reject(fn (BoatCrewMember $member): bool => (bool) $member->pivot->in_charge)->values();

        return new self(
            id: $job->id,
            leavesAt: substr((string) $job->leaves_at, 0, 5),
            jobType: $job->job_type->value,
            jobLabel: $job->job_type->label(),
            boatId: $job->boat_id,
            boat: $job->boat?->name,
            inChargeId: $inCharge?->id,
            inCharge: $inCharge?->name,
            assistantIds: array_values($assistants->map(fn (BoatCrewMember $member): int => $member->id)->all()),
            assistants: array_values($assistants->map(fn (BoatCrewMember $member): string => $member->name)->all()),
            note: $job->note,
            done: $job->done_at !== null,
        );
    }

    /** "Din (in charge), Razak": the one in charge first, then the assistants. Null with nobody on the crew. */
    public function crewText(): ?string
    {
        $names = array_filter([
            $this->inCharge === null ? null : __(':name (in charge)', ['name' => $this->inCharge]),
            ...$this->assistants,
        ]);

        return $names === [] ? null : implode(', ', $names);
    }
}

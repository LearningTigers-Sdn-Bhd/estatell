<?php

namespace App\Data\Boats;

use App\Models\BoatCrewMember;
use App\Models\BoatSailing;
use App\Services\Boats\DateSailing;
use Spatie\LaravelData\Data;

/**
 * The boat and crew one running sailing or boat job of a date already has, in both directions, so the Change boat and crew Dialog
 * can hide them from the other sailings that leave at the same time. `key` is the board group key, `time` the leave
 * time (`HH:MM`). See docs/24-boats/05-boats-board.md#change-boat-and-crew.
 */
class BoatSailingCrewData extends Data
{
    /**
     * @param  list<int>  $crewIds
     */
    public function __construct(
        public string $key,
        public string $time,
        public ?int $boatId,
        public array $crewIds,
    ) {}

    public static function fromSailing(DateSailing $sailing): self
    {
        return new self(
            key: $sailing->key(),
            time: $sailing->leavesAt,
            boatId: $sailing->boat?->id,
            crewIds: array_values($sailing->crew->map(fn (BoatCrewMember $member): int => $member->id)->all()),
        );
    }

    /** A boat job uses its boat and crew from its leave time, so a guest sailing leaving then cannot take them. */
    public static function fromJob(BoatSailing $job): self
    {
        return new self(
            key: 'job-'.$job->id,
            time: substr((string) $job->leaves_at, 0, 5),
            boatId: $job->boat_id,
            crewIds: array_values($job->crew->map(fn (BoatCrewMember $member): int => $member->id)->all()),
        );
    }
}

<?php

namespace App\Data\Boats;

use App\Services\Boats\DateSailing;
use Spatie\LaravelData\Data;

/**
 * The boat and crew one running sailing of a date already has, in both directions, so the Change boat and crew Dialog
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
            crewIds: $sailing->crew->pluck('id')->map(intval(...))->values()->all(),
        );
    }
}

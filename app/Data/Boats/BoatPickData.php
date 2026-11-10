<?php

namespace App\Data\Boats;

use Spatie\LaravelData\Data;

/**
 * One boat of a date to pick in the Change boat Dialog on the Boat Trips board: a running sailing of a departure.
 * `key` is the board group key, `sailingId` is null for the main sailing that is not stored yet, `taken` counts the
 * guests on this boat and `seats` is its limit (null for none). See docs/24-boats/05-boats-board.md#row-menu.
 */
class BoatPickData extends Data
{
    public function __construct(
        public string $key,
        public string $direction,
        public int $departureId,
        public ?int $sailingId,
        public string $time,
        public ?string $boat,
        public int $taken,
        public ?int $seats,
    ) {}

    public static function fromGroup(BoatBoardGroupData $group): self
    {
        return new self(
            key: $group->key,
            direction: (string) $group->direction,
            departureId: (int) $group->departureId,
            sailingId: $group->sailingId,
            time: (string) $group->time,
            boat: $group->boat,
            taken: $group->taken,
            seats: $group->seats,
        );
    }
}

<?php

namespace App\Data\Boats;

use Spatie\LaravelData\Data;

/**
 * The main sailing of one departure on the manifest date, for the Boats and crew panel: the boat and crew it runs with.
 * `isStored` says the date has its own rows (the Default button goes back to the timetable); `otherBoats` counts the
 * boats added to the departure for the date.
 */
class BoatMainSailingData extends Data
{
    /**
     * @param  list<int>  $assistantIds
     */
    public function __construct(
        public int $departureId,
        public string $label,
        public string $direction,
        public ?int $boatId,
        public ?int $inChargeId,
        public array $assistantIds,
        public bool $isStored = false,
        public int $otherBoats = 0,
    ) {}
}

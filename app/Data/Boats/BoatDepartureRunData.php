<?php

namespace App\Data\Boats;

use Spatie\LaravelData\Data;

/** One departure of the manifest date, with the boat and crew set for it (empty when none). */
class BoatDepartureRunData extends Data
{
    public function __construct(
        public int $departureId,
        public string $label,
        public string $direction,
        public ?int $boatId,
        public ?string $crew,
    ) {}
}

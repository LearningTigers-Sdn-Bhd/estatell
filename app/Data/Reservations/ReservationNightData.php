<?php

namespace App\Data\Reservations;

use App\Models\UnitNight;
use Spatie\LaravelData\Data;

/**
 * One night of a unit. Expects `room`, `bed` and `ratePlan` to be loaded.
 */
class ReservationNightData extends Data
{
    public function __construct(
        public string $date,
        public ?string $assigned,
        public string $ratePlan,
        public string $amount,
        public bool $isOverridden,
    ) {}

    public static function fromModel(UnitNight $night): self
    {
        return new self(
            date: $night->date->toDateString(),
            assigned: $night->room?->number ?? $night->bed?->label,
            ratePlan: $night->ratePlan->name,
            amount: $night->amount,
            isOverridden: $night->override_amount !== null,
        );
    }
}

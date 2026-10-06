<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Change dates form shows for the dates typed so far: the unit total before and after, the early departure
 * fee of a shorter stay, and whether the new nights break a rate restriction (so the form asks for a reason when the
 * user may book past it).
 */
class UnitDatesPreviewData extends Data
{
    public function __construct(
        public ?string $previousTotal,
        public ?string $newTotal,
        public ?string $difference,
        public int $nights,
        public bool $isEarlyDeparture,
        public ?string $fee,
        public bool $restricted,
        public ?string $error,
    ) {}

    public static function refused(string $error): self
    {
        return new self(null, null, null, 0, false, null, false, $error);
    }
}

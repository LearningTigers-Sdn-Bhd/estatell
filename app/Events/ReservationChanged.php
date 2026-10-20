<?php

namespace App\Events;

use App\Models\ReservationUnit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A unit took nights off sale or put them back: booked, cancelled, no-show, reinstated, moved or its dates changed.
 * The dates are the nights that changed, `Y-m-d`, both included.
 */
class ReservationChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $propertyId,
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly ?int $roomTypeId = null,
        public readonly ?int $ratePlanId = null,
    ) {}

    /**
     * The nights of a unit, from arrival up to the night before departure. Pass the dates the unit had before a
     * change so the nights it gave up are sent too.
     */
    public static function forUnit(ReservationUnit $unit, ?string $previousArrival = null, ?string $previousDeparture = null): self
    {
        $dates = [$unit->arrival_date->toDateString(), $unit->departure_date->subDay()->max($unit->arrival_date)->toDateString()];

        if ($previousArrival !== null) {
            $dates[] = $previousArrival;
        }

        if ($previousDeparture !== null) {
            $dates[] = CarbonImmutable::parse($previousDeparture)->subDay()->toDateString();
        }

        return new self($unit->property_id, min($dates), max($dates), $unit->room_type_id);
    }
}

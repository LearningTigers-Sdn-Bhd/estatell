<?php

namespace App\Data\Reservations;

use App\Data\Shared\BadgeData;
use App\Models\ReservationUnit;
use Spatie\LaravelData\Data;

/**
 * One unit in the room box hover card: its room type, the room or bed it has (null when it has none), its status and
 * its dates. Dates are `Y-m-d`.
 */
class ReservationRoomBoxUnitData extends Data
{
    public function __construct(
        public string $roomType,
        public ?string $assigned,
        public BadgeData $status,
        public string $arrivalDate,
        public string $departureDate,
    ) {}

    /**
     * Expects `roomType` to be loaded.
     */
    public static function fromModel(ReservationUnit $unit, ?string $assigned): self
    {
        return new self(
            roomType: $unit->roomType->name,
            assigned: $assigned,
            status: BadgeData::fromUnitStatus($unit->status),
            arrivalDate: $unit->arrival_date->toDateString(),
            departureDate: $unit->departure_date->toDateString(),
        );
    }
}

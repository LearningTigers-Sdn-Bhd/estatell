<?php

namespace App\Data\Boats;

use App\Data\Shared\BadgeData;
use App\Enums\BoatDirection;
use App\Models\ReservationUnit;
use Spatie\LaravelData\Data;

/**
 * One open unit in the Set boats Dialog: its label, status and dates, and both directions as they are now. `canSetBoatIn`
 * and `canSetBoatOut` say whether the Dialog may change a direction; a direction it may not change is shown read only.
 */
class BoatSetupUnitData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public BadgeData $status,
        public string $arrival,
        public string $departure,
        public string $arrivalTime,
        public string $departureTime,
        public bool $canSetBoatIn,
        public bool $canSetBoatOut,
        public ?UnitBoatTripData $boatIn,
        public ?UnitBoatTripData $boatOut,
    ) {}

    /**
     * Expects `boatTrips.boatDeparture` to be loaded on the unit.
     */
    public static function fromModel(ReservationUnit $unit, string $label, bool $canSetBoatIn, bool $canSetBoatOut): self
    {
        return new self(
            id: $unit->id,
            label: $label,
            status: BadgeData::fromUnitStatus($unit->status),
            arrival: $unit->arrival_date->toDateString(),
            departure: $unit->departure_date->toDateString(),
            arrivalTime: substr($unit->arrival_time, 0, 5),
            departureTime: substr($unit->departure_time, 0, 5),
            canSetBoatIn: $canSetBoatIn,
            canSetBoatOut: $canSetBoatOut,
            boatIn: UnitBoatTripData::forDirection($unit->boatTrips, BoatDirection::In, $unit),
            boatOut: UnitBoatTripData::forDirection($unit->boatTrips, BoatDirection::Out, $unit),
        );
    }
}

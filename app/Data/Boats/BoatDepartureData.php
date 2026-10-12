<?php

namespace App\Data\Boats;

use App\Enums\BoatDirection;
use App\Models\BoatDeparture;
use Spatie\LaravelData\Data;

/** One row of the boat departures tables and the departure form. Times are `HH:MM`. */
class BoatDepartureData extends Data
{
    public function __construct(
        public int $id,
        public BoatDirection $direction,
        public string $leavesAt,
        public string $arrivesAt,
        public ?string $pickupPoint,
        public ?int $seats,
        public ?int $chargeItemId,
        public ?BoatFareData $fare,
        public bool $active,
    ) {}

    /**
     * The departure's fare is loaded with `chargeItem`, even when that fare is no longer available.
     */
    public static function fromModel(BoatDeparture $boatDeparture): self
    {
        return new self(
            id: $boatDeparture->id,
            direction: $boatDeparture->direction,
            leavesAt: substr($boatDeparture->leaves_at, 0, 5),
            arrivesAt: substr($boatDeparture->arrives_at, 0, 5),
            pickupPoint: $boatDeparture->pickup_point,
            seats: $boatDeparture->seats,
            chargeItemId: $boatDeparture->charge_item_id,
            fare: $boatDeparture->chargeItem === null ? null : BoatFareData::fromModel($boatDeparture->chargeItem),
            active: $boatDeparture->active,
        );
    }
}

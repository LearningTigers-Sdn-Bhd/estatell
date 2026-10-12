<?php

namespace App\Data\Boats;

use App\Models\BoatDeparture;
use Spatie\LaravelData\Data;

/**
 * An available departure to pick for a Boat in or Boat out trip on the booking form. `propertyTime` is the time the
 * unit takes when the trip is picked (`HH:MM`): the arrival at the property for Boat in, the departure for Boat out.
 * `seats` is null when the departure has no seat limit.
 */
class BoatDepartureOptionData extends Data
{
    public function __construct(
        public int $id,
        public string $direction,
        public string $leavesAt,
        public string $propertyTime,
        public ?string $pickupPoint,
        public ?int $seats,
    ) {}

    public static function fromModel(BoatDeparture $departure): self
    {
        return new self(
            id: $departure->id,
            direction: $departure->direction->value,
            leavesAt: substr($departure->leaves_at, 0, 5),
            propertyTime: substr($departure->direction->propertyTime($departure), 0, 5),
            pickupPoint: $departure->pickup_point,
            seats: $departure->seats,
        );
    }
}

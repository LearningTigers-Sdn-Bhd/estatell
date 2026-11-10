<?php

namespace App\Data\Boats;

use App\Enums\BoatDirection;
use App\Models\BoatDeparture;
use Spatie\LaravelData\Data;

/**
 * One row of the boat departures tables and the departure form. Times are `HH:MM`. `upcomingBookings` counts the bookings
 * whose upcoming trips use the departure, for the turn-off warning and the edit note. `meals` names the meal periods a
 * guest on it can get, for the Meals column.
 */
class BoatDepartureData extends Data
{
    /**
     * @param  list<string>  $meals
     */
    public function __construct(
        public int $id,
        public BoatDirection $direction,
        public string $leavesAt,
        public string $arrivesAt,
        public ?string $pickupPoint,
        public ?int $seats,
        public ?int $chargeItemId,
        public ?BoatFareData $fare,
        public ?int $defaultBoatId = null,
        public ?string $defaultBoatName = null,
        public ?int $inChargeId = null,
        /** @var list<int> */
        public array $assistantIds = [],
        /** @var list<string> The crew names for the table: the one in charge first, then the assistants. */
        public array $crewNames = [],
        public bool $active = true,
        public int $upcomingBookings = 0,
        public array $meals = [],
    ) {}

    /**
     * The departure's fare is loaded with `chargeItem`, even when that fare is no longer available. The default crew is loaded
     * with `crew`, the one in charge first.
     *
     * @param  list<string>  $meals
     */
    public static function fromModel(BoatDeparture $boatDeparture, int $upcomingBookings = 0, array $meals = []): self
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
            defaultBoatId: $boatDeparture->default_boat_id,
            defaultBoatName: $boatDeparture->defaultBoat?->name,
            inChargeId: $boatDeparture->crew->first(fn ($member) => (bool) $member->pivot->in_charge)?->id,
            assistantIds: $boatDeparture->crew->reject(fn ($member) => (bool) $member->pivot->in_charge)->pluck('id')->values()->all(),
            crewNames: $boatDeparture->crew->pluck('name')->values()->all(),
            active: $boatDeparture->active,
            upcomingBookings: $upcomingBookings,
            meals: $meals,
        );
    }
}

<?php

namespace App\Services\Boats;

use App\Data\Boats\BoatTripData;
use App\Enums\BoatDirection;
use App\Features\Boats;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\UnitBoatTrip;
use Laravel\Pennant\Feature;

/**
 * The boat trips that Add unit copies from the reservation's first unit. Reads only. A scheduled trip whose departure
 * is no longer available is skipped and named for the toast, and every trip is skipped while the Boats feature is off.
 * Add unit is never refused for a trip. See docs/24-boats/02-boat-trips.md#booking-form.
 */
class CopyableBoatTrips
{
    /**
     * @return array{trips: list<BoatTripData>, skipped: list<string>} The trips to copy, and the skipped trips as `Boat in 09:00`.
     */
    public function forNewUnit(Reservation $reservation, Property $property): array
    {
        $result = ['trips' => [], 'skipped' => []];

        if (! Feature::for($property)->active(Boats::class)) {
            return $result;
        }

        $first = $reservation->units()->oldest('id')->first(['id', 'arrival_time', 'departure_time']);

        if ($first === null) {
            return $result;
        }

        $trips = UnitBoatTrip::query()
            ->where('reservation_unit_id', $first->id)
            ->with('boatDeparture')
            ->get()
            ->sortBy(fn (UnitBoatTrip $trip): int => $trip->direction === BoatDirection::In ? 0 : 1);

        foreach ($trips as $trip) {
            if (! $trip->mode->needsDeparture()) {
                $time = substr($trip->direction === BoatDirection::In ? $first->arrival_time : $first->departure_time, 0, 5);
                $result['trips'][] = new BoatTripData($trip->direction, $trip->mode, null, $time, $trip->note);

                continue;
            }

            $departure = $trip->boatDeparture;

            if ($departure === null || ! $departure->active) {
                $result['skipped'][] = $departure === null
                    ? $trip->direction->label()
                    : $trip->direction->label().' '.substr($departure->leaves_at, 0, 5);

                continue;
            }

            $result['trips'][] = new BoatTripData($trip->direction, $trip->mode, $departure->id, null, $trip->note);
        }

        return $result;
    }
}

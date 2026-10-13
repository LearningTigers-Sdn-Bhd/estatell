<?php

namespace App\Data\Boats;

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Models\ReservationUnit;
use App\Models\UnitBoatTrip;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * One direction of a unit's boat trip as the Boat row shows it. `summary` is the readable line: "Scheduled boat · 09:00
 * Kuala Besut → 09:45", or "Own boat · 11:30 · Guest's own speedboat". A trip on a departure that is no longer available
 * says "unavailable" and keeps its departure, so staff see it and pick another. `time` is the unit's time, read for a
 * charter or own boat trip only.
 */
class UnitBoatTripData extends Data
{
    public function __construct(
        public BoatDirection $direction,
        public BoatMode $mode,
        public string $modeLabel,
        public ?int $boatDepartureId,
        public bool $departureAvailable,
        public ?string $time,
        public ?string $note,
        public string $summary,
    ) {}

    /**
     * The trip of one direction, or null when the unit has none. Expects `boatDeparture` to be loaded on the trip.
     *
     * @param  Collection<int, UnitBoatTrip>  $trips
     */
    public static function forDirection(Collection $trips, BoatDirection $direction, ReservationUnit $unit): ?self
    {
        $trip = $trips->first(fn (UnitBoatTrip $trip): bool => $trip->direction === $direction);

        if ($trip === null) {
            return null;
        }

        $departure = $trip->boatDeparture;
        $available = $departure !== null && $departure->active;
        $time = $trip->mode->needsDeparture() ? null : substr($direction === BoatDirection::In ? $unit->arrival_time : $unit->departure_time, 0, 5);

        $parts = [$trip->mode->label()];

        if ($departure !== null) {
            $parts[] = substr($departure->leaves_at, 0, 5).($departure->pickup_point !== null ? ' '.$departure->pickup_point : '').' → '.substr($departure->arrives_at, 0, 5);
        } elseif ($time !== null) {
            $parts[] = $time;
        }

        if ($trip->note !== null) {
            $parts[] = $trip->note;
        }

        if (! $available && $departure !== null) {
            $parts[] = __('unavailable');
        }

        return new self(
            direction: $direction,
            mode: $trip->mode,
            modeLabel: $trip->mode->label(),
            boatDepartureId: $trip->boat_departure_id,
            departureAvailable: $available,
            time: $time,
            note: $trip->note,
            summary: implode(' · ', $parts),
        );
    }
}

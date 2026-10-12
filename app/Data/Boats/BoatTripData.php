<?php

namespace App\Data\Boats;

use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use Spatie\LaravelData\Data;

/**
 * One direction of a unit's boat trip as staff set it. A scheduled trip names its departure; a charter or own boat
 * trip names its time (`H:i`) and an optional note. `SetBoatTrip` checks it against the unit and the departure.
 */
class BoatTripData extends Data
{
    public function __construct(
        public BoatDirection $direction,
        public BoatMode $mode,
        public ?int $boatDepartureId = null,
        public ?string $time = null,
        public ?string $note = null,
    ) {}

    /**
     * The trip of one validated booking request direction (`mode`, `boat_departure_id`, `time`, `note`). A scheduled
     * trip keeps no time, and a charter or own boat trip keeps no departure.
     *
     * @param  array{mode: string, boat_departure_id?: int|string|null, time?: string|null, note?: string|null}  $input
     */
    public static function fromInput(array $input, BoatDirection $direction): self
    {
        $mode = BoatMode::from($input['mode']);
        $scheduled = $mode->needsDeparture();

        return new self(
            direction: $direction,
            mode: $mode,
            boatDepartureId: $scheduled && isset($input['boat_departure_id']) ? (int) $input['boat_departure_id'] : null,
            time: $scheduled || blank($input['time'] ?? null) ? null : (string) $input['time'],
            note: filled($input['note'] ?? null) ? (string) $input['note'] : null,
        );
    }
}

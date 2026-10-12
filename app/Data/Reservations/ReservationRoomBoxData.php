<?php

namespace App\Data\Reservations;

use App\Enums\ReservationRoomBoxState;
use App\Enums\Tone;
use Spatie\LaravelData\Data;

/**
 * The room box of a reservation: room or bed numbers, or an icon state with a tooltip. Hovering the box opens a card that lists the units.
 * `numbers` holds at most two, and `more` counts the other assigned rooms. `count` is the number of units without a
 * room or bed, for the count badge of a group. `isBed` picks the bed icon.
 */
class ReservationRoomBoxData extends Data
{
    /**
     * @param  list<string>  $numbers
     * @param  list<ReservationRoomBoxUnitData>  $units  Every unit of the reservation (live or not) with its room, status and dates, for the hover card.
     */
    public function __construct(
        public ReservationRoomBoxState $state,
        public string $label,
        public Tone $tone,
        public ?string $tooltip,
        public array $numbers,
        public int $more,
        public int $count,
        public bool $isBed,
        public array $units,
    ) {}
}

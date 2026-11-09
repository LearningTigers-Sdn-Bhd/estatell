<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the group Check in all form lists when it opens: one row for each Confirmed unit of the reservation. A row is
 * `ready` when the business date is inside the stay or the unit was not logged in time (`endedStay`), and otherwise
 * carries a `note` (for example the arrival date). `moveTotal` is the unit total after a move to today.
 */
class CheckInAllPreviewData extends Data
{
    /**
     * @param  list<array{unitId: int, label: string, guest: string|null, room: string|null, cardsMissing: int, ready: bool, note: string|null, endedStay: bool, moveTotal: string|null}>  $rows
     */
    public function __construct(
        public array $rows,
        public bool $canCheckInWithoutCard,
        public ?string $error,
        public ?CheckInAllEndedStayData $endedStay = null,
    ) {}

    public static function refused(string $error): self
    {
        return new self([], false, $error);
    }
}

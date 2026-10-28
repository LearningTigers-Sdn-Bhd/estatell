<?php

namespace App\Actions\FrontDesk;

use App\Actions\Reservations\BookingResult;
use App\Models\Reservation;

/**
 * What `CheckInWalkIn` returns: the saved reservation, or null when nothing was saved. A refused booking comes back as
 * its `BookingResult`; `amountChanged` is true when the server total was not `expectedTotal`. A unit refused in a walk-in that is already checked out comes back as `unitRefused()`, with the unit alone and nothing saved. `units` has one row per
 * unit in unit order, and `collected` is the sum of everything taken.
 */
class WalkInResult
{
    /**
     * @param  list<WalkInUnitResult>  $units
     */
    private function __construct(
        public readonly ?Reservation $reservation,
        public readonly ?BookingResult $booking,
        public readonly bool $amountChanged,
        public readonly array $units,
        public readonly string $collected,
    ) {}

    /**
     * @param  list<WalkInUnitResult>  $units
     */
    public static function done(Reservation $reservation, array $units, string $collected): self
    {
        return new self($reservation, null, false, $units, $collected);
    }

    public static function bookingRefused(BookingResult $booking): self
    {
        return new self(null, $booking, false, [], '0.00');
    }

    /**
     * A walk-in saved as already checked out whose unit was refused: nothing is saved, and `units` holds the refused unit.
     */
    public static function unitRefused(WalkInUnitResult $unit): self
    {
        return new self(null, null, false, [$unit], '0.00');
    }

    public static function amountChanged(): self
    {
        return new self(null, null, true, [], '0.00');
    }

    public function isRefused(): bool
    {
        return $this->reservation === null;
    }

    public function checkedIn(): int
    {
        return count(array_filter($this->units, fn (WalkInUnitResult $unit): bool => $unit->isCheckedIn()));
    }
}

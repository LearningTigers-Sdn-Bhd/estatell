<?php

namespace App\Data\Facilities;

use Spatie\LaravelData\Data;

/**
 * What the Book facility Sheet sends. Times are `HH:MM` on `bookingDate`; a session sends its own times.
 * `unitAmount` is a decimal string; null keeps the list price. `UpdateFacilityBooking` ignores `facilityId`.
 * `chargeTo` is a reservation booking's folio: `f{folio id}`, or `u{unit id}` for a unit with no folio yet. An event
 * booking sends none.
 * See docs/33-facilities/04-facility-bookings.md#book-facility-sheet.
 */
class FacilityBookingInputData extends Data
{
    public function __construct(
        public int $facilityId,
        public string $bookingDate,
        public string $startsAt,
        public string $endsAt,
        public int $quantity,
        public ?string $unitAmount,
        public ?string $notes,
        public ?string $chargeTo = null,
    ) {}
}

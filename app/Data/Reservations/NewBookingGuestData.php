<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One guest tab of the booking form. The first tab is the booker. `guestId` links an existing guest; null creates one
 * from `profile`. `updateProfile` saves `profile` onto a linked guest. `unitIndex` is the unit the guest stays in,
 * counting the units in the order the lines add them (a Bed line adds one unit for each bed). Null means not staying,
 * which only the booker may be. See docs/12-reservations/05-booking-form.md.
 */
class NewBookingGuestData extends Data
{
    /**
     * @param  array<string, mixed>  $profile  Validated profile fields, as `GuestRequest::profileAttributes()` returns them.
     */
    public function __construct(
        public ?int $guestId = null,
        public array $profile = [],
        public bool $updateProfile = false,
        public ?int $unitIndex = null,
    ) {}
}

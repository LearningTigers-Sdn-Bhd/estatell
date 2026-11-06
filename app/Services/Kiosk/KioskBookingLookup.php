<?php

namespace App\Services\Kiosk;

use App\Models\ReservationUnit;

/**
 * The answer of `KioskBookingFinder`: the stay and the steps the kiosk must run, or the kiosk error code that refused it.
 */
final readonly class KioskBookingLookup
{
    public const string AMBIGUOUS = 'ambiguous_reference';

    public const string BOOKING_NOT_FOUND = 'booking_not_found';

    public const string ARRIVAL_NOT_REACHED = 'arrival_not_reached';

    /** The property has no open business date yet, so nobody can check in. */
    public const string NOT_OPEN = 'check_in_not_open';

    /**
     * @param  array{id: bool, registration_card: bool}|null  $steps
     * @param  array{status: string, check_out_open: bool, key_available: bool, room_number: string|null}|null  $state
     */
    private function __construct(
        public ?ReservationUnit $stay,
        public ?string $guestName,
        public ?array $steps,
        public ?string $refusal,
        public ?array $state = null,
    ) {}

    /**
     * `state` says whether the stay is still to check in or already in-house, whether check-out is open, and, only
     * while the kiosk may still show a key, the room (`key_available`, see `KioskStays::KEY_WINDOW_MINUTES`).
     *
     * @param  array{id: bool, registration_card: bool}  $steps
     * @param  array{status: string, check_out_open: bool, key_available: bool, room_number: string|null}  $state
     */
    public static function found(ReservationUnit $stay, string $guestName, array $steps, array $state): self
    {
        return new self($stay, $guestName, $steps, null, $state);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, null, null, $refusal);
    }
}

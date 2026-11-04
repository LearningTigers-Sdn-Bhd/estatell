<?php

namespace App\Services\Kiosk;

use App\Models\ReservationUnit;

/**
 * The answer of `KioskBookingFinder`: the stay and the steps the kiosk must run, or the kiosk error code that refused it.
 */
final readonly class KioskBookingLookup
{
    public const string BOOKING_NOT_FOUND = 'booking_not_found';

    public const string ARRIVAL_NOT_REACHED = 'arrival_not_reached';

    /** The property has no open business date yet, so nobody can check in. */
    public const string NOT_OPEN = 'check_in_not_open';

    /**
     * @param  array{id: bool, registration_card: bool}|null  $steps
     */
    private function __construct(
        public ?ReservationUnit $stay,
        public ?string $guestName,
        public ?array $steps,
        public ?string $refusal,
    ) {}

    /**
     * @param  array{id: bool, registration_card: bool}  $steps
     */
    public static function found(ReservationUnit $stay, string $guestName, array $steps): self
    {
        return new self($stay, $guestName, $steps, null);
    }

    public static function refused(string $refusal): self
    {
        return new self(null, null, null, $refusal);
    }
}

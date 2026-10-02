<?php

namespace App\Data\Reservations;

use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * One row of the Guests tab: a guest on a unit, the booker who does not stay, or a unit without a guest (`name` null).
 * `guestId` and `phone` are null for staff without `guests.view`.
 */
class ReservationGuestData extends Data
{
    /**
     * @param  list<string>  $roles  "Booker" and "Primary".
     */
    public function __construct(
        public ?int $guestId,
        public ?string $name,
        public ?string $phone,
        public ?int $unitId,
        public ?string $unitLabel,
        public ?BadgeData $unitStatus,
        public array $roles,
    ) {}
}

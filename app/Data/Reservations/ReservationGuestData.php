<?php

namespace App\Data\Reservations;

use App\Data\Guests\GuestOptionData;
use App\Data\Shared\BadgeData;
use Spatie\LaravelData\Data;

/**
 * One row of the Guests tab: a guest on a unit, the booker who does not stay, or a unit without a guest (`name` null).
 * `guestId` and `phone` are null for staff without `guests.view`. `cardUnitGuestId` opens the registration card and is
 * null for staff without `registration-card.sign`. `billing` says how the guest relates to a folio ("Pays unit folio",
 * "Own folio", or on the primary guest "Unit folio billed to X"). `canBill` shows the Bill to guest action. `guest` is
 * the guest with its profile, for the expanded row; null for staff without `guests.view` and on a unit with no guest.
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
        public ?int $cardUnitGuestId = null,
        public ?string $billing = null,
        public bool $canBill = false,
        public ?GuestOptionData $guest = null,
    ) {}
}

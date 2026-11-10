<?php

namespace App\Actions\DoorLocks;

use App\Enums\PasscodeStatus;
use App\Models\DoorCard;
use App\Models\ReservationUnit;
use App\Models\User;

/**
 * Take every live guest card of a stay off its locks, so a card stops opening the door when staff check the guest out. A card
 * that cannot be reached keeps working until its end time, which the nightly expiry then closes. The kiosk cleans up its own cards.
 */
class RemoveStayCards
{
    public function __construct(private RemoveDoorCard $removeDoorCard) {}

    public function __invoke(ReservationUnit $unit, ?User $actor = null): void
    {
        DoorCard::query()
            ->where('reservation_unit_id', $unit->id)
            ->whereIn('status', [PasscodeStatus::Pending, PasscodeStatus::Active])
            ->with('doorLock.property.doorLockConnection')
            ->get()
            ->each(fn (DoorCard $card) => ($this->removeDoorCard)($card, $actor));
    }
}

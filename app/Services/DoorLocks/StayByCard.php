<?php

namespace App\Services\DoorLocks;

use App\Enums\PasscodeStatus;
use App\Models\DoorCard;
use App\Models\Property;
use App\Models\ReservationUnit;

/**
 * Finds the stay a card was registered for, from the card UID, so check-out by card can work. Only an Active card with a
 * stay counts: a Revoked, Failed or Pending card, or a card with no stay, finds nothing. It reads only.
 */
class StayByCard
{
    public function __construct(private CardNumber $cardNumber) {}

    public function find(Property $property, string $uid): ?ReservationUnit
    {
        $number = $this->cardNumber->fromUid($uid);

        if ($number === null) {
            return null;
        }

        $card = DoorCard::query()
            ->where('property_id', $property->id)
            ->where('card_number', $number)
            ->where('status', PasscodeStatus::Active)
            ->whereNotNull('reservation_unit_id')
            ->latest('id')
            ->first();

        return $card === null ? null : ReservationUnit::query()->where('property_id', $property->id)->find($card->reservation_unit_id);
    }
}

<?php

namespace App\Data\DoorLocks;

use App\Models\DoorCard;
use App\Models\DoorPasscode;

/**
 * Who a PIN or card belongs to, for the Door locks lists: the stay's primary guest, its booking reference and the room of the lock.
 * It reads only what the caller already loaded (`reservationUnit.reservation`, `reservationUnit.guests.guest`, `doorLock.room`), so a
 * list that does not need it costs no extra queries. A manual PIN has no stay, so its guest and reference are null.
 */
final class CredentialStay
{
    /**
     * @return array{guestName: ?string, reference: ?string, room: ?string}
     */
    public static function of(DoorPasscode|DoorCard $credential): array
    {
        $unit = $credential->relationLoaded('reservationUnit') ? $credential->reservationUnit : null;
        $lock = $credential->relationLoaded('doorLock') ? $credential->doorLock : null;

        return [
            // The primary guest, else the person who made the booking (older stays may have no guest marked primary).
            'guestName' => ($unit?->relationLoaded('guests') ? $unit->guests->first()?->guest?->full_name : null)
                ?? ($unit?->relationLoaded('reservation') && $unit->reservation?->relationLoaded('booker') ? $unit->reservation->booker?->full_name : null),
            'reference' => $unit?->relationLoaded('reservation') ? $unit->reservation?->reference : null,
            'room' => $lock?->relationLoaded('room') ? $lock->room?->number : null,
        ];
    }
}

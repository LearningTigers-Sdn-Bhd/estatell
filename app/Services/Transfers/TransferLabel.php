<?php

namespace App\Services\Transfers;

use App\Enums\TransferDirection;
use App\Models\ReservationUnit;
use App\Models\UnitTransfer;

/**
 * A room's road transfer in one line, for the lists the desk reads: "10:30 · Sandakan Airport · AK 6231 · Shared".
 * The place is where the driver collects (In) or leaves (Out) the guest. Null when the room has no transfer that way.
 * See docs/35-transfers/01-land-transfers.md.
 */
class TransferLabel
{
    /**
     * Expects the unit's `transfers.transferRoute` loaded.
     */
    public function forUnit(ReservationUnit $unit, TransferDirection $direction): ?string
    {
        $transfer = $unit->transfers->first(fn (UnitTransfer $candidate): bool => $candidate->direction === $direction);

        if ($transfer === null) {
            return null;
        }

        return implode(' · ', array_filter([
            substr($transfer->pickup_time, 0, 5),
            $transfer->transferRoute->place,
            $transfer->flight_number,
            $transfer->mode->label(),
        ]));
    }
}

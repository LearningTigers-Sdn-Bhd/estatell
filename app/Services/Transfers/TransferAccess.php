<?php

namespace App\Services\Transfers;

use App\Actions\Transfers\UnitTransferResult;
use App\Enums\TransferDirection;
use App\Enums\UnitStatus;
use App\Models\ReservationUnit;

/**
 * Whether a unit's transfer in one direction can still change: In while the unit is Confirmed; Out while it is
 * Confirmed or CheckedIn. The same rule as boat trips.
 */
class TransferAccess
{
    public function refusalFor(ReservationUnit $unit, TransferDirection $direction): ?string
    {
        $open = match ($direction) {
            TransferDirection::In => [UnitStatus::Confirmed],
            TransferDirection::Out => [UnitStatus::Confirmed, UnitStatus::CheckedIn],
        };

        return in_array($unit->status, $open, true) ? null : UnitTransferResult::REFUSAL_UNIT_NOT_OPEN;
    }
}

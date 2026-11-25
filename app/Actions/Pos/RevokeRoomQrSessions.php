<?php

namespace App\Actions\Pos;

use App\Enums\PosQrSessionKind;
use App\Models\PosQrSession;

/**
 * Revoke a unit's room QR tokens when the guest checks out, or the check-in is undone.
 */
class RevokeRoomQrSessions
{
    public function __invoke(int $propertyId, int $reservationUnitId): void
    {
        PosQrSession::query()
            ->where('property_id', $propertyId)
            ->where('kind', PosQrSessionKind::Room->value)
            ->where('reservation_unit_id', $reservationUnitId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}

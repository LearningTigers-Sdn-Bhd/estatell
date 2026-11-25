<?php

namespace App\Actions\Pos;

use App\Enums\PosOrderStatus;
use App\Enums\PosQrSessionKind;
use App\Models\PosOrder;
use App\Models\PosQrSession;

/**
 * Revoke a table's live QR tokens once no order of the table is still open. Called when an order settles. Other open checks
 * on the same table keep the token alive.
 */
class RevokeTableQrSessions
{
    public function __invoke(int $propertyId, int $tableId): void
    {
        $stillOpen = PosOrder::query()
            ->where('property_id', $propertyId)
            ->where('pos_table_id', $tableId)
            ->where('status', PosOrderStatus::Open->value)
            ->exists();

        if ($stillOpen) {
            return;
        }

        PosQrSession::query()
            ->where('property_id', $propertyId)
            ->where('kind', PosQrSessionKind::Table->value)
            ->where('pos_table_id', $tableId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}

<?php

namespace App\Actions\Transfers;

use App\Enums\TransferDirection;
use App\Models\ReservationUnit;
use App\Models\UnitTransfer;
use App\Models\User;
use App\Services\Transfers\TransferAccess;
use Illuminate\Support\Facades\DB;

class ClearUnitTransfer
{
    public function __construct(
        private TransferAccess $transferAccess,
        private SyncTransferCharge $syncTransferCharge,
    ) {}

    /**
     * Remove one unit's transfer in one direction and its unposted charge rows. A posted row stays on the folio, and the
     * result says so. A unit with no transfer in that direction is done with nothing written. Logs
     * `reservation.transfer_cleared`.
     */
    public function __invoke(?User $actor, ReservationUnit $unit, TransferDirection $direction): UnitTransferResult
    {
        try {
            return DB::transaction(function () use ($actor, $unit, $direction): UnitTransferResult {
                $locked = ReservationUnit::query()->with('reservation')->lockForUpdate()->findOrFail($unit->id);
                $refusal = $this->transferAccess->refusalFor($locked, $direction);

                if ($refusal !== null) {
                    return UnitTransferResult::refused($refusal);
                }

                $transfer = UnitTransfer::query()
                    ->where('reservation_unit_id', $locked->id)
                    ->where('direction', $direction->value)
                    ->first();

                if ($transfer === null) {
                    return UnitTransferResult::done();
                }

                $charge = ($this->syncTransferCharge)($actor, $locked, $transfer, null, null);

                if ($charge->isRefused()) {
                    throw new TransferRefused((string) $charge->refusal);
                }

                $transfer->delete();

                activity()
                    ->performedOn($locked->reservation)
                    ->causedBy($actor)
                    ->event('reservation.transfer_cleared')
                    ->withProperties(['property_id' => $locked->property_id, 'reservation_unit_id' => $locked->id, 'direction' => $direction->value])
                    ->log(__(':direction removed.', ['direction' => $direction->label()]));

                return UnitTransferResult::done($charge->postedChargeKept);
            });
        } catch (TransferRefused $refused) {
            return UnitTransferResult::refused($refused->refusal);
        }
    }
}

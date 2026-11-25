<?php

namespace App\Actions\Transfers;

use App\Actions\Reservations\AddUnitAddOn;
use App\Actions\Reservations\RemoveUnitAddOn;
use App\Data\Reservations\NewAddOnData;
use App\Enums\TransferMode;
use App\Models\ReservationUnit;
use App\Models\TransferRoute;
use App\Models\UnitTransfer;
use App\Models\UnitTransferAddOn;
use App\Models\User;
use App\Services\Rates\RatePlanIncludes;

class SyncTransferCharge
{
    public function __construct(
        private AddUnitAddOn $addUnitAddOn,
        private RemoveUnitAddOn $removeUnitAddOn,
        private RatePlanIncludes $ratePlanIncludes,
    ) {}

    /**
     * Replace the charge rows of one transfer. Unposted rows are removed; a posted row is kept, and the result says so.
     * Then the route's shared item (Shared) or private item (Private) is added as an undated add-on and linked to the
     * transfer, unless the route has no item for that type or the unit's rate plan includes it. Included adds nothing.
     * Pass a null route to only remove (Clear). Call it inside the caller's transaction, with the unit row locked.
     * See docs/35-transfers/01-land-transfers.md#charge.
     */
    public function __invoke(?User $actor, ReservationUnit $unit, UnitTransfer $transfer, ?TransferRoute $route, ?TransferMode $mode): UnitTransferResult
    {
        $links = UnitTransferAddOn::query()
            ->where('unit_transfer_id', $transfer->id)
            ->with('unitAddOn')
            ->get();

        $postedChargeKept = $links->contains(fn (UnitTransferAddOn $link): bool => $link->unitAddOn->folio_line_id !== null);

        foreach ($links as $link) {
            if ($link->unitAddOn->folio_line_id !== null) {
                continue;
            }

            if (! ($this->removeUnitAddOn)($actor, $link->unitAddOn)) {
                return UnitTransferResult::refused(UnitTransferResult::REFUSAL_CHARGE_NOT_FOUND);
            }
        }

        $chargeItemId = match ($mode) {
            TransferMode::Shared => $route?->shared_charge_item_id,
            TransferMode::Private => $route?->private_charge_item_id,
            TransferMode::Included, null => null,
        };

        if ($chargeItemId === null || $this->ratePlanIncludes->includesChargeItem($unit->rate_plan_id, $chargeItemId)) {
            return UnitTransferResult::done($postedChargeKept);
        }

        $added = ($this->addUnitAddOn)($actor, $unit, new NewAddOnData(chargeItemId: $chargeItemId));

        if ($added->isRefused()) {
            return UnitTransferResult::refused(UnitTransferResult::REFUSAL_CHARGE_NOT_FOUND);
        }

        foreach ($added->addOns as $addOn) {
            UnitTransferAddOn::query()->create(['unit_transfer_id' => $transfer->id, 'unit_add_on_id' => $addOn->id]);
        }

        return UnitTransferResult::done($postedChargeKept);
    }
}

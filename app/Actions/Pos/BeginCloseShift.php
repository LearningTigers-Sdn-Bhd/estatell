<?php

namespace App\Actions\Pos;

use App\Enums\PosOrderStatus;
use App\Enums\PosShiftStatus;
use App\Models\PosOrder;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\PosShiftCashTotals;
use Illuminate\Support\Facades\DB;

/**
 * Start closing a shift: Open to ClosingControl. From then on the register takes no sales, refunds or cash movements while
 * the cash is counted. Refuses while the register still has open orders (`open_orders_exist`): staff settle, void or move
 * them first. The shift's cash totals are worked out and stored. A refusal writes nothing.
 */
class BeginCloseShift
{
    public function __construct(private PosShiftCashTotals $totals) {}

    public function __invoke(User $actor, Property $property, PosShift $shift): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $shift): PosActionResult {
            $shift = PosShift::query()->where('property_id', $property->id)->whereKey($shift->id)->lockForUpdate()->first();

            if ($shift === null) {
                return PosActionResult::refused('shift_invalid');
            }

            if ($shift->status !== PosShiftStatus::Open) {
                return PosActionResult::refused('shift_not_open');
            }

            if (PosOrder::query()->where('pos_shift_id', $shift->id)->where('status', PosOrderStatus::Open->value)->exists()) {
                return PosActionResult::refused('open_orders_exist');
            }

            $shift->forceFill($this->totals->for($shift) + ['status' => PosShiftStatus::ClosingControl])->save();

            activity()->performedOn($shift)->causedBy($actor)->event('pos.shift_closing_started')
                ->withProperties(['property_id' => $property->id])
                ->log('POS shift closing started');

            return PosActionResult::saved($shift);
        });
    }
}

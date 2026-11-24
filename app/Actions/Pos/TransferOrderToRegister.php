<?php

namespace App\Actions\Pos;

use App\Enums\PosOrderStatus;
use App\Enums\PosShiftStatus;
use App\Models\PosOrder;
use App\Models\PosRegister;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Move an open order to another register of the same outlet that has an Open shift, so a cashier can close their shift
 * with nothing left open (`BeginCloseShift` refuses while orders are open). Both shifts must be Open (`shift_not_open`) and
 * the registers must belong to the same outlet (`register_invalid`). The shifts are locked in id order. A refusal writes nothing.
 */
class TransferOrderToRegister
{
    public function __invoke(User $actor, Property $property, PosOrder $order, PosRegister $target): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $order, $target): PosActionResult {
            $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

            if ($order === null || $order->pos_shift_id === null) {
                return PosActionResult::refused('order_invalid');
            }

            if ($order->status !== PosOrderStatus::Open) {
                return PosActionResult::refused('order_not_open');
            }

            $targetShift = PosShift::query()
                ->where('property_id', $property->id)
                ->where('pos_register_id', $target->id)
                ->where('pos_outlet_id', $order->pos_outlet_id)
                ->where('status', PosShiftStatus::Open->value)
                ->first();

            if ($targetShift === null) {
                return PosActionResult::refused(PosRegister::query()->where('property_id', $property->id)->where('pos_outlet_id', $order->pos_outlet_id)->whereKey($target->id)->exists() ? 'shift_not_open' : 'register_invalid');
            }

            $shifts = PosShift::query()->whereIn('id', [$order->pos_shift_id, $targetShift->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($shifts->get($order->pos_shift_id)?->status !== PosShiftStatus::Open || $shifts->get($targetShift->id)?->status !== PosShiftStatus::Open) {
                return PosActionResult::refused('shift_not_open');
            }

            $from = $order->pos_shift_id;
            $order->forceFill(['pos_shift_id' => $targetShift->id])->save();

            activity()->performedOn($order)->causedBy($actor)->event('pos.order_transferred')
                ->withProperties(['property_id' => $property->id, 'from_pos_shift_id' => $from, 'to_pos_shift_id' => $targetShift->id])
                ->log('POS order moved to another register');

            return PosActionResult::saved($order->refresh());
        });
    }
}

<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Enums\UnitStatus;
use App\Models\PosOrder;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Link or unlink an in-house guest stay (`ReservationUnit`) on an open POS order so cashiers can redeem included
 * meal entitlements or pre-associate a room with the check. Unlinking is refused while any standing line on the order
 * is covered by an included meal entitlement (`meal_lines_require_room`).
 */
class AttachOrderRoom
{
    public function __invoke(User $actor, Property $property, PosOrder $order, ?ReservationUnit $unit): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $order, $unit): PosActionResult {
            $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

            if ($order === null || $order->status !== PosOrderStatus::Open) {
                return PosActionResult::refused('order_not_open');
            }

            if ($unit === null) {
                $hasMealCover = $order->lines()
                    ->where('is_entitlement_covered', true)
                    ->where('kitchen_status', '!=', PosKitchenStatus::Voided->value)
                    ->exists();

                if ($hasMealCover) {
                    return PosActionResult::refused('meal_lines_require_room');
                }
            } else {
                $unit = ReservationUnit::query()->where('property_id', $property->id)->find($unit->id);

                if ($unit === null || $unit->status !== UnitStatus::CheckedIn) {
                    return PosActionResult::refused('unit_not_postable');
                }
            }

            $order->forceFill(['reservation_unit_id' => $unit?->id])->save();

            activity()->performedOn($order)->causedBy($actor)->event('pos.order_room_linked')
                ->withProperties(['property_id' => $property->id, 'reservation_unit_id' => $unit?->id])
                ->log($unit === null ? 'POS order room unlinked' : 'POS order room linked');

            return PosActionResult::saved($order);
        });
    }
}

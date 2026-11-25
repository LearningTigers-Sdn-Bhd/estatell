<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Print the pre-bill. The first print fires every Draft line, so nothing can be deleted quietly after the guest saw the
 * bill, and counts `prebill_print_count` up. From then on a line void, any discount and a change of dining option all need
 * a manager. When the outlet requires it (`require_reprint_pin`), every reprint needs a manager too (`approval_required`;
 * the approver has already been checked for `pos.void`) and is logged as `pos.prebill_reprinted`. An order with no lines
 * has nothing to bill (`order_not_settleable`). The paper itself is the browser's print. A refusal writes nothing.
 * See docs/34-native-pos/03-floors-and-orders.md#anti-fraud-controls.
 */
class PrintPreBill
{
    public function __construct(private FireOrderLines $fireOrderLines) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, ?User $approver = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $order, $approver): PosActionResult {
            $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

            if ($order === null) {
                return PosActionResult::refused('order_invalid');
            }

            if ($order->status !== PosOrderStatus::Open) {
                return PosActionResult::refused('order_not_open');
            }

            if (! $order->lines()->where('kitchen_status', '!=', PosKitchenStatus::Voided->value)->exists()) {
                return PosActionResult::refused('order_not_settleable');
            }

            $reprint = $order->prebill_print_count >= 1;

            if ($reprint && $order->outlet->require_reprint_pin && $approver === null) {
                return PosActionResult::refused('approval_required');
            }

            ($this->fireOrderLines)($actor, $property, $order);

            $order->refresh()->forceFill(['prebill_print_count' => $order->prebill_print_count + 1])->save();

            activity()->performedOn($order)->causedBy($actor)->event($reprint ? 'pos.prebill_reprinted' : 'pos.prebill_printed')
                ->withProperties(['property_id' => $property->id, 'print_count' => $order->prebill_print_count, 'approved_by_user_id' => $approver?->id])
                ->log($reprint ? 'POS pre-bill reprinted' : 'POS pre-bill printed');

            return PosActionResult::saved($order);
        });
    }
}

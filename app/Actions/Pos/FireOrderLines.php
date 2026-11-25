<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Models\PosItem;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOutletItem;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Send Draft lines to the kitchen: Draft to Fired, with the time, and each fired line takes its quantity off the outlet
 * variant's stock countdown and the item's shared count when they are set. Counts may go below zero, the register only
 * warns. A line that is not Draft is skipped, so firing twice changes nothing. Give line ids to fire some, or none to fire
 * every Draft line. `SettlePosOrder` and `PrintPreBill` call it too, so a paid or billed line can no longer be deleted
 * quietly. Returns the order. A refusal writes nothing. See docs/34-native-pos/03-floors-and-orders.md.
 */
class FireOrderLines
{
    /**
     * @param  list<int>|null  $lineIds
     */
    public function __invoke(User $actor, Property $property, PosOrder $order, ?array $lineIds = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $order, $lineIds): PosActionResult {
            $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

            if ($order === null) {
                return PosActionResult::refused('order_invalid');
            }

            if ($order->status !== PosOrderStatus::Open) {
                return PosActionResult::refused('order_not_open');
            }

            $lines = $order->lines()
                ->where('kitchen_status', PosKitchenStatus::Draft->value)
                ->where('line_kind', '!=', PosOrderLineKind::PackagingFee->value)
                ->when($lineIds !== null, fn ($query) => $query->whereIn('id', $lineIds))
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                $line->forceFill(['kitchen_status' => PosKitchenStatus::Fired, 'fired_at' => now()])->save();
                $this->takeStock($order, $line);
            }

            if ($lines->isNotEmpty()) {
                activity()->performedOn($order)->causedBy($actor)->event('pos.lines_fired')
                    ->withProperties(['property_id' => $property->id, 'pos_order_line_ids' => $lines->pluck('id')->all()])
                    ->log('POS lines fired');
            }

            return PosActionResult::saved($order->refresh());
        });
    }

    private function takeStock(PosOrder $order, PosOrderLine $line): void
    {
        if ($line->pos_item_variant_id === null) {
            return;
        }

        $quantity = (int) $line->quantity;

        PosOutletItem::query()
            ->where('pos_outlet_id', $order->pos_outlet_id)
            ->where('pos_item_variant_id', $line->pos_item_variant_id)
            ->whereNotNull('stock_countdown')
            ->decrement('stock_countdown', $quantity);

        PosItem::query()
            ->whereKey($line->variant->pos_item_id)
            ->whereNotNull('shared_stock_count')
            ->decrement('shared_stock_count', $quantity);
    }
}

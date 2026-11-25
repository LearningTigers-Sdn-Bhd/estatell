<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Models\PosItem;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOutletItem;
use App\Models\PosVoidReason;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Void a line of an open order and price the order again. The line stays on the order as Voided, with who voided it, who
 * approved it, the reason and the time. A line that was fired, or any line once a pre-bill was printed, needs a manager
 * (`approval_required`; the approver has already been checked for `pos.void`). The
 * reason is mandatory and the property's (`void_reason_invalid`). With `$returnStock` (or the reason's default) a fired
 * line puts its quantity back on the stock counts it took them from. The packaging fee line is the system's and cannot be
 * voided (`line_locked`). A refusal writes nothing. See docs/34-native-pos/03-floors-and-orders.md#anti-fraud-controls.
 */
class VoidOrderLine
{
    public function __construct(private RecalculatePosOrder $recalculate) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, PosOrderLine $line, PosVoidReason $reason, ?string $reasonText = null, ?bool $returnStock = null, ?User $approver = null): PosActionResult
    {
        try {
            return DB::transaction(function () use ($actor, $property, $order, $line, $reason, $reasonText, $returnStock, $approver): PosActionResult {
                $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();
                $line = $order === null ? null : PosOrderLine::query()->where('pos_order_id', $order->id)->lockForUpdate()->find($line->id);

                if ($order === null || $line === null) {
                    return PosActionResult::refused('order_invalid');
                }

                if ($order->status !== PosOrderStatus::Open) {
                    return PosActionResult::refused('order_not_open');
                }

                if ($line->kitchen_status === PosKitchenStatus::Voided || $line->line_kind === PosOrderLineKind::PackagingFee) {
                    return PosActionResult::refused('line_locked');
                }

                $reason = PosVoidReason::query()->where('property_id', $property->id)->where('active', true)->find($reason->id);

                if ($reason === null) {
                    return PosActionResult::refused('void_reason_invalid');
                }

                $fired = $line->kitchen_status->isLocked();

                if (($fired || $order->prebill_print_count >= 1) && $approver === null) {
                    return PosActionResult::refused('approval_required');
                }

                $return = $returnStock ?? $reason->default_return_stock;

                $line->forceFill([
                    'kitchen_status' => PosKitchenStatus::Voided,
                    'void_reason_id' => $reason->id,
                    'void_reason_text' => $reasonText === null || trim($reasonText) === '' ? null : trim($reasonText),
                    'voided_by_user_id' => $actor->id,
                    'void_approved_by_user_id' => $approver?->id,
                    'return_stock_on_void' => $fired && $return,
                ])->save();

                if ($fired && $return) {
                    $this->returnStock($order, $line);
                }

                $result = ($this->recalculate)($order);

                if ($result->isRefused()) {
                    throw new PosRefusedException((string) $result->refusal);
                }

                activity()->performedOn($order)->causedBy($actor)->event('pos.line_voided')
                    ->withProperties(['property_id' => $property->id, 'pos_order_line_id' => $line->id, 'item_name' => $line->item_name, 'quantity' => $line->quantity, 'reason' => $reason->label, 'approved_by_user_id' => $approver?->id, 'return_stock' => $fired && $return])
                    ->log('POS line voided');

                return PosActionResult::saved($line->refresh());
            });
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }

    private function returnStock(PosOrder $order, PosOrderLine $line): void
    {
        if ($line->pos_item_variant_id === null) {
            return;
        }

        $quantity = (int) $line->quantity;

        PosOutletItem::query()->where('pos_outlet_id', $order->pos_outlet_id)->where('pos_item_variant_id', $line->pos_item_variant_id)->whereNotNull('stock_countdown')->increment('stock_countdown', $quantity);
        PosItem::query()->whereKey($line->variant->pos_item_id)->whereNotNull('shared_stock_count')->increment('shared_stock_count', $quantity);
    }
}

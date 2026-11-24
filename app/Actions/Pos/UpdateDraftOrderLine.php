<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Change the quantity or note of a Draft line, and price the order again. Only a Draft line can change (`line_locked`):
 * once it is fired or a pre-bill printed, it is locked and a void needs a manager. After a pre-bill the quantity can only
 * go up (`prebill_locked`). A line covered by an included meal stays at 1, one portion per line (`quantity_invalid`).
 * A refusal writes nothing.
 */
class UpdateDraftOrderLine
{
    public function __construct(private RecalculatePosOrder $recalculate) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, PosOrderLine $line, ?int $quantity, ?string $note = null): PosActionResult
    {
        try {
            return DB::transaction(function () use ($actor, $property, $order, $line, $quantity, $note): PosActionResult {
                $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();
                $line = $order === null ? null : PosOrderLine::query()->where('pos_order_id', $order->id)->find($line->id);

                if ($order === null || $line === null) {
                    return PosActionResult::refused('order_invalid');
                }

                if ($order->status !== PosOrderStatus::Open) {
                    return PosActionResult::refused('order_not_open');
                }

                if ($line->kitchen_status !== PosKitchenStatus::Draft) {
                    return PosActionResult::refused('line_locked');
                }

                if ($quantity !== null) {
                    if ($quantity < 1 || $quantity > 999 || ($line->is_entitlement_covered && $quantity !== 1)) {
                        return PosActionResult::refused('quantity_invalid');
                    }

                    if ($order->prebill_print_count >= 1 && $quantity < (int) $line->quantity) {
                        return PosActionResult::refused('prebill_locked');
                    }

                    $line->quantity = $quantity.'.000';
                }

                if ($note !== null) {
                    $line->note = trim($note) === '' ? null : trim($note);
                }

                $line->save();
                $result = ($this->recalculate)($order);

                if ($result->isRefused()) {
                    throw new PosRefusedException((string) $result->refusal);
                }

                activity()->performedOn($order)->causedBy($actor)->event('pos.line_updated')
                    ->withProperties(['property_id' => $property->id, 'pos_order_line_id' => $line->id, 'quantity' => $quantity])
                    ->log('POS line updated');

                return PosActionResult::saved($line->refresh());
            });
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }
}

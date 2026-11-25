<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOrderLineModifier;
use App\Models\PosOrderLineTax;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Delete a Draft line that was never sent to the kitchen, and price the order again. Allowed only before the line is fired
 * and before any pre-bill is printed (`line_locked`, `prebill_locked`); after that a manager voids it (`VoidOrderLine`).
 * The draft is the only POS row that is deleted, because nothing was sold. A refusal writes nothing.
 */
class RemoveDraftLine
{
    public function __construct(private RecalculatePosOrder $recalculate, private BumpCfdVersion $bumpCfdVersion) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, PosOrderLine $line): PosActionResult
    {
        try {
            $registerId = null;
            $saved = DB::transaction(function () use ($actor, $property, $order, $line, &$registerId): PosActionResult {
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

                if ($order->prebill_print_count >= 1) {
                    return PosActionResult::refused('prebill_locked');
                }

                if ($line->parent_combo_line_id !== null) {
                    return PosActionResult::refused('combo_part_locked');
                }

                foreach ([...PosOrderLine::query()->where('parent_combo_line_id', $line->id)->get()->all(), $line] as $row) {
                    PosOrderLineTax::query()->where('pos_order_line_id', $row->id)->delete();
                    PosOrderLineModifier::query()->where('pos_order_line_id', $row->id)->delete();
                    $row->delete();
                }

                $result = ($this->recalculate)($order);

                if ($result->isRefused()) {
                    throw new PosRefusedException((string) $result->refusal);
                }

                activity()->performedOn($order)->causedBy($actor)->event('pos.line_removed')
                    ->withProperties(['property_id' => $property->id, 'item_name' => $line->item_name])
                    ->log('POS draft line removed');

                $registerId = $order->shift?->pos_register_id;

                return PosActionResult::saved($order->refresh());
            });

            if (! $saved->isRefused() && $registerId !== null) {
                ($this->bumpCfdVersion)($registerId);
            }

            return $saved;
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }
}

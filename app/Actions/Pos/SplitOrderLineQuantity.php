<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Break one line into two on the same check: `3x Teh Tarik` split by 1 gives `2x` and `1x`, so the 1x can then move or be
 * paid apart. The new line is a copy with the same modifiers, note, course, seat and kitchen status, fired time included, so the
 * kitchen is not sent a second docket. The amount must be a whole number below the line's quantity (`split_quantity_invalid`),
 * and a combo, packaging, voided, discounted or meal-entitlement line cannot be split (`line_not_splittable`): a fixed discount
 * would be given twice, so remove it first. The check is priced again.
 * A refusal writes nothing.
 */
class SplitOrderLineQuantity
{
    public function __construct(private RecalculatePosOrder $recalculate) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, PosOrderLine $line, int $splitOff): PosActionResult
    {
        try {
            return DB::transaction(function () use ($actor, $property, $order, $line, $splitOff): PosActionResult {
                $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

                if ($order === null) {
                    return PosActionResult::refused('order_invalid');
                }

                if ($order->status !== PosOrderStatus::Open) {
                    return PosActionResult::refused('order_not_open');
                }

                $line = PosOrderLine::query()->where('pos_order_id', $order->id)->whereKey($line->id)->lockForUpdate()->first();

                if ($line === null) {
                    return PosActionResult::refused('line_invalid');
                }

                $isCombo = $line->parent_combo_line_id !== null || PosOrderLine::query()->where('parent_combo_line_id', $line->id)->exists();

                if ($isCombo || $line->line_kind !== PosOrderLineKind::Item || $line->kitchen_status === PosKitchenStatus::Voided || $line->is_entitlement_covered || $line->meal_redemption_id !== null || $line->discount_type !== null) {
                    return PosActionResult::refused('line_not_splittable');
                }

                $quantity = BigDecimal::of($line->quantity);

                if ($splitOff < 1 || ! $quantity->isGreaterThan($splitOff) || ! $quantity->isEqualTo($quantity->toScale(0))) {
                    return PosActionResult::refused('split_quantity_invalid');
                }

                $copy = $line->replicate(['quantity', 'line_gross', 'discount_amount', 'net_amount', 'service_charge_amount', 'tax_amount', 'line_total']);
                $copy->quantity = (string) $splitOff;
                $copy->save();

                foreach ($line->modifiers as $modifier) {
                    $modifier->replicate()->forceFill(['pos_order_line_id' => $copy->id])->save();
                }

                $line->forceFill(['quantity' => (string) $quantity->minus($splitOff)->toScale(3)])->save();

                $result = ($this->recalculate)($order);

                if ($result->isRefused()) {
                    throw new PosRefusedException((string) $result->refusal);
                }

                activity()->performedOn($order)->causedBy($actor)->event('pos.line_quantity_split')
                    ->withProperties(['property_id' => $property->id, 'pos_order_line_id' => $line->id, 'new_pos_order_line_id' => $copy->id, 'quantity' => $splitOff])
                    ->log('POS line quantity split');

                return PosActionResult::saved($copy->refresh());
            });
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }
}

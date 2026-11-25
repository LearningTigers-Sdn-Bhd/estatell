<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Move chosen lines from one open order to another open order of the same outlet, on the same table or another, and price
 * both again. A combo moves whole: its components follow the parent, and a component alone is refused (`line_not_movable`),
 * as is a voided, packaging or meal-entitlement line. A check with a bill discount cannot give or take lines
 * (`order_has_discounts`), because the discount would be spread over lines the approval never covered; remove it first. The target takes the higher pre-bill count of the two, so a line can
 * never escape the pre-bill lock by moving to a fresh check. Both orders are locked in id order. A refusal writes nothing.
 * See docs/34-native-pos/03-floors-and-orders.md.
 */
class MovePosOrderItems
{
    public function __construct(private RecalculatePosOrder $recalculate) {}

    /**
     * @param  list<int>  $lineIds
     */
    public function __invoke(User $actor, Property $property, PosOrder $source, PosOrder $target, array $lineIds): PosActionResult
    {
        try {
            return DB::transaction(fn (): PosActionResult => $this->move($actor, $property, $source, $target, $lineIds));
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }

    /**
     * @param  list<int>  $lineIds
     */
    private function move(User $actor, Property $property, PosOrder $source, PosOrder $target, array $lineIds): PosActionResult
    {
        if ($source->id === $target->id) {
            return PosActionResult::refused('same_order');
        }

        $orders = PosOrder::query()->where('property_id', $property->id)->whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $source = $orders->get($source->id);
        $target = $orders->get($target->id);

        if ($source === null || $target === null) {
            return PosActionResult::refused('order_invalid');
        }

        if ($source->status !== PosOrderStatus::Open || $target->status !== PosOrderStatus::Open) {
            return PosActionResult::refused('order_not_open');
        }

        if ($source->pos_outlet_id !== $target->pos_outlet_id) {
            return PosActionResult::refused('outlet_mismatch');
        }

        if ($orders->contains(fn (PosOrder $order): bool => $order->discounts()->exists())) {
            return PosActionResult::refused('order_has_discounts');
        }

        $lineIds = array_values(array_unique($lineIds));

        if ($lineIds === []) {
            return PosActionResult::refused('no_lines');
        }

        $lines = PosOrderLine::query()->where('pos_order_id', $source->id)
            ->where(fn ($query) => $query->whereIn('id', $lineIds)->orWhereIn('parent_combo_line_id', $lineIds))
            ->lockForUpdate()->get();

        $chosen = $lines->whereIn('id', $lineIds);

        if ($chosen->count() !== count($lineIds)) {
            return PosActionResult::refused('line_not_movable');
        }

        foreach ($lines as $line) {
            $orphan = $line->parent_combo_line_id !== null && ! in_array($line->parent_combo_line_id, $lineIds, true) && ! $lines->contains('id', $line->parent_combo_line_id);

            if ($line->kitchen_status === PosKitchenStatus::Voided || $line->line_kind === PosOrderLineKind::PackagingFee || $line->is_entitlement_covered || $line->meal_redemption_id !== null || $orphan) {
                return PosActionResult::refused('line_not_movable');
            }
        }

        foreach ($lines as $line) {
            $line->forceFill(['pos_order_id' => $target->id])->save();
        }

        $target->forceFill(['prebill_print_count' => max($source->prebill_print_count, $target->prebill_print_count)])->save();

        foreach ([$source, $target] as $order) {
            $result = ($this->recalculate)($order);

            if ($result->isRefused()) {
                throw new PosRefusedException((string) $result->refusal);
            }
        }

        activity()->performedOn($target)->causedBy($actor)->event('pos.order_items_moved')
            ->withProperties(['property_id' => $property->id, 'from_pos_order_id' => $source->id, 'pos_order_line_ids' => $lines->pluck('id')->all()])
            ->log('POS items moved to another check');

        return PosActionResult::saved($target->refresh());
    }
}

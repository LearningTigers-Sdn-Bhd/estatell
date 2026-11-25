<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosOrderLineInputData;
use App\Enums\PosAvailability;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Enums\PosPricingType;
use App\Enums\PosShiftStatus;
use App\Models\PosItem;
use App\Models\PosItemVariant;
use App\Models\PosModifierGroup;
use App\Models\PosModifierOption;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOrderLineModifier;
use App\Models\PosOutletItem;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Add an item to an open order as a Draft line, with its chosen modifiers, and price the order again. The price is the
 * outlet's override or the variant's (an item priced at the register needs `unitPrice`: `price_required`). Names, price and
 * cost are copied onto the line. The first `free_included_count` choices of a group, cheapest first, cost nothing. Choices
 * must fit each group (`modifier_invalid`, `modifier_selection_invalid`). An item the outlet switched off is refused
 * (`item_unavailable`); a stock count that ran out only warns on the register. An item covered by the guest's included
 * meal (`coverWithEntitlement`) becomes one line for each portion, so each can be given back on its own. If the order
 * cannot be priced the line is not added (`sst_registration_missing` and the other calculator refusals). A shift that is
 * not Open takes no new lines. A refusal writes nothing. See docs/34-native-pos/03-floors-and-orders.md.
 */
class AddOrderLine
{
    public function __construct(private RecalculatePosOrder $recalculate) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, PosOrderLineInputData $data): PosActionResult
    {
        try {
            return DB::transaction(function () use ($actor, $property, $order, $data): PosActionResult {
                $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

                if ($order === null) {
                    return PosActionResult::refused('order_invalid');
                }

                if ($order->status !== PosOrderStatus::Open) {
                    return PosActionResult::refused('order_not_open');
                }

                if ($order->shift?->status !== PosShiftStatus::Open) {
                    return PosActionResult::refused('shift_not_open');
                }

                if ($data->quantity < 1 || $data->quantity > 999) {
                    return PosActionResult::refused('quantity_invalid');
                }

                $variant = PosItemVariant::query()->where('property_id', $property->id)->where('active', true)->with(['item.category'])->find($data->variantId);
                $item = $variant?->item;

                if ($variant === null || ! $item->active || ! $item->category->active) {
                    return PosActionResult::refused('item_unavailable');
                }

                $outletItem = PosOutletItem::query()->where('pos_outlet_id', $order->pos_outlet_id)->where('pos_item_variant_id', $variant->id)->first();

                if ($outletItem?->availability === PosAvailability::None) {
                    return PosActionResult::refused('item_unavailable');
                }

                $unitPrice = $this->unitPrice($variant, $outletItem, $data);

                if ($unitPrice === null) {
                    return PosActionResult::refused('price_required');
                }

                if ($data->coverWithEntitlement && ($order->reservation_unit_id === null || $item->meal_period_id === null)) {
                    return PosActionResult::refused('not_entitled');
                }

                $modifiers = $this->modifiers($item->modifierGroups()->where('pos_modifier_groups.active', true)->with('options')->get(), $data->modifierOptionIds);

                if (is_string($modifiers)) {
                    return PosActionResult::refused($modifiers);
                }

                $portions = $data->coverWithEntitlement ? $data->quantity : 1;
                $lines = [];

                for ($i = 0; $i < $portions; $i++) {
                    $lines[] = $this->createLine($order, $item, $variant, $unitPrice, $data, $modifiers, $data->coverWithEntitlement ? 1 : $data->quantity);
                }

                $result = ($this->recalculate)($order);

                if ($result->isRefused()) {
                    throw new PosRefusedException((string) $result->refusal);
                }

                activity()->performedOn($order)->causedBy($actor)->event('pos.line_added')
                    ->withProperties(['property_id' => $property->id, 'pos_order_line_id' => $lines[0]->id, 'pos_item_variant_id' => $variant->id, 'quantity' => $data->quantity])
                    ->log('POS line added');

                return PosActionResult::saved($lines[0]->refresh());
            });
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }

    private function unitPrice(PosItemVariant $variant, ?PosOutletItem $outletItem, PosOrderLineInputData $data): ?string
    {
        if ($variant->pricing_type === PosPricingType::Variable) {
            return $data->unitPrice !== null && preg_match('/^\d{1,10}(\.\d{1,2})?$/', $data->unitPrice) === 1
                ? BigDecimal::of($data->unitPrice)->toScale(2)->__toString()
                : null;
        }

        return $outletItem->price_override ?? $variant->price;
    }

    /**
     * Check the chosen options against the item's groups and price each portion of them.
     *
     * @param  Collection<int, PosModifierGroup>  $groups
     * @param  list<int>  $optionIds
     * @return list<array{option: PosModifierOption, charged: string}>|string A refusal code when the choice is not allowed.
     */
    private function modifiers($groups, array $optionIds): array|string
    {
        $counts = array_count_values($optionIds);
        $allowed = $groups->flatMap(fn (PosModifierGroup $group) => $group->options->filter(fn (PosModifierOption $option): bool => $option->active && $option->availability !== PosAvailability::None))->keyBy('id');

        if (array_diff(array_keys($counts), $allowed->keys()->all()) !== []) {
            return 'modifier_invalid';
        }

        $rows = [];

        foreach ($groups as $group) {
            $groupOptionIds = $group->options->pluck('id')->all();
            $chosen = array_filter($counts, fn (int $count, int $id): bool => in_array($id, $groupOptionIds, true), ARRAY_FILTER_USE_BOTH);
            $total = array_sum($chosen);

            if ($total < $group->min_select || ($group->max_select !== null && $total > $group->max_select) || array_any($chosen, fn (int $count): bool => $count > $group->max_per_option)) {
                return 'modifier_selection_invalid';
            }

            $units = [];

            foreach ($chosen as $id => $count) {
                $option = $allowed->get($id);

                for ($i = 0; $option !== null && $i < $count; $i++) {
                    $units[] = $option;
                }
            }

            usort($units, fn (PosModifierOption $a, PosModifierOption $b): int => BigDecimal::of($a->price_delta)->compareTo($b->price_delta) ?: $a->id <=> $b->id);

            foreach ($units as $position => $option) {
                $charged = $position < $group->free_included_count || BigDecimal::of($option->price_delta)->isNegative() ? '0.00' : $option->price_delta;
                $rows[] = ['option' => $option, 'charged' => $charged];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{option: PosModifierOption, charged: string}>  $modifiers
     */
    private function createLine(PosOrder $order, PosItem $item, PosItemVariant $variant, string $unitPrice, PosOrderLineInputData $data, array $modifiers, int $quantity): PosOrderLine
    {
        $line = new PosOrderLine(['note' => $data->note === null || trim($data->note) === '' ? null : trim($data->note)]);
        $line->property_id = $order->property_id;
        $line->pos_order_id = $order->id;
        $line->pos_item_variant_id = $variant->id;
        $line->line_kind = PosOrderLineKind::Item;
        $line->price_includes_tax = $item->price_includes_tax;
        $line->service_charge_exempt = $item->service_charge_exempt;
        $line->transaction_code_id = $item->transaction_code_id ?? $item->category->transaction_code_id;
        $line->is_entitlement_covered = $data->coverWithEntitlement;
        $line->item_name = $item->name;
        $line->variant_name = $variant->name;
        $line->internal_name = $item->internal_name;
        $line->quantity = (string) $quantity.'.000';
        $line->unit_price = $unitPrice;
        $line->unit_cost = $variant->cost;
        $line->kitchen_status = PosKitchenStatus::Draft;
        $line->course_number = min(9, max(1, $data->courseNumber));
        $line->seat_number = $data->seatNumber !== null && $data->seatNumber >= 1 ? min(99, $data->seatNumber) : null;
        $line->save();

        foreach ($modifiers as $row) {
            $modifier = new PosOrderLineModifier;
            $modifier->pos_order_line_id = $line->id;
            $modifier->pos_modifier_option_id = $row['option']->id;
            $modifier->option_name = $row['option']->name;
            $modifier->internal_name = $row['option']->internal_name;
            $modifier->price_delta = $row['option']->price_delta;
            $modifier->charged_price = $row['charged'];
            $modifier->quantity = 1;
            $modifier->save();
        }

        return $line;
    }
}

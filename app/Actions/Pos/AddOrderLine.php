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
use App\Services\Pos\PosMenuAvailability;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Add an item to an open order as a Draft line, with its chosen modifiers, and price the order again. The price is the
 * outlet's override or the variant's (an item priced at the register needs `unitPrice`: `price_required`). Names, price and
 * cost are copied onto the line. The first `free_included_count` choices of a group, cheapest first, cost nothing. Choices
 * must fit each group (`modifier_invalid`, `modifier_selection_invalid`). An item the outlet switched off is refused
 * (`item_unavailable`), as is one outside its menu schedule (`item_not_scheduled`); a stock count that ran out only warns on the register. An item covered by the guest's included
 * meal (`coverWithEntitlement`) becomes one line for each portion, so each can be given back on its own. If the order
 * cannot be priced the line is not added (`sst_registration_missing` and the other calculator refusals). A shift that is
 * not Open takes no new lines. An item sold by weight takes `weight` instead of a count and prices it per unit of weight
 * (`weight_invalid`); it is never covered by an included meal. A refusal writes nothing. See docs/34-native-pos/03-floors-and-orders.md.
 */
class AddOrderLine
{
    public function __construct(private RecalculatePosOrder $recalculate, private PosMenuAvailability $availability, private BumpCfdVersion $bumpCfdVersion) {}

    /**
     * A guest adding a line by QR has no staff actor, so `$actor` may be null and the log names no one.
     */
    public function __invoke(?User $actor, Property $property, PosOrder $order, PosOrderLineInputData $data): PosActionResult
    {
        try {
            $registerId = null;
            $saved = DB::transaction(function () use ($actor, $property, $order, $data, &$registerId): PosActionResult {
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

                if (! $this->availability->sellable($order->outlet, $item)) {
                    return PosActionResult::refused('item_not_scheduled');
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

                $parts = [];

                if ($item->is_combo) {
                    $parts = $data->coverWithEntitlement ? 'not_entitled' : $this->comboParts($order, $item, $data->comboSelections);

                    if (is_string($parts)) {
                        return PosActionResult::refused($parts);
                    }
                }

                $portions = $data->coverWithEntitlement ? $data->quantity : 1;
                $lines = [];
                $quantity = $data->quantity.'.000';

                if ($item->sold_by_weight) {
                    $weighed = $this->weighedQuantity($item, $data);

                    if ($weighed === null || $data->coverWithEntitlement) {
                        return PosActionResult::refused('weight_invalid');
                    }

                    $quantity = $weighed;
                }

                for ($i = 0; $i < $portions; $i++) {
                    $lines[] = $this->createLine($order, $item, $variant, $unitPrice, $data, $modifiers, $data->coverWithEntitlement ? '1.000' : $quantity);
                }

                foreach ($parts as $part) {
                    $this->createComponent($order, $lines[0], $part['variant'], $part['upcharge'], $quantity, $data);
                }

                $result = ($this->recalculate)($order);

                if ($result->isRefused()) {
                    throw new PosRefusedException((string) $result->refusal);
                }

                activity()->performedOn($order)->causedBy($actor)->event('pos.line_added')
                    ->withProperties(['property_id' => $property->id, 'pos_order_line_id' => $lines[0]->id, 'pos_item_variant_id' => $variant->id, 'quantity' => $data->quantity])
                    ->log('POS line added');

                $registerId = $order->shift?->pos_register_id;

                return PosActionResult::saved($lines[0]->refresh());
            });

            if (! $saved->isRefused() && $registerId !== null) {
                ($this->bumpCfdVersion)($registerId);
            }

            return $saved;
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }

    /**
     * Check a guest's picks against the combo's slots, one entry for each picked variant with its upcharge.
     *
     * @param  array<int, list<int>>  $selections
     * @return list<array{variant: PosItemVariant, upcharge: string}>|string A refusal code when the picks do not fit.
     */
    private function comboParts(PosOrder $order, PosItem $item, array $selections): array|string
    {
        $slots = $item->comboSlots()->with(['options.variant.item.category'])->get();

        if ($slots->isEmpty() || array_diff(array_keys($selections), $slots->pluck('id')->all()) !== []) {
            return 'combo_selection_invalid';
        }

        $parts = [];

        foreach ($slots as $slot) {
            $picked = $selections[$slot->id] ?? [];

            if (count($picked) < $slot->min_select || count($picked) > $slot->max_select || count(array_unique($picked)) !== count($picked)) {
                return 'combo_selection_invalid';
            }

            foreach ($picked as $variantId) {
                $option = $slot->options->firstWhere('pos_item_variant_id', $variantId);
                $variant = $option?->variant;

                if ($option === null || ! $variant->active || ! $variant->item->active || ! $variant->item->category->active) {
                    return 'combo_selection_invalid';
                }

                if (PosOutletItem::query()->where('pos_outlet_id', $order->pos_outlet_id)->where('pos_item_variant_id', $variant->id)->first()?->availability === PosAvailability::None) {
                    return 'item_unavailable';
                }

                $parts[] = ['variant' => $variant, 'upcharge' => $option->upcharge];
            }
        }

        return $parts;
    }

    /**
     * A part of a combo as its own line under the combo line, priced at its upcharge, so each part books to its own account
     * and tax and reaches the kitchen on its own.
     */
    private function createComponent(PosOrder $order, PosOrderLine $parent, PosItemVariant $variant, string $upcharge, string $quantity, PosOrderLineInputData $data): void
    {
        $item = $variant->item;
        $line = new PosOrderLine;
        $line->property_id = $order->property_id;
        $line->pos_order_id = $order->id;
        $line->pos_item_variant_id = $variant->id;
        $line->line_kind = PosOrderLineKind::ComboComponent;
        $line->parent_combo_line_id = $parent->id;
        $line->price_includes_tax = $item->price_includes_tax;
        $line->service_charge_exempt = $item->service_charge_exempt;
        $line->transaction_code_id = $item->transaction_code_id ?? $item->category->transaction_code_id;
        $line->is_entitlement_covered = false;
        $line->item_name = $item->name;
        $line->variant_name = $variant->name;
        $line->internal_name = $item->internal_name;
        $line->quantity = $quantity;
        $line->unit_price = $upcharge;
        $line->unit_cost = $variant->cost;
        $line->kitchen_status = PosKitchenStatus::Draft;
        $line->course_number = $parent->course_number;
        $line->seat_number = $parent->seat_number;
        $line->save();
    }

    /**
     * What an item sold by weight comes to in price units: the weighed amount over the unit the price is quoted for, so
     * 260 g at RM 10.90 per 50 g is 5.200 units. Null when no usable weight was given.
     */
    private function weighedQuantity(PosItem $item, PosOrderLineInputData $data): ?string
    {
        if ($data->weight === null || preg_match('/^\d{1,7}(\.\d{1,3})?$/', $data->weight) !== 1 || $item->weight_unit_divisor === null) {
            return null;
        }

        $quantity = BigDecimal::of($data->weight)->dividedBy($item->weight_unit_divisor, 3, RoundingMode::HalfUp);

        return $quantity->isPositive() && $quantity->isLessThanOrEqualTo('99999') ? (string) $quantity : null;
    }

    private function unitPrice(PosItemVariant $variant, ?PosOutletItem $outletItem, PosOrderLineInputData $data): ?string
    {
        // Only an offline sync sets this, to honour the price its snapshot showed. The register never sends it.
        if ($data->snapshotPrice !== null) {
            return BigDecimal::of($data->snapshotPrice)->toScale(2)->__toString();
        }

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
    private function createLine(PosOrder $order, PosItem $item, PosItemVariant $variant, string $unitPrice, PosOrderLineInputData $data, array $modifiers, string $quantity): PosOrderLine
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
        $line->quantity = $quantity;
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

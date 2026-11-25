<?php

namespace App\Actions\Pos;

use App\Models\PosComboSlot;
use App\Models\PosComboSlotOption;
use App\Models\PosItem;
use App\Models\PosItemVariant;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Set the slots of a combo item, replacing its old ones. Each slot has a name, a pick range (`min` to `max`, at least one
 * option, `combo_invalid` when the numbers do not fit), and options that name a variant of the property and an upcharge. A
 * variant of another combo cannot be an option, so combos never nest. An item with no slots stops being a combo. An item
 * sold by weight cannot be a combo. Slots already used on an order line are copied onto the line, so changing them never
 * rewrites history. A refusal writes nothing.
 *
 * @phpstan-type OptionInput array{variantId: int, upcharge: string}
 * @phpstan-type SlotInput array{name: string, min: int, max: int, options: list<OptionInput>}
 */
class SavePosComboSlots
{
    /**
     * @param  list<SlotInput>  $slots
     */
    public function __invoke(User $actor, Property $property, PosItem $item, array $slots): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $item, $slots): PosActionResult {
            $item = PosItem::query()->where('property_id', $property->id)->whereKey($item->id)->lockForUpdate()->first();

            if ($item === null) {
                return PosActionResult::refused('item_invalid');
            }

            if ($slots !== [] && $item->sold_by_weight) {
                return PosActionResult::refused('combo_invalid');
            }

            foreach ($slots as $slot) {
                $name = trim($slot['name']);

                if ($name === '' || mb_strlen($name) > 80 || $slot['min'] < 0 || $slot['max'] < max(1, $slot['min']) || $slot['max'] > 20 || $slot['options'] === [] || count($slot['options']) < $slot['min']) {
                    return PosActionResult::refused('combo_invalid');
                }

                $variantIds = array_map(fn (array $option): int => $option['variantId'], $slot['options']);

                if (count(array_unique($variantIds)) !== count($variantIds)) {
                    return PosActionResult::refused('combo_invalid');
                }

                $variants = PosItemVariant::query()->where('property_id', $property->id)->where('active', true)->whereIn('id', $variantIds)->with('item')->get();

                if ($variants->count() !== count($variantIds) || $variants->contains(fn (PosItemVariant $variant): bool => $variant->item->is_combo || $variant->pos_item_id === $item->id)) {
                    return PosActionResult::refused('combo_invalid');
                }

                foreach ($slot['options'] as $option) {
                    if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $option['upcharge']) !== 1 || BigDecimal::of($option['upcharge'])->isNegative()) {
                        return PosActionResult::refused('price_invalid');
                    }
                }
            }

            PosComboSlot::query()->where('pos_item_id', $item->id)->each(function (PosComboSlot $slot): void {
                $slot->options()->delete();
                $slot->delete();
            });

            foreach ($slots as $position => $slot) {
                $row = new PosComboSlot(['name' => trim($slot['name']), 'min_select' => $slot['min'], 'max_select' => $slot['max'], 'sort_order' => $position]);
                $row->property_id = $property->id;
                $row->pos_item_id = $item->id;
                $row->save();

                foreach ($slot['options'] as $index => $option) {
                    $optionRow = new PosComboSlotOption(['upcharge' => BigDecimal::of($option['upcharge'])->toScale(2)->__toString(), 'sort_order' => $index]);
                    $optionRow->property_id = $property->id;
                    $optionRow->pos_combo_slot_id = $row->id;
                    $optionRow->pos_item_variant_id = $option['variantId'];
                    $optionRow->save();
                }
            }

            $item->forceFill(['is_combo' => $slots !== []])->save();

            activity()->performedOn($item)->causedBy($actor)->event('pos.combo_saved')
                ->withProperties(['property_id' => $property->id, 'slots' => count($slots)])
                ->log('POS combo saved');

            return PosActionResult::saved($item);
        });
    }
}

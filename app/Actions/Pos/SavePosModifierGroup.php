<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosModifierGroupInputData;
use App\Models\PosItem;
use App\Models\PosModifierGroup;
use App\Models\PosModifierOption;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a modifier group with its choices and the items that offer it. `max_select` may not be below
 * `min_select` (`select_range_invalid`), a price change may be negative, and every item must be the property's
 * (`item_invalid`). Choices sent with an `id` are updated, new ones created, and the rest deactivated, never deleted.
 * A refusal writes nothing. See docs/34-native-pos/02-menu-catalog.md#4-modifier-groups--free-included-count.
 */
class SavePosModifierGroup
{
    public function __invoke(User $actor, Property $property, PosModifierGroupInputData $data, ?PosModifierGroup $group = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $data, $group): PosActionResult {
            if ($group !== null && $group->property_id !== $property->id) {
                return PosActionResult::refused('group_invalid');
            }

            if ($data->maxSelect !== null && $data->maxSelect < $data->minSelect) {
                return PosActionResult::refused('select_range_invalid');
            }

            $itemIds = array_values(array_unique($data->itemIds));

            if ($itemIds !== [] && PosItem::query()->where('property_id', $property->id)->whereIn('id', $itemIds)->count() !== count($itemIds)) {
                return PosActionResult::refused('item_invalid');
            }

            $ownOptionIds = $group === null ? [] : $group->options()->pluck('id')->all();

            foreach ($data->options as $option) {
                if ($option->id !== null && ! in_array($option->id, $ownOptionIds, true)) {
                    return PosActionResult::refused('option_invalid');
                }
            }

            $created = $group === null;
            $group ??= new PosModifierGroup;
            $group->fill([
                'name' => trim($data->name),
                'min_select' => $data->minSelect,
                'max_select' => $data->maxSelect,
                'max_per_option' => $data->maxPerOption,
                'free_included_count' => $data->freeIncludedCount,
                'sort_order' => $data->sortOrder,
                'active' => $data->active,
            ]);
            $group->property_id = $property->id;
            $group->save();

            $keptIds = [];

            foreach ($data->options as $input) {
                $option = $input->id === null ? new PosModifierOption : $group->options()->findOrFail($input->id);
                $option->fill([
                    'name' => trim($input->name),
                    'internal_name' => $input->internalName,
                    'price_delta' => $input->priceDelta,
                    'availability' => $input->availability,
                    'sort_order' => $input->sortOrder,
                    'active' => $input->active,
                ]);
                $option->property_id = $property->id;
                $option->pos_modifier_group_id = $group->id;
                $option->save();
                $keptIds[] = $option->id;
            }

            $group->options()->whereNotIn('id', $keptIds)->update(['active' => false]);
            $group->items()->sync(collect($itemIds)->mapWithKeys(fn (int $id, int $position): array => [$id => ['sort_order' => $position]])->all());

            activity()->performedOn($group)->causedBy($actor)->event($created ? 'pos.modifier_group_created' : 'pos.modifier_group_updated')
                ->withProperties(['property_id' => $property->id, 'name' => $group->name])
                ->log($created ? 'POS modifier group created' : 'POS modifier group updated');

            return PosActionResult::saved($group);
        });
    }
}

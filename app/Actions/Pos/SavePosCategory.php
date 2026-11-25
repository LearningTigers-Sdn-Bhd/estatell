<?php

namespace App\Actions\Pos;

use App\Data\Pos\PosCategoryInputData;
use App\Models\PosCategory;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a menu category. Its transaction code must be the property's and gives its items a default GL account
 * and taxes. Names are unique per property, ignoring case (`name_taken`). A refusal writes nothing.
 * See docs/34-native-pos/02-menu-catalog.md.
 */
class SavePosCategory
{
    public function __invoke(User $actor, Property $property, PosCategoryInputData $data, ?PosCategory $category = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $data, $category): PosActionResult {
            if ($category !== null && $category->property_id !== $property->id) {
                return PosActionResult::refused('category_invalid');
            }

            if (! TransactionCode::query()->where('property_id', $property->id)->whereKey($data->transactionCodeId)->exists()) {
                return PosActionResult::refused('transaction_code_invalid');
            }

            $name = trim($data->name);

            $taken = PosCategory::query()
                ->where('property_id', $property->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($category !== null, fn ($query) => $query->whereKeyNot($category->id))
                ->exists();

            if ($taken) {
                return PosActionResult::refused('name_taken');
            }

            $created = $category === null;
            $category ??= new PosCategory;
            $category->fill([
                'transaction_code_id' => $data->transactionCodeId,
                'name' => $name,
                'internal_name' => $data->internalName,
                'color_token' => $data->colorToken,
                'sort_order' => $data->sortOrder,
                'active' => $data->active,
            ]);
            $category->property_id = $property->id;
            $category->save();

            activity()->performedOn($category)->causedBy($actor)->event($created ? 'pos.category_created' : 'pos.category_updated')
                ->withProperties(['property_id' => $property->id, 'name' => $name])
                ->log($created ? 'POS category created' : 'POS category updated');

            return PosActionResult::saved($category);
        });
    }
}

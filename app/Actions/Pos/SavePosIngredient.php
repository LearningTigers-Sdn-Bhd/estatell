<?php

namespace App\Actions\Pos;

use App\Models\PosIngredient;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a stock ingredient, or change one. Names are unique per property ignoring case (`ingredient_name_taken`). The base
 * unit is required, and the purchase-to-base ratio must be more than zero (`ingredient_invalid`). Costs are not set here:
 * they come from purchases. A refusal writes nothing. See docs/34-native-pos/07-inventory-and-recipes.md.
 */
class SavePosIngredient
{
    public function __invoke(User $actor, Property $property, string $name, string $baseUnit, ?string $purchaseUnit, string $ratio, ?string $sku, ?string $threshold, bool $active, ?PosIngredient $ingredient = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $name, $baseUnit, $purchaseUnit, $ratio, $sku, $threshold, $active, $ingredient): PosActionResult {
            $name = trim($name);
            $baseUnit = trim($baseUnit);

            if ($ingredient !== null && $ingredient->property_id !== $property->id) {
                return PosActionResult::refused('ingredient_invalid');
            }

            if ($name === '' || mb_strlen($name) > 120 || $baseUnit === '' || mb_strlen($baseUnit) > 20 || ! is_numeric($ratio) || (float) $ratio <= 0) {
                return PosActionResult::refused('ingredient_invalid');
            }

            $taken = PosIngredient::query()
                ->where('property_id', $property->id)
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->when($ingredient !== null, fn ($query) => $query->whereKeyNot($ingredient->id))
                ->exists();

            if ($taken) {
                return PosActionResult::refused('ingredient_name_taken');
            }

            $created = $ingredient === null;
            $ingredient ??= new PosIngredient;
            $ingredient->fill([
                'name' => $name,
                'sku' => $sku === null || trim($sku) === '' ? null : trim($sku),
                'base_unit' => $baseUnit,
                'purchase_unit' => $purchaseUnit === null || trim($purchaseUnit) === '' ? null : trim($purchaseUnit),
                'purchase_to_base_ratio' => $ratio,
                'low_stock_threshold' => $threshold === null || trim($threshold) === '' ? null : $threshold,
                'active' => $active,
            ]);
            $ingredient->property_id = $property->id;
            $ingredient->save();
            $ingredient->refresh();

            activity()->performedOn($ingredient)->causedBy($actor)->event($created ? 'pos.ingredient_created' : 'pos.ingredient_updated')
                ->withProperties(['property_id' => $property->id, 'name' => $name])
                ->log($created ? 'POS ingredient created' : 'POS ingredient updated');

            return PosActionResult::saved($ingredient);
        });
    }
}

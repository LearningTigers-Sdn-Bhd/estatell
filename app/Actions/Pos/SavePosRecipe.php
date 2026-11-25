<?php

namespace App\Actions\Pos;

use App\Models\PosIngredient;
use App\Models\PosItemVariant;
use App\Models\PosRecipe;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Replace the recipe of one item variant: the ingredients it uses and how much of each, in the ingredient's base unit. An
 * empty list removes the recipe. Each ingredient must belong to the property and each quantity be more than zero
 * (`recipe_ingredient_invalid`, `recipe_quantity_invalid`). A refusal writes nothing.
 * See docs/34-native-pos/07-inventory-and-recipes.md.
 */
class SavePosRecipe
{
    /**
     * @param  array<int|string, string|int|float>  $quantities  Ingredient id to quantity in its base unit.
     */
    public function __invoke(User $actor, Property $property, PosItemVariant $variant, array $quantities): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $variant, $quantities): PosActionResult {
            if ($variant->property_id !== $property->id) {
                return PosActionResult::refused('variant_invalid');
            }

            $ids = array_map(fn (int|string $id): int => (int) $id, array_keys($quantities));

            if (PosIngredient::query()->where('property_id', $property->id)->whereIn('id', $ids)->count() !== count($ids)) {
                return PosActionResult::refused('recipe_ingredient_invalid');
            }

            foreach ($quantities as $quantity) {
                if (! is_numeric($quantity) || BigDecimal::of((string) $quantity)->isLessThanOrEqualTo(0)) {
                    return PosActionResult::refused('recipe_quantity_invalid');
                }
            }

            $variant->recipes()->delete();

            foreach ($quantities as $ingredientId => $quantity) {
                PosRecipe::query()->create([
                    'property_id' => $property->id,
                    'pos_item_variant_id' => $variant->id,
                    'pos_ingredient_id' => (int) $ingredientId,
                    'quantity_in_base_unit' => (string) $quantity,
                ]);
            }

            activity()->performedOn($variant)->causedBy($actor)->event('pos.recipe_saved')
                ->withProperties(['property_id' => $property->id, 'ingredients' => count($quantities)])
                ->log('POS recipe saved');

            return PosActionResult::saved($variant);
        });
    }
}

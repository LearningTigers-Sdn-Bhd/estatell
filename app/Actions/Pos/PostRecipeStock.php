<?php

namespace App\Actions\Pos;

use App\Enums\PosStockMovementKind;
use App\Models\PosIngredient;
use App\Models\PosOrderLine;
use App\Models\PosRecipe;
use App\Models\PosStockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Move the stock of a line's recipes: a fired line takes its ingredients out (`sale`), and a void that returns stock puts them
 * back (`void_return`). A modifier option's recipe is multiplied by the option's quantity. The line's unit cost is stamped on
 * the first fire, so later cost changes do not rewrite history. A product with no recipe moves nothing.
 */
class PostRecipeStock
{
    public function __invoke(PosOrderLine $line, PosStockMovementKind $kind): void
    {
        $line->loadMissing(['order', 'modifiers']);

        $optionQuantities = $line->modifiers->filter(fn ($modifier): bool => $modifier->pos_modifier_option_id !== null)
            ->groupBy('pos_modifier_option_id')
            ->map(fn ($rows): int => (int) $rows->sum('quantity'));

        $recipes = PosRecipe::query()
            ->where(function ($query) use ($line, $optionQuantities): void {
                $query->where('pos_item_variant_id', $line->pos_item_variant_id);

                if ($optionQuantities->isNotEmpty()) {
                    $query->orWhereIn('pos_modifier_option_id', $optionQuantities->keys()->all());
                }
            })
            ->get();

        if ($recipes->isEmpty()) {
            return;
        }

        $quantities = [];
        $unitCost = BigDecimal::zero();
        $ingredients = PosIngredient::query()->whereIn('id', $recipes->pluck('pos_ingredient_id'))->get()->keyBy('id');

        foreach ($recipes as $recipe) {
            $portions = $recipe->pos_item_variant_id !== null
                ? BigDecimal::of((string) $line->quantity)
                : BigDecimal::of((string) $line->quantity)->multipliedBy($optionQuantities[$recipe->pos_modifier_option_id] ?? 0);

            $used = BigDecimal::of((string) $recipe->quantity_in_base_unit)->multipliedBy($portions);
            $quantities[$recipe->pos_ingredient_id] = BigDecimal::of((string) ($quantities[$recipe->pos_ingredient_id] ?? '0'))->plus($used);
            $unitCost = $unitCost->plus(BigDecimal::of((string) $recipe->quantity_in_base_unit)->multipliedBy(BigDecimal::of((string) $ingredients[$recipe->pos_ingredient_id]->unit_cost))
                ->multipliedBy($recipe->pos_item_variant_id !== null ? 1 : ($optionQuantities[$recipe->pos_modifier_option_id] ?? 0)));
        }

        if ($kind === PosStockMovementKind::Sale && $line->unit_cost === null) {
            $line->forceFill(['unit_cost' => $unitCost->toScale(2, RoundingMode::HalfUp)->__toString()])->save();
        }

        $sign = $kind === PosStockMovementKind::Sale ? -1 : 1;

        foreach ($quantities as $ingredientId => $used) {
            if ($used->isZero()) {
                continue;
            }

            PosStockMovement::query()->create([
                'property_id' => $line->property_id,
                'pos_outlet_id' => $line->order->pos_outlet_id,
                'pos_ingredient_id' => $ingredientId,
                'kind' => $kind,
                'quantity_delta' => $used->multipliedBy($sign)->toScale(4, RoundingMode::HalfUp)->__toString(),
                'unit_cost' => $ingredients[$ingredientId]->unit_cost,
                'reference_type' => 'pos_order_line',
                'reference_id' => $line->id,
                'created_at' => now(),
            ]);
        }
    }
}

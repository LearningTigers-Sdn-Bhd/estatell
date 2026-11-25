<?php

namespace App\Actions\Pos;

use App\Enums\PosStockMovementKind;
use App\Models\PosIngredient;
use App\Models\PosOutlet;
use App\Models\PosStockMovement;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\PosStockBalances;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Receive a delivery at an outlet, in purchase units. It is converted to the base unit with the ingredient's ratio, and the
 * ingredient's cost becomes the moving average of what is on hand across the property and the new cost. A refusal writes
 * nothing (`quantity_invalid`, `cost_invalid`, `outlet_invalid`, `ingredient_invalid`).
 * See docs/34-native-pos/07-inventory-and-recipes.md.
 */
class ReceiveStockPurchase
{
    public function __construct(private PosStockBalances $balances) {}

    public function __invoke(User $actor, Property $property, PosOutlet $outlet, PosIngredient $ingredient, string $quantity, string $unitCost): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $ingredient, $quantity, $unitCost): PosActionResult {
            if ($outlet->property_id !== $property->id) {
                return PosActionResult::refused('outlet_invalid');
            }

            $ingredient = PosIngredient::query()->where('property_id', $property->id)->whereKey($ingredient->id)->lockForUpdate()->first();

            if ($ingredient === null) {
                return PosActionResult::refused('ingredient_invalid');
            }

            if (! is_numeric($quantity) || BigDecimal::of($quantity)->isLessThanOrEqualTo(0)) {
                return PosActionResult::refused('quantity_invalid');
            }

            if (! is_numeric($unitCost) || BigDecimal::of($unitCost)->isNegative()) {
                return PosActionResult::refused('cost_invalid');
            }

            $ratio = BigDecimal::of((string) $ingredient->purchase_to_base_ratio);
            $baseQuantity = BigDecimal::of($quantity)->multipliedBy($ratio)->toScale(4, RoundingMode::HalfUp);
            $baseCost = BigDecimal::of($unitCost)->dividedBy($ratio, 4, RoundingMode::HalfUp);

            $onHand = BigDecimal::of($this->balances->onHandAcrossOutlets($property->id, $ingredient->id));
            $previous = BigDecimal::of((string) $ingredient->unit_cost);
            $average = $onHand->isPositive()
                ? $onHand->multipliedBy($previous)->plus($baseQuantity->multipliedBy($baseCost))->dividedBy($onHand->plus($baseQuantity), 4, RoundingMode::HalfUp)
                : $baseCost;

            PosStockMovement::query()->create([
                'property_id' => $property->id,
                'pos_outlet_id' => $outlet->id,
                'pos_ingredient_id' => $ingredient->id,
                'kind' => PosStockMovementKind::PurchaseReceive,
                'quantity_delta' => $baseQuantity->__toString(),
                'unit_cost' => $baseCost->__toString(),
                'acted_by_user_id' => $actor->id,
                'created_at' => now(),
            ]);

            $ingredient->forceFill(['unit_cost' => $average->__toString()])->save();

            activity()->performedOn($ingredient)->causedBy($actor)->event('pos.stock_received')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id, 'quantity' => $baseQuantity->__toString()])
                ->log('POS stock received');

            return PosActionResult::saved($ingredient);
        });
    }
}

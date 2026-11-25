<?php

namespace App\Actions\Pos;

use App\Enums\PosStockMovementKind;
use App\Models\PosIngredient;
use App\Models\PosOutlet;
use App\Models\PosStockMovement;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Write off stock that spoiled, broke or was eaten by staff, in the base unit, with the reason in writing. A refusal writes
 * nothing (`quantity_invalid`, `waste_reason_required`).
 */
class RecordStockWaste
{
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, PosIngredient $ingredient, string $quantity, string $reason): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $ingredient, $quantity, $reason): PosActionResult {
            $reason = trim($reason);

            $ingredient = PosIngredient::query()->where('property_id', $property->id)->whereKey($ingredient->id)->first();

            if ($outlet->property_id !== $property->id || $ingredient === null) {
                return PosActionResult::refused('ingredient_invalid');
            }

            if (! is_numeric($quantity) || BigDecimal::of($quantity)->isLessThanOrEqualTo(0)) {
                return PosActionResult::refused('quantity_invalid');
            }

            if ($reason === '' || mb_strlen($reason) > 200) {
                return PosActionResult::refused('waste_reason_required');
            }

            PosStockMovement::query()->create([
                'property_id' => $property->id,
                'pos_outlet_id' => $outlet->id,
                'pos_ingredient_id' => $ingredient->id,
                'kind' => PosStockMovementKind::Waste,
                'quantity_delta' => BigDecimal::of($quantity)->negated()->__toString(),
                'unit_cost' => $ingredient->unit_cost,
                'reason' => $reason,
                'acted_by_user_id' => $actor->id,
                'created_at' => now(),
            ]);

            activity()->performedOn($ingredient)->causedBy($actor)->event('pos.stock_wasted')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id, 'quantity' => $quantity, 'reason' => $reason])
                ->log('POS stock wasted');

            return PosActionResult::saved($ingredient);
        });
    }
}

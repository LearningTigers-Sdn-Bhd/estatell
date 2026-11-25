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
 * Move stock from one outlet to another as a pair of ledger rows, one out and one in, in the same transaction. A refusal
 * writes nothing (`transfer_same_outlet`, `quantity_invalid`, `outlet_invalid`, `ingredient_invalid`).
 */
class TransferStock
{
    public function __invoke(User $actor, Property $property, PosIngredient $ingredient, PosOutlet $from, PosOutlet $to, string $quantity): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $ingredient, $from, $to, $quantity): PosActionResult {
            if ($from->property_id !== $property->id || $to->property_id !== $property->id) {
                return PosActionResult::refused('outlet_invalid');
            }

            if ($from->id === $to->id) {
                return PosActionResult::refused('transfer_same_outlet');
            }

            $ingredient = PosIngredient::query()->where('property_id', $property->id)->whereKey($ingredient->id)->first();

            if ($ingredient === null) {
                return PosActionResult::refused('ingredient_invalid');
            }

            if (! is_numeric($quantity) || BigDecimal::of($quantity)->isLessThanOrEqualTo(0)) {
                return PosActionResult::refused('quantity_invalid');
            }

            $moment = now();
            $reason = __('Transfer from :from to :to', ['from' => $from->name, 'to' => $to->name]);

            foreach ([[$from, PosStockMovementKind::TransferOut, BigDecimal::of($quantity)->negated()], [$to, PosStockMovementKind::TransferIn, BigDecimal::of($quantity)]] as [$outlet, $kind, $delta]) {
                PosStockMovement::query()->create([
                    'property_id' => $property->id,
                    'pos_outlet_id' => $outlet->id,
                    'pos_ingredient_id' => $ingredient->id,
                    'kind' => $kind,
                    'quantity_delta' => $delta->__toString(),
                    'unit_cost' => $ingredient->unit_cost,
                    'reason' => $reason,
                    'acted_by_user_id' => $actor->id,
                    'created_at' => $moment,
                ]);
            }

            activity()->performedOn($ingredient)->causedBy($actor)->event('pos.stock_transferred')
                ->withProperties(['property_id' => $property->id, 'from' => $from->id, 'to' => $to->id, 'quantity' => $quantity])
                ->log('POS stock transferred');

            return PosActionResult::saved($ingredient);
        });
    }
}

<?php

namespace App\Actions\Pos;

use App\Enums\PosStockMovementKind;
use App\Enums\PosStocktakeStatus;
use App\Models\PosStockMovement;
use App\Models\PosStocktake;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\PosStockBalances;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Close a count. Each counted ingredient is compared with the ledger as it stood at its own counted time, so a sale made
 * after the count is not counted twice. The difference is posted as a stocktake adjustment, and the variance cost is kept on
 * the line. A count with no lines is refused (`stocktake_empty`). A refusal writes nothing.
 * See docs/34-native-pos/07-inventory-and-recipes.md.
 */
class ClosePosStocktake
{
    public function __construct(private PosStockBalances $balances) {}

    public function __invoke(User $actor, Property $property, PosStocktake $stocktake): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $stocktake): PosActionResult {
            $stocktake = PosStocktake::query()->where('property_id', $property->id)->whereKey($stocktake->id)->lockForUpdate()->with('lines.ingredient')->first();

            if ($stocktake === null || $stocktake->status !== PosStocktakeStatus::Draft) {
                return PosActionResult::refused('stocktake_closed');
            }

            if ($stocktake->lines->isEmpty()) {
                return PosActionResult::refused('stocktake_empty');
            }

            $moment = now();

            foreach ($stocktake->lines as $line) {
                $expected = BigDecimal::of($this->balances->onHand($stocktake->pos_outlet_id, $line->pos_ingredient_id, $line->counted_at));
                $variance = BigDecimal::of((string) $line->counted_quantity)->minus($expected);
                $unitCost = BigDecimal::of((string) $line->ingredient->unit_cost);

                $line->forceFill([
                    'expected_quantity' => $expected->toScale(4, RoundingMode::HalfUp)->__toString(),
                    'variance_quantity' => $variance->toScale(4, RoundingMode::HalfUp)->__toString(),
                    'variance_cost' => $variance->multipliedBy($unitCost)->toScale(2, RoundingMode::HalfUp)->__toString(),
                ])->save();

                if (! $variance->isZero()) {
                    PosStockMovement::query()->create([
                        'property_id' => $property->id,
                        'pos_outlet_id' => $stocktake->pos_outlet_id,
                        'pos_ingredient_id' => $line->pos_ingredient_id,
                        'kind' => PosStockMovementKind::StocktakeAdjustment,
                        'quantity_delta' => $variance->toScale(4, RoundingMode::HalfUp)->__toString(),
                        'unit_cost' => $unitCost->__toString(),
                        'reference_type' => 'pos_stocktake',
                        'reference_id' => $stocktake->id,
                        'acted_by_user_id' => $actor->id,
                        'created_at' => $moment,
                    ]);
                }
            }

            $stocktake->forceFill([
                'status' => PosStocktakeStatus::Closed,
                'closed_by_user_id' => $actor->id,
                'closed_at' => $moment,
            ])->save();

            activity()->performedOn($stocktake)->causedBy($actor)->event('pos.stocktake_closed')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $stocktake->pos_outlet_id])
                ->log('POS stocktake closed');

            return PosActionResult::saved($stocktake);
        });
    }
}

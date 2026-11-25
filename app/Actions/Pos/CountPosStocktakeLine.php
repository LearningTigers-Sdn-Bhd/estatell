<?php

namespace App\Actions\Pos;

use App\Enums\PosStocktakeStatus;
use App\Models\PosIngredient;
use App\Models\PosStocktake;
use App\Models\PosStocktakeLine;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Record the count of one ingredient on an open stocktake, with the exact time it was counted. Recounting replaces the
 * earlier count. A refusal writes nothing (`stocktake_closed`, `ingredient_invalid`, `quantity_invalid`).
 */
class CountPosStocktakeLine
{
    public function __invoke(User $actor, Property $property, PosStocktake $stocktake, PosIngredient $ingredient, string $counted, ?CarbonImmutable $countedAt = null): PosActionResult
    {
        return DB::transaction(function () use ($property, $stocktake, $ingredient, $counted, $countedAt): PosActionResult {
            $stocktake = PosStocktake::query()->where('property_id', $property->id)->whereKey($stocktake->id)->lockForUpdate()->first();

            if ($stocktake === null || $stocktake->status !== PosStocktakeStatus::Draft) {
                return PosActionResult::refused('stocktake_closed');
            }

            if (PosIngredient::query()->where('property_id', $property->id)->whereKey($ingredient->id)->doesntExist()) {
                return PosActionResult::refused('ingredient_invalid');
            }

            if (! is_numeric($counted) || BigDecimal::of($counted)->isNegative()) {
                return PosActionResult::refused('quantity_invalid');
            }

            $line = PosStocktakeLine::query()->updateOrCreate(
                ['pos_stocktake_id' => $stocktake->id, 'pos_ingredient_id' => $ingredient->id],
                ['counted_at' => $countedAt ?? now(), 'counted_quantity' => $counted],
            );

            return PosActionResult::saved($line);
        });
    }
}

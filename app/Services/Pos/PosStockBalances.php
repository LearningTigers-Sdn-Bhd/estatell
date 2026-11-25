<?php

namespace App\Services\Pos;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The on-hand quantity of an ingredient, summed from the immutable ledger. Reads only; never a stored balance.
 */
class PosStockBalances
{
    public function onHand(int $outletId, int $ingredientId, ?CarbonInterface $at = null): string
    {
        $query = DB::table('pos_stock_movements')->where('pos_outlet_id', $outletId)->where('pos_ingredient_id', $ingredientId);

        if ($at !== null) {
            $query->where('created_at', '<=', $at);
        }

        return (string) $query->selectRaw('coalesce(sum(quantity_delta), 0)::text as total')->value('total');
    }

    /**
     * The on-hand quantity of one ingredient summed over every outlet of the property. Used for the moving-average cost.
     */
    public function onHandAcrossOutlets(int $propertyId, int $ingredientId): string
    {
        return (string) DB::table('pos_stock_movements')
            ->where('property_id', $propertyId)
            ->where('pos_ingredient_id', $ingredientId)
            ->selectRaw('coalesce(sum(quantity_delta), 0)::text as total')
            ->value('total');
    }

    /**
     * The on-hand quantity of every ingredient at one outlet, keyed by ingredient id.
     *
     * @return array<int, string>
     */
    public function forOutlet(int $outletId): array
    {
        return DB::table('pos_stock_movements')
            ->where('pos_outlet_id', $outletId)
            ->groupBy('pos_ingredient_id')
            ->selectRaw('pos_ingredient_id, coalesce(sum(quantity_delta), 0)::text as total')
            ->pluck('total', 'pos_ingredient_id')
            ->map(fn (mixed $total): string => (string) $total)
            ->all();
    }
}

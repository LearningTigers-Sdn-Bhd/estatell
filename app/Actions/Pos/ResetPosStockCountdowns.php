<?php

namespace App\Actions\Pos;

use App\Models\PosItem;
use App\Models\PosOutletItem;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * The daily stock reset the Night Audit runs as it opens a new business date: every outlet variant with a daily reset
 * number goes back to that count, and so does every item with a shared daily reset number. Counts with no reset number
 * stay until staff restock them by hand. Returns how many counts were reset.
 */
class ResetPosStockCountdowns
{
    public function __invoke(Property $property): int
    {
        $variants = PosOutletItem::query()
            ->where('property_id', $property->id)
            ->whereNotNull('daily_stock_reset')
            ->where(fn ($query) => $query->whereNull('stock_countdown')->orWhereColumn('stock_countdown', '!=', 'daily_stock_reset'))
            ->update(['stock_countdown' => DB::raw('daily_stock_reset')]);

        $items = PosItem::query()
            ->where('property_id', $property->id)
            ->whereNotNull('daily_shared_stock_reset')
            ->where(fn ($query) => $query->whereNull('shared_stock_count')->orWhereColumn('shared_stock_count', '!=', 'daily_shared_stock_reset'))
            ->update(['shared_stock_count' => DB::raw('daily_shared_stock_reset')]);

        return $variants + $items;
    }
}

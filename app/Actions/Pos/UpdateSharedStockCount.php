<?php

namespace App\Actions\Pos;

use App\Models\PosItem;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Set the stock shared by every variant of one item (a pot of soup sold in two sizes), and the number the Night Audit
 * resets it to each day. A null count turns the shared countdown off. The item must be the property's. A refusal writes nothing.
 */
class UpdateSharedStockCount
{
    public function __invoke(User $actor, Property $property, PosItem $item, ?int $count, ?int $dailyReset = null): PosActionResult
    {
        if ($item->property_id !== $property->id) {
            return PosActionResult::refused('item_invalid');
        }

        return DB::transaction(function () use ($actor, $property, $item, $count, $dailyReset): PosActionResult {
            $item = PosItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $item->forceFill(['shared_stock_count' => $count, 'daily_shared_stock_reset' => $dailyReset])->save();

            activity()->performedOn($item)->causedBy($actor)->event('pos.shared_stock_updated')
                ->withProperties(['property_id' => $property->id, 'count' => $count])
                ->log('POS shared stock updated');

            return PosActionResult::saved($item);
        });
    }
}

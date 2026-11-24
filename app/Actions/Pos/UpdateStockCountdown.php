<?php

namespace App\Actions\Pos;

use App\Models\PosItemVariant;
use App\Models\PosOutlet;
use App\Models\PosOutletItem;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Set how many of a variant one outlet has left, and the number the Night Audit resets it to each day (null means it
 * stays until restocked by hand). A null count turns the countdown off. The count may be below zero after staff sold past
 * the stock. The outlet and variant must be the property's. A refusal writes nothing.
 */
class UpdateStockCountdown
{
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, PosItemVariant $variant, ?int $count, ?int $dailyReset = null): PosActionResult
    {
        if ($outlet->property_id !== $property->id || $variant->property_id !== $property->id) {
            return PosActionResult::refused('outlet_invalid');
        }

        return DB::transaction(function () use ($actor, $property, $outlet, $variant, $count, $dailyReset): PosActionResult {
            $row = PosOutletItem::query()->where('pos_outlet_id', $outlet->id)->where('pos_item_variant_id', $variant->id)->lockForUpdate()->first() ?? new PosOutletItem;
            $row->fill(['stock_countdown' => $count, 'daily_stock_reset' => $dailyReset]);
            $row->property_id = $property->id;
            $row->pos_outlet_id = $outlet->id;
            $row->pos_item_variant_id = $variant->id;
            $row->save();

            activity()->performedOn($row)->causedBy($actor)->event('pos.stock_countdown_updated')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id, 'count' => $count])
                ->log('POS stock countdown updated');

            return PosActionResult::saved($row);
        });
    }
}

<?php

namespace App\Actions\Pos;

use App\Models\PosCategory;
use App\Models\PosItem;
use App\Models\PosKitchenStation;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Route categories and items to kitchen stations. An item with no station uses its category's; with neither, its fired
 * lines show on no display. Each id must be of this property, and each station of this outlet (`station_invalid`). A
 * refusal writes nothing. See docs/34-native-pos/06-kitchen-kds-and-qr.md#kitchen-stations--ticket-routing.
 */
class SavePosKitchenRouting
{
    /**
     * @param  array<int|string, int|null>  $categoryStations  category id to station id, null to unroute
     * @param  array<int|string, int|null>  $itemStations  item id to station id, null to unroute
     */
    public function __invoke(User $actor, Property $property, PosOutlet $outlet, array $categoryStations, array $itemStations): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $outlet, $categoryStations, $itemStations): PosActionResult {
            $stationIds = collect([...array_values($categoryStations), ...array_values($itemStations)])->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values();

            $valid = PosKitchenStation::query()
                ->where('property_id', $property->id)
                ->where('pos_outlet_id', $outlet->id)
                ->whereIn('id', $stationIds)
                ->pluck('id')
                ->all();

            if (count($valid) !== $stationIds->count()) {
                return PosActionResult::refused('station_invalid');
            }

            foreach ($categoryStations as $categoryId => $stationId) {
                PosCategory::query()->where('property_id', $property->id)->whereKey((int) $categoryId)
                    ->update(['kitchen_station_id' => $stationId === null ? null : (int) $stationId]);
            }

            foreach ($itemStations as $itemId => $stationId) {
                PosItem::query()->where('property_id', $property->id)->whereKey((int) $itemId)
                    ->update(['kitchen_station_id' => $stationId === null ? null : (int) $stationId]);
            }

            activity()->causedBy($actor)->event('pos.kitchen_routing_saved')
                ->withProperties(['property_id' => $property->id, 'pos_outlet_id' => $outlet->id])
                ->log('POS kitchen routing saved');

            return PosActionResult::saved($outlet);
        });
    }
}

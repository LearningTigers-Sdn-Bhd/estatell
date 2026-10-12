<?php

namespace App\Services\Boats;

use App\Models\BoatDeparture;
use App\Models\ChargeItem;
use App\Models\Property;

/**
 * Reads how many boat departures use each fare. Reads only; the Actions decide what to do with the counts.
 */
class BoatFareUsage
{
    /**
     * Available departures per fare, keyed by charge item id. A fare no available departure uses is not in the list.
     *
     * @return array<int, int>
     */
    public function availableCounts(Property $property): array
    {
        return BoatDeparture::query()
            ->where('property_id', $property->id)
            ->where('active', true)
            ->whereNotNull('charge_item_id')
            ->selectRaw('charge_item_id, count(*) as departures')
            ->groupBy('charge_item_id')
            ->get()
            ->mapWithKeys(fn (BoatDeparture $row): array => [(int) $row->charge_item_id => (int) $row->departures])
            ->all();
    }

    /** Whether any departure, available or not, uses the fare. */
    public function isUsedByAnyDeparture(ChargeItem $chargeItem): bool
    {
        return BoatDeparture::query()->where('charge_item_id', $chargeItem->id)->exists();
    }

    /** Whether an available departure uses the fare. Pass `$lock` inside a transaction to hold those rows. */
    public function isUsedByAvailableDeparture(ChargeItem $chargeItem, bool $lock = false): bool
    {
        $query = BoatDeparture::query()->where('charge_item_id', $chargeItem->id)->where('active', true);

        return ($lock ? $query->lockForUpdate() : $query)->exists();
    }
}

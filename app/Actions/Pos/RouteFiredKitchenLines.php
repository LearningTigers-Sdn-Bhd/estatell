<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Models\PosKitchenStation;
use App\Models\PosOrderLine;
use App\Models\PosOutlet;
use App\Services\Pos\KitchenDisplay;

/**
 * Give the lines that were sent to the kitchen before a station was set up the station of their item or category, so
 * they show on its screen. Only fired lines of open checks at this outlet are touched; a line that still has no station is
 * left as it is. Returns how many lines were routed. Run it after a station or its routing is saved.
 */
class RouteFiredKitchenLines
{
    public function __construct(private KitchenDisplay $kitchenDisplay, private BumpKitchenVersion $bumpKitchenVersion) {}

    public function __invoke(PosOutlet $outlet): int
    {
        $lines = PosOrderLine::query()
            ->where('property_id', $outlet->property_id)
            ->whereHas('order', fn ($orders) => $orders->where('pos_outlet_id', $outlet->id)->where('status', PosOrderStatus::Open->value))
            ->where('kitchen_status', PosKitchenStatus::Fired->value)
            ->where('line_kind', '!=', PosOrderLineKind::PackagingFee->value)
            ->whereNull('kitchen_station_id')
            ->with('variant.item.category')
            ->lockForUpdate()
            ->get();

        $routed = 0;
        $stationIds = [];

        foreach ($lines as $line) {
            $stationId = $this->kitchenDisplay->stationIdFor($line);

            if ($stationId === null) {
                continue;
            }

            $line->forceFill(['kitchen_station_id' => $stationId])->save();
            $stationIds[$stationId] = true;
            $routed++;
        }

        foreach (array_keys($stationIds) as $stationId) {
            ($this->bumpKitchenVersion)(PosKitchenStation::query()->find($stationId));
        }

        return $routed;
    }
}

<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\PosKitchenStation;
use App\Models\PosOrder;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Bump a ticket: every line of one order that this station still has Fired or Preparing becomes Ready. A recall puts the
 * ready lines of a bumped ticket back to Preparing. A refusal writes nothing (`nothing_to_bump`, `nothing_to_recall`).
 * See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class BumpKitchenOrder
{
    public function __construct(private BumpKitchenVersion $bumpKitchenVersion) {}

    public function __invoke(Property $property, PosKitchenStation $station, PosOrder $order, bool $recall = false): PosActionResult
    {
        return DB::transaction(function () use ($property, $station, $order, $recall): PosActionResult {
            $station = PosKitchenStation::query()->where('property_id', $property->id)->whereKey($station->id)->first();
            $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

            if ($station === null || $order === null || $order->pos_outlet_id !== $station->pos_outlet_id) {
                return PosActionResult::refused('station_invalid');
            }

            if ($order->status !== PosOrderStatus::Open) {
                return PosActionResult::refused('order_not_open');
            }

            $lines = $order->lines()
                ->where('kitchen_station_id', $station->id)
                ->whereIn('kitchen_status', $recall ? [PosKitchenStatus::Ready->value] : [PosKitchenStatus::Fired->value, PosKitchenStatus::Preparing->value])
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                return PosActionResult::refused($recall ? 'nothing_to_recall' : 'nothing_to_bump');
            }

            foreach ($lines as $line) {
                $line->forceFill($recall
                    ? ['kitchen_status' => PosKitchenStatus::Preparing, 'ready_at' => null]
                    : ['kitchen_status' => PosKitchenStatus::Ready, 'ready_at' => now()])->save();
            }

            ($this->bumpKitchenVersion)($station);

            return PosActionResult::saved($order->refresh());
        });
    }
}

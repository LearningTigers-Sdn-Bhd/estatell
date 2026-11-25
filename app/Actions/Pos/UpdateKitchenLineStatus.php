<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\PosOrderLine;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * Move one fired line along the kitchen path: Fired to Preparing or Ready, Preparing to Ready, Ready back to Preparing or on
 * to Served. The time of each step is kept. A voided line, an unrouted line, or a line of a closed order is refused, and a
 * step the path does not allow is refused (`line_status_invalid`). A refusal writes nothing.
 * See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class UpdateKitchenLineStatus
{
    public function __construct(private BumpKitchenVersion $bumpKitchenVersion) {}

    public function __invoke(Property $property, PosOrderLine $line, PosKitchenStatus $to): PosActionResult
    {
        return DB::transaction(function () use ($property, $line, $to): PosActionResult {
            $line = PosOrderLine::query()->where('property_id', $property->id)->whereKey($line->id)->lockForUpdate()->with(['order', 'kitchenStation'])->first();

            if ($line === null) {
                return PosActionResult::refused('line_invalid');
            }

            if ($line->order->status !== PosOrderStatus::Open) {
                return PosActionResult::refused('order_not_open');
            }

            if ($line->kitchen_station_id === null) {
                return PosActionResult::refused('line_not_routed');
            }

            if (! $line->kitchen_status->canTransitionTo($to)) {
                return PosActionResult::refused('line_status_invalid');
            }

            $line->forceFill(match ($to) {
                PosKitchenStatus::Preparing => ['kitchen_status' => $to, 'preparing_at' => now(), 'ready_at' => null],
                PosKitchenStatus::Ready => ['kitchen_status' => $to, 'ready_at' => now()],
                PosKitchenStatus::Served => ['kitchen_status' => $to, 'served_at' => now()],
                default => ['kitchen_status' => $to],
            })->save();

            ($this->bumpKitchenVersion)($line->kitchenStation);

            return PosActionResult::saved($line);
        });
    }
}

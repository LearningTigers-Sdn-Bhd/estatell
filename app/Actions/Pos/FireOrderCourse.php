<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Release a held course to the kitchen: its Held lines go through `FireOrderLines`, so they are stamped, take their stock and
 * can no longer be removed quietly. Nothing held in that course (`course_not_held`) is refused. Returns the order.
 */
class FireOrderCourse
{
    public function __construct(private FireOrderLines $fireOrderLines) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, int $course): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $order, $course): PosActionResult {
            $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

            if ($order === null) {
                return PosActionResult::refused('order_invalid');
            }

            if ($order->status !== PosOrderStatus::Open) {
                return PosActionResult::refused('order_not_open');
            }

            $ids = array_values($order->lines()->where('kitchen_status', PosKitchenStatus::Held->value)->where('course_number', $course)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

            if ($ids === []) {
                return PosActionResult::refused('course_not_held');
            }

            $order->lines()->whereIn('id', $ids)->update(['kitchen_status' => PosKitchenStatus::Draft->value]);

            activity()->performedOn($order)->causedBy($actor)->event('pos.course_fired')
                ->withProperties(['property_id' => $property->id, 'course' => $course])
                ->log('POS course fired');

            return ($this->fireOrderLines)($actor, $property, $order, $ids);
        });
    }
}

<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hold a course so a later course waits for the table: every Draft line of that course becomes Held and is not sent to the
 * kitchen until `FireOrderCourse`. Only course 2 and up can be held (`course_invalid`), because course 1 is sent straight away.
 * Fired lines are not touched. Returns the order. A refusal writes nothing.
 */
class HoldOrderCourse
{
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

            if ($course < 2 || $course > 9) {
                return PosActionResult::refused('course_invalid');
            }

            $held = $order->lines()
                ->where('kitchen_status', PosKitchenStatus::Draft->value)
                ->where('line_kind', '!=', PosOrderLineKind::PackagingFee->value)
                ->where('course_number', $course)
                ->update(['kitchen_status' => PosKitchenStatus::Held->value]);

            if ($held > 0) {
                activity()->performedOn($order)->causedBy($actor)->event('pos.course_held')
                    ->withProperties(['property_id' => $property->id, 'course' => $course, 'lines' => $held])
                    ->log('POS course held');
            }

            return PosActionResult::saved($order->refresh());
        });
    }
}

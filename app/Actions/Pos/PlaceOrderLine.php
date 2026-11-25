<?php

namespace App\Actions\Pos;

use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderLineKind;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Put a line at a course and a seat after it was added: the course it is sent with (1 to 9) and the seat that ordered it
 * (1 to 99, or 0 to clear), which a split by seat uses. A course can only change while the line is still Draft or Held
 * (`line_locked`), because a sent line is already in the kitchen; a line moved to course 1 that was Held is released as
 * Draft so it is not stuck. The seat can change on any live line. A combo part follows its combo (`combo_part_locked`), and a
 * voided or packaging line cannot be placed (`line_locked`). A refusal writes nothing.
 */
class PlaceOrderLine
{
    public function __invoke(User $actor, Property $property, PosOrder $order, PosOrderLine $line, ?int $course, ?int $seat): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $order, $line, $course, $seat): PosActionResult {
            $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();
            $line = $order === null ? null : PosOrderLine::query()->where('pos_order_id', $order->id)->lockForUpdate()->find($line->id);

            if ($order === null || $line === null) {
                return PosActionResult::refused('order_invalid');
            }

            if ($order->status !== PosOrderStatus::Open) {
                return PosActionResult::refused('order_not_open');
            }

            if ($line->kitchen_status === PosKitchenStatus::Voided || $line->line_kind === PosOrderLineKind::PackagingFee) {
                return PosActionResult::refused('line_locked');
            }

            if ($line->parent_combo_line_id !== null) {
                return PosActionResult::refused('combo_part_locked');
            }

            if (($course !== null && ($course < 1 || $course > 9)) || ($seat !== null && ($seat < 0 || $seat > 99))) {
                return PosActionResult::refused('placement_invalid');
            }

            $change = [];

            if ($course !== null && $course !== $line->course_number) {
                if (! in_array($line->kitchen_status, [PosKitchenStatus::Draft, PosKitchenStatus::Held], true)) {
                    return PosActionResult::refused('line_locked');
                }

                $change['course_number'] = $course;

                if ($course === 1 && $line->kitchen_status === PosKitchenStatus::Held) {
                    $change['kitchen_status'] = PosKitchenStatus::Draft;
                }
            }

            if ($seat !== null) {
                $change['seat_number'] = $seat === 0 ? null : $seat;
            }

            if ($change !== []) {
                $line->forceFill($change)->save();
                PosOrderLine::query()->where('parent_combo_line_id', $line->id)->update(array_map(fn (mixed $value): mixed => $value instanceof PosKitchenStatus ? $value->value : $value, $change));

                activity()->performedOn($order)->causedBy($actor)->event('pos.line_placed')
                    ->withProperties(['property_id' => $property->id, 'pos_order_line_id' => $line->id, 'course' => $course, 'seat' => $seat])
                    ->log('POS line placed');
            }

            return PosActionResult::saved($line->refresh());
        });
    }
}

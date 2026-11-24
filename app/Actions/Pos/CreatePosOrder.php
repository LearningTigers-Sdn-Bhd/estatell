<?php

namespace App\Actions\Pos;

use App\Enums\PosOrderStatus;
use App\Enums\PosShiftStatus;
use App\Enums\UnitStatus;
use App\Features\NativePos;
use App\Models\PosDiningOption;
use App\Models\PosOrder;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Open a counter or table order on a register's open shift. The client sends a `client_uuid`, so a tap that is sent twice
 * returns the same order and writes nothing twice. The dining option must be the shift outlet's own and active
 * (`dining_option_invalid`). A room may be named up front for meal entitlement and room charge: it must be in house
 * (`unit_not_eligible`). A shift that is not Open takes no orders (`shift_not_open`). A refusal writes nothing.
 */
class CreatePosOrder
{
    public function __construct(private CurrentBusinessDate $currentBusinessDate) {}

    public function __invoke(User $actor, Property $property, PosShift $shift, PosDiningOption $diningOption, string $clientUuid, int $guestCount = 1, ?ReservationUnit $unit = null): PosActionResult
    {
        return DB::transaction(function () use ($actor, $property, $shift, $diningOption, $clientUuid, $guestCount, $unit): PosActionResult {
            if (! Feature::for($property)->active(NativePos::class)) {
                return PosActionResult::refused('native_pos_disabled');
            }

            $existing = PosOrder::query()->where('property_id', $property->id)->where('client_uuid', $clientUuid)->first();

            if ($existing !== null) {
                return PosActionResult::replayed($existing);
            }

            $shift = PosShift::query()->where('property_id', $property->id)->whereKey($shift->id)->lockForUpdate()->first();

            if ($shift === null) {
                return PosActionResult::refused('shift_invalid');
            }

            if ($shift->status !== PosShiftStatus::Open) {
                return PosActionResult::refused('shift_not_open');
            }

            if ($diningOption->property_id !== $property->id || $diningOption->pos_outlet_id !== $shift->pos_outlet_id || ! $diningOption->active) {
                return PosActionResult::refused('dining_option_invalid');
            }

            if ($guestCount < 1 || $guestCount > 99) {
                return PosActionResult::refused('guest_count_invalid');
            }

            if ($unit !== null && ! $this->eligible($property, $unit)) {
                return PosActionResult::refused('unit_not_eligible');
            }

            $order = new PosOrder(['guest_count' => $guestCount]);
            $order->property_id = $property->id;
            $order->pos_outlet_id = $shift->pos_outlet_id;
            $order->pos_shift_id = $shift->id;
            $order->client_uuid = $clientUuid;
            $order->pos_dining_option_id = $diningOption->id;
            $order->reservation_unit_id = $unit?->id;
            $order->status = PosOrderStatus::Open;
            $order->opened_by_user_id = $actor->id;
            $order->opened_at = now();
            $order->save();

            activity()->performedOn($order)->causedBy($actor)->event('pos.order_opened')
                ->withProperties(['property_id' => $property->id, 'pos_shift_id' => $shift->id, 'reservation_unit_id' => $unit?->id])
                ->log('POS order opened');

            return PosActionResult::saved($order);
        });
    }

    private function eligible(Property $property, ReservationUnit $unit): bool
    {
        $unit = ReservationUnit::query()->where('property_id', $property->id)->find($unit->id);
        $businessDate = $this->currentBusinessDate->date($property);

        return $unit !== null && $businessDate !== null
            && ($unit->status === UnitStatus::CheckedIn
                || ($unit->status === UnitStatus::CheckedOut && $unit->departure_date->toDateString() === $businessDate->toDateString()));
    }
}

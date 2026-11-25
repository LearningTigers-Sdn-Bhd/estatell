<?php

namespace App\Actions\Pos;

use App\Enums\PosOrderStatus;
use App\Models\PosDiningOption;
use App\Models\PosOrder;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Switch an open order to another dining option of its outlet (Dine-In to Takeaway) and price it again. Once a pre-bill was
 * printed it needs a manager (`approval_required`), because dropping the service charge by switching to Takeaway is a classic
 * way to skim. The option must be the outlet's and active (`dining_option_invalid`). A refusal writes nothing.
 */
class ChangePosDiningOption
{
    public function __construct(private RecalculatePosOrder $recalculate, private BumpCfdVersion $bumpCfdVersion) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, PosDiningOption $option, ?User $approver = null): PosActionResult
    {
        try {
            return DB::transaction(function () use ($actor, $property, $order, $option, $approver): PosActionResult {
                $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

                if ($order === null) {
                    return PosActionResult::refused('order_invalid');
                }

                if ($order->status !== PosOrderStatus::Open) {
                    return PosActionResult::refused('order_not_open');
                }

                if ($option->property_id !== $property->id || $option->pos_outlet_id !== $order->pos_outlet_id || ! $option->active) {
                    return PosActionResult::refused('dining_option_invalid');
                }

                if ($order->prebill_print_count >= 1 && $approver === null) {
                    return PosActionResult::refused('approval_required');
                }

                $from = $order->pos_dining_option_id;
                $order->forceFill(['pos_dining_option_id' => $option->id])->save();
                $order->unsetRelation('diningOption');

                $result = ($this->recalculate)($order);

                if ($result->isRefused()) {
                    throw new PosRefusedException((string) $result->refusal);
                }

                activity()->performedOn($order)->causedBy($actor)->event('pos.dining_option_changed')
                    ->withProperties(['property_id' => $property->id, 'from' => $from, 'to' => $option->id, 'approved_by_user_id' => $approver?->id])
                    ->log('POS dining option changed');

                $registerId = $order->shift?->pos_register_id;

                if ($registerId !== null) {
                    ($this->bumpCfdVersion)($registerId);
                }

                return PosActionResult::saved($order->refresh());
            });
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }
}

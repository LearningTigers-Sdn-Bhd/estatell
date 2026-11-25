<?php

namespace App\Actions\Pos;

use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Take a discount off one line, or off the whole bill when no line is given, and price the order again. After a pre-bill
 * was printed it needs a manager (`approval_required`), as applying one does. A bill discount is deleted only while the
 * order is open, since nothing is sold yet. A refusal writes nothing.
 */
class RemovePosDiscount
{
    public function __construct(private RecalculatePosOrder $recalculate, private BumpCfdVersion $bumpCfdVersion) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, ?PosOrderLine $line = null, ?User $approver = null): PosActionResult
    {
        try {
            $registerId = null;
            $saved = DB::transaction(function () use ($actor, $property, $order, $line, $approver, &$registerId): PosActionResult {
                $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

                if ($order === null) {
                    return PosActionResult::refused('order_invalid');
                }

                if ($order->status !== PosOrderStatus::Open) {
                    return PosActionResult::refused('order_not_open');
                }

                if ($order->prebill_print_count >= 1 && $approver === null) {
                    return PosActionResult::refused('approval_required');
                }

                if ($line !== null) {
                    $current = PosOrderLine::query()->where('pos_order_id', $order->id)->find($line->id);

                    if ($current === null) {
                        return PosActionResult::refused('order_invalid');
                    }

                    $current->forceFill(['discount_type' => null, 'discount_value' => null, 'discount_amount' => '0.00', 'exclude_addons_from_discount' => false, 'discount_reason' => null, 'discount_approved_by_user_id' => null])->save();
                } else {
                    $order->discounts()->delete();
                }

                $result = ($this->recalculate)($order);

                if ($result->isRefused()) {
                    throw new PosRefusedException((string) $result->refusal);
                }

                activity()->performedOn($order)->causedBy($actor)->event('pos.discount_removed')
                    ->withProperties(['property_id' => $property->id, 'pos_order_line_id' => $line?->id, 'approved_by_user_id' => $approver?->id])
                    ->log('POS discount removed');

                $registerId = $order->shift?->pos_register_id;

                return PosActionResult::saved($order->refresh());
            });

            if (! $saved->isRefused() && $registerId !== null) {
                ($this->bumpCfdVersion)($registerId);
            }

            return $saved;
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }
}

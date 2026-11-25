<?php

namespace App\Actions\Pos;

use App\Enums\PosDiscountType;
use App\Enums\PosOrderStatus;
use App\Models\PosOrder;
use App\Models\PosOrderDiscount;
use App\Models\PosOrderLine;
use App\Models\Property;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Discount one line or the whole bill, before service charge and tax, and price the order again. A percent is above 0 and
 * at most 100, an amount is not negative (`discount_invalid`). A typed-in discount, a set price, a free-of-charge comp, and
 * any discount at all once a pre-bill was printed, needs a manager (`approval_required`; the approver has already been checked
 * for `pos.discount`). A bill discount replaces the last one. Paid add-ons stay out of a line discount when
 * `$excludeAddons`. The approver and reason are kept on the row and in the log. A refusal writes nothing.
 * See docs/34-native-pos/03-floors-and-orders.md#discounts-vs-payment-vouchers.
 */
class ApplyPosDiscount
{
    public function __construct(private RecalculatePosOrder $recalculate) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, PosDiscountType $type, string $value, ?PosOrderLine $line = null, ?string $reason = null, ?User $approver = null, bool $excludeAddons = false): PosActionResult
    {
        try {
            return DB::transaction(function () use ($actor, $property, $order, $type, $value, $line, $reason, $approver, $excludeAddons): PosActionResult {
                $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();

                if ($order === null) {
                    return PosActionResult::refused('order_invalid');
                }

                if ($order->status !== PosOrderStatus::Open) {
                    return PosActionResult::refused('order_not_open');
                }

                $asked = $line !== null;
                $line = $asked ? PosOrderLine::query()->where('pos_order_id', $order->id)->find($line->id) : null;

                if ($asked && $line === null) {
                    return PosActionResult::refused('order_invalid');
                }

                if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value) !== 1 || ($type->isPercent() && (BigDecimal::of($value)->isZero() || BigDecimal::of($value)->isGreaterThan(100)))) {
                    return PosActionResult::refused('discount_invalid');
                }

                if (($type->needsApproval() || $order->prebill_print_count >= 1) && $approver === null) {
                    return PosActionResult::refused('approval_required');
                }

                $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

                if ($line !== null) {
                    $line->forceFill([
                        'discount_type' => $type,
                        'discount_value' => BigDecimal::of($value)->toScale(2)->__toString(),
                        'exclude_addons_from_discount' => $excludeAddons,
                        'discount_reason' => $reason,
                        'discount_approved_by_user_id' => $approver?->id,
                    ])->save();
                } else {
                    $discount = new PosOrderDiscount;
                    $discount->property_id = $property->id;
                    $discount->pos_order_id = $order->id;
                    $discount->name = $type->label();
                    $discount->discount_type = $type;
                    $discount->value = BigDecimal::of($value)->toScale(2)->__toString();
                    $discount->amount = '0.00';
                    $discount->reason = $reason;
                    $discount->applied_by_user_id = $actor->id;
                    $discount->approved_by_user_id = $approver?->id;
                    $discount->save();
                }

                $result = ($this->recalculate)($order);

                if ($result->isRefused()) {
                    throw new PosRefusedException((string) $result->refusal);
                }

                if ($line === null) {
                    $order->discounts()->orderByDesc('id')->firstOrFail()->forceFill(['amount' => array_reduce($result->lines, fn (BigDecimal $total, $row): BigDecimal => $total->plus($row->orderDiscountShare), BigDecimal::zero())->toScale(2)->__toString()])->save();
                }

                activity()->performedOn($order)->causedBy($actor)->event('pos.discount_applied')
                    ->withProperties(['property_id' => $property->id, 'type' => $type->value, 'value' => $value, 'pos_order_line_id' => $line?->id, 'approved_by_user_id' => $approver?->id, 'reason' => $reason])
                    ->log('POS discount applied');

                return PosActionResult::saved($order->refresh());
            });
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }
}

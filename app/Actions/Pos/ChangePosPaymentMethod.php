<?php

namespace App\Actions\Pos;

use App\Enums\PosOrderStatus;
use App\Enums\PosPaymentDirection;
use App\Enums\PosPaymentStatus;
use App\Enums\PosReconciliationStatus;
use App\Enums\PosShiftStatus;
use App\Enums\PosTenderType;
use App\Models\PosOrder;
use App\Models\PosOrderPayment;
use App\Models\PosOutletPaymentMethod;
use App\Models\PosShift;
use App\Models\Property;
use App\Models\User;
use App\Services\Pos\MalaysiaCashRounding;
use App\Services\Pos\OpenPosBusinessDate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Correct the way a settled order was paid: the wrong tender was keyed, so the payment is swapped for another tender of the
 * same amount. Allowed only while the order's shift is still Open and its business date is still the property's open one
 * (`tender_swap_not_allowed`), the payment has no refund against it (`payment_has_refunds`), and neither tender is a room
 * charge, which is on the folio. A manager approves (`approval_required`). The old row is marked Replaced and a new Captured row
 * takes its place, so nothing is edited and a closed shift still ties out. Cash rounding is worked out again for the new
 * tender and the order's rounding follows. A refusal writes nothing.
 */
class ChangePosPaymentMethod
{
    public function __construct(private OpenPosBusinessDate $openPosBusinessDate, private MalaysiaCashRounding $cashRounding) {}

    public function __invoke(User $actor, Property $property, PosOrder $order, PosOrderPayment $payment, PosTenderType $tender, ?int $paymentMethodId = null, ?string $reference = null, ?User $approver = null): PosActionResult
    {
        try {
            return DB::transaction(fn (): PosActionResult => $this->swap($actor, $property, $order, $payment, $tender, $paymentMethodId, $reference, $approver));
        } catch (PosRefusedException $refused) {
            return PosActionResult::refused($refused->refusal);
        }
    }

    private function swap(User $actor, Property $property, PosOrder $order, PosOrderPayment $payment, PosTenderType $tender, ?int $paymentMethodId, ?string $reference, ?User $approver): PosActionResult
    {
        if ($approver === null) {
            return PosActionResult::refused('approval_required');
        }

        $businessDate = $this->openPosBusinessDate->lock($property);

        if (is_string($businessDate)) {
            return PosActionResult::refused($businessDate);
        }

        $order = PosOrder::query()->where('property_id', $property->id)->whereKey($order->id)->lockForUpdate()->first();
        $shift = $order?->pos_shift_id === null ? null : PosShift::query()->whereKey($order->pos_shift_id)->lockForUpdate()->first();
        $payment = $order === null ? null : PosOrderPayment::query()->where('pos_order_id', $order->id)->whereKey($payment->id)->lockForUpdate()->first();

        if ($order === null || $payment === null) {
            return PosActionResult::refused('order_invalid');
        }

        if ($order->status !== PosOrderStatus::Settled || $payment->direction !== PosPaymentDirection::In || $payment->status !== PosPaymentStatus::Captured) {
            return PosActionResult::refused('tender_swap_not_allowed');
        }

        if ($shift === null || $shift->status !== PosShiftStatus::Open || $order->business_date?->toDateString() !== $businessDate->date->toDateString()) {
            return PosActionResult::refused('tender_swap_not_allowed');
        }

        if ($payment->tender_type === PosTenderType::RoomCharge || $tender === PosTenderType::RoomCharge || $tender === $payment->tender_type) {
            return PosActionResult::refused('tender_swap_not_allowed');
        }

        if (PosOrderPayment::query()->where('refund_of_payment_id', $payment->id)->exists()) {
            return PosActionResult::refused('payment_has_refunds');
        }

        $method = PosOutletPaymentMethod::query()->where('pos_outlet_id', $order->pos_outlet_id)->where('active', true)->where('tender_type', $tender->value)
            ->when($paymentMethodId !== null, fn ($query) => $query->where('payment_method_id', $paymentMethodId))->orderBy('sort_order')->orderBy('id')->first();

        if ($method === null) {
            return PosActionResult::refused('payment_method_invalid');
        }

        $new = new PosOrderPayment;
        $new->property_id = $property->id;
        $new->pos_order_id = $order->id;
        $new->pos_shift_id = $shift->id;
        $new->idempotency_key = 'swap:'.$payment->id;
        $new->direction = PosPaymentDirection::In;
        $new->tender_type = $tender;
        $new->payment_method_id = $method->payment_method_id;
        $new->reconciliation_status = PosReconciliationStatus::Verified;
        $new->amount = $payment->amount;
        $new->rounding_adjustment = '0.00';
        $new->status = PosPaymentStatus::Captured;
        $new->acted_by_user_id = $actor->id;
        $new->approved_by_user_id = $approver->id;
        $new->reference = $reference;
        $new->paid_at = now();

        if ($tender === PosTenderType::Cash) {
            $outlet = $order->outlet;
            $adjustment = $this->cashRounding->adjustment($outlet->rounding_mode, $outlet->rounding_policy, $order->grand_total, $payment->amount);
            $new->rounding_adjustment = $adjustment;
            $due = BigDecimal::of($payment->amount)->plus($adjustment)->toScale(2)->__toString();
            $new->tendered_amount = $due;
            $new->change_amount = '0.00';
        }

        $new->save();
        $payment->forceFill(['status' => PosPaymentStatus::Replaced, 'replaced_by_payment_id' => $new->id])->save();

        $order->forceFill(['rounding_adjustment' => (string) BigDecimal::of((string) PosOrderPayment::query()->where('pos_order_id', $order->id)->where('direction', PosPaymentDirection::In->value)->where('status', PosPaymentStatus::Captured->value)->sum('rounding_adjustment'))->toScale(2)])->save();

        activity()->performedOn($order)->causedBy($actor)->event('pos.payment_method_changed')
            ->withProperties(['property_id' => $property->id, 'from_pos_order_payment_id' => $payment->id, 'to_pos_order_payment_id' => $new->id, 'from' => $payment->tender_type->value, 'to' => $tender->value, 'approved_by_user_id' => $approver->id])
            ->log('POS payment method changed');

        return PosActionResult::saved($new->refresh());
    }
}

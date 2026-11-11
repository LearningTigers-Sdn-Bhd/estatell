<?php

namespace App\Actions\Concierge;

use App\Actions\Payments\RecordPayment;
use App\Data\Payments\NewPaymentData;
use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentPurpose;
use App\Enums\UnitStatus;
use App\Models\GatewayPayment;
use App\Models\PaymentMethod;
use App\Models\ReservationUnit;
use Illuminate\Support\Facades\DB;

class RecordStayPayment
{
    public function __construct(private RecordPayment $recordPayment) {}

    /**
     * Post a paid stay order on the unit folio with the Online method: an Advance before check-in, a Settlement once the
     * guest is checked in. Both ways the news arrives call it:
     * the guest's own page after checkout (signature checked by `ConfirmStayPayment`) and Curlec's `order.paid` webhook,
     * so it is safe to repeat: the folio line has the key `online:{order}:{unit}` and a second call posts nothing more.
     * Returns false, writing nothing, for an order that is not a stay order, for another payment id than the one the order
     * was paid with, and when the payment could not be posted (no Online method, unit closed or cancelled). The caller
     * decides who is told.
     */
    public function __invoke(GatewayPayment $payment, string $gatewayPaymentId): bool
    {
        return DB::transaction(function () use ($payment, $gatewayPaymentId): bool {
            $locked = GatewayPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->reservation_unit_id === null || $gatewayPaymentId === '') {
                return false;
            }

            if ($locked->gateway_payment_id !== null && $locked->gateway_payment_id !== $gatewayPaymentId) {
                return false;
            }

            if ($locked->status->canTransitionTo(GatewayPaymentStatus::Paid)) {
                $locked->forceFill(['status' => GatewayPaymentStatus::Paid, 'gateway_payment_id' => $gatewayPaymentId])->save();
            }

            if ($locked->status !== GatewayPaymentStatus::Paid) {
                return false;
            }

            $unit = ReservationUnit::query()->where('property_id', $locked->property_id)->find($locked->reservation_unit_id);
            $method = PaymentMethod::query()->where('property_id', $locked->property_id)->where('type', PaymentMethodType::Online)->first();

            if ($unit === null || $method === null) {
                return false;
            }

            $key = "online:{$locked->order_id}:{$unit->id}";
            $result = ($this->recordPayment)(null, $unit->reservation()->firstOrFail(), null, $unit, new NewPaymentData(
                paymentMethodId: $method->id,
                purpose: $unit->status === UnitStatus::CheckedIn ? PaymentPurpose::Settlement : PaymentPurpose::Advance,
                amount: $locked->amount,
                reference: $gatewayPaymentId,
                idempotencyKey: $key,
                lineKey: $key,
                allowOnline: true,
            ));

            return ! $result->isRefused();
        });
    }
}

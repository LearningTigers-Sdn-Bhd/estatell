<?php

namespace App\Actions\Concierge;

use App\Actions\Payments\AlertOnlinePaymentProblem;
use App\Models\GatewayPayment;
use App\Models\ReservationUnit;
use App\Services\Payments\CurlecGateway;

class ConfirmStayPayment
{
    public function __construct(
        private CurlecGateway $gateway,
        private RecordStayPayment $recordStayPayment,
        private AlertOnlinePaymentProblem $alert,
    ) {}

    /**
     * The guest's page reports a finished checkout with Curlec's order id, payment id and signature. The signature must
     * match the order, and the order must be one of this stay: another stay's order, or one that is not a stay order, is
     * ignored. Money that was taken but could not be posted (the stay was cancelled meanwhile) alerts the admins. False,
     * writing nothing, for a bad signature or an unknown order. Curlec's webhook posts the same payment if this never runs.
     */
    public function __invoke(ReservationUnit $stay, string $orderId, string $paymentId, string $signature): bool
    {
        if (! $this->gateway->verifySignature($orderId, $paymentId, $signature)) {
            return false;
        }

        $payment = GatewayPayment::query()
            ->where('property_id', $stay->property_id)
            ->where('reservation_unit_id', $stay->id)
            ->where('order_id', $orderId)
            ->first();

        if ($payment === null) {
            return false;
        }

        $recorded = ($this->recordStayPayment)($payment, $paymentId);

        if (! $recorded) {
            ($this->alert)($payment->refresh(), __('A guest paid for a stay and the payment could not be posted to the folio. Check the stay, then post or refund the payment.'));
        }

        return $recorded;
    }
}

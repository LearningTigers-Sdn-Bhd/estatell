<?php

namespace App\Actions\Payments;

use App\Enums\PaymentMethodType;
use App\Models\GatewayPayment;
use App\Models\Payment;
use App\Models\User;

class RefundHeldPayment
{
    public function __construct(
        private RefundPayment $refundPayment,
        private RequestOnlineRefund $requestOnlineRefund,
    ) {}

    /**
     * Give money back from a payment the way its method allows. Every staff refund comes through here. An Online payment is
     * asked of Curlec and posts when Curlec confirms, and any other goes through `RefundPayment` at once with its `$notes`.
     * See docs/29-booking-engine/05-cancel-and-refund.md#rules-for-online-payments.
     */
    public function __invoke(User $actor, Payment $payment, string $amount, ?string $reference, string $idempotencyKey, ?int $methodId = null, ?string $reason = null, ?string $notes = null): PaymentResult
    {
        if ($payment->paymentMethod->type !== PaymentMethodType::Online) {
            return ($this->refundPayment)($actor, $payment, $amount, $reference, $idempotencyKey, methodId: $methodId, reason: $reason, notes: $notes);
        }

        if ($methodId !== null && $methodId !== $payment->payment_method_id) {
            return PaymentResult::refused(PaymentResult::REFUSAL_ONLINE_REFUND_ONLY);
        }

        $gatewayPayment = GatewayPayment::query()->where('property_id', $payment->property_id)->where('gateway_payment_id', $payment->reference)->first();

        if ($gatewayPayment === null) {
            return PaymentResult::refused(PaymentResult::REFUSAL_PAYMENT_NOT_FOUND);
        }

        $result = ($this->requestOnlineRefund)($actor, $gatewayPayment, [['payment' => $payment, 'amount' => $amount]], $amount, $idempotencyKey, $reason);

        return $result->isRefused() ? PaymentResult::refused((string) $result->refusal) : PaymentResult::requested();
    }
}

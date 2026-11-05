<?php

namespace App\Actions\Payments;

use App\Actions\BookingEngine\ConfirmBookingHold;
use App\Actions\BookingEngine\MarkBookingHoldPaid;
use App\Enums\GatewayPaymentStatus;
use App\Models\GatewayEvent;
use App\Models\GatewayPayment;

/**
 * Act on one saved Curlec event. An order estatell did not create, and any event it does not use, is marked processed and
 * ignored. Returns false when the booking could not be made: the event stays unprocessed for the queue to retry, and
 * admins are alerted on the first attempt only. See docs/29-booking-engine/04-payment.md#webhook.
 */
class ProcessCurlecEvent
{
    public function __construct(
        private MarkBookingHoldPaid $markBookingHoldPaid,
        private ConfirmBookingHold $confirmBookingHold,
        private AlertOnlinePaymentProblem $alert,
    ) {}

    public function __invoke(GatewayEvent $event, bool $isFirstAttempt = true): bool
    {
        if ($event->processed_at !== null) {
            return true;
        }

        $isDone = match ($event->event_type) {
            'order.paid' => $this->orderPaid($event, $isFirstAttempt),
            'payment.failed' => $this->paymentFailed($event),
            default => true,
        };

        if ($isDone) {
            $event->update(['processed_at' => now()]);
        }

        return $isDone;
    }

    private function orderPaid(GatewayEvent $event, bool $isFirstAttempt): bool
    {
        $payment = $this->payment(data_get($event->payload, 'payload.order.entity.id'));

        if ($payment === null) {
            return true;
        }

        $this->record($payment, GatewayPaymentStatus::Paid, $event);

        $hold = $payment->hold;

        if (! ($this->markBookingHoldPaid)($hold)) {
            ($this->alert)($payment, __('The guest paid after the hold expired and the rooms are taken. No booking was made. Refund the whole payment from the Curlec dashboard.'));

            return true;
        }

        $result = ($this->confirmBookingHold)($hold, $payment);

        if ($result->refusal === null) {
            return true;
        }

        if ($isFirstAttempt) {
            ($this->alert)($payment, __('The guest paid and the booking was refused (:code). It will be tried again.', ['code' => $result->refusal]));
        }

        return false;
    }

    /**
     * A failed attempt only changes the record. The guest can retry the same order until the hold expires.
     */
    private function paymentFailed(GatewayEvent $event): bool
    {
        $payment = $this->payment(data_get($event->payload, 'payload.payment.entity.order_id'));

        if ($payment !== null) {
            $this->record($payment, GatewayPaymentStatus::Failed, $event);
        }

        return true;
    }

    private function payment(mixed $orderId): ?GatewayPayment
    {
        return is_string($orderId) ? GatewayPayment::query()->with('hold')->where('order_id', $orderId)->first() : null;
    }

    /**
     * Only a move the status allows is saved, so a late `payment.failed` cannot undo a Paid order.
     */
    private function record(GatewayPayment $payment, GatewayPaymentStatus $status, GatewayEvent $event): void
    {
        if (! $payment->status->canTransitionTo($status)) {
            return;
        }

        $entity = data_get($event->payload, 'payload.payment.entity');
        $paymentId = data_get($entity, 'id');

        $payment->update([
            'status' => $status,
            'gateway_payment_id' => $status === GatewayPaymentStatus::Paid && is_string($paymentId) ? $paymentId : $payment->gateway_payment_id,
            'gateway_payload' => is_array($entity) ? $entity : $payment->gateway_payload,
        ]);
    }
}

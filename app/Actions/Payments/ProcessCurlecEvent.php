<?php

namespace App\Actions\Payments;

use App\Actions\BookingEngine\ConfirmBookingHold;
use App\Actions\BookingEngine\MarkBookingHoldPaid;
use App\Enums\GatewayPaymentStatus;
use App\Models\GatewayEvent;
use App\Models\GatewayPayment;
use App\Models\GatewayRefund;
use App\Notifications\LateOnlinePaymentRefunded;
use App\Services\Notifications\MailActorResolver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;

/**
 * Act on one saved Curlec event. An order estatell did not create, and any event it does not use, is marked processed and
 * ignored. Returns false when the booking or a refund's folio line could not be made: the event stays unprocessed for the
 * queue to retry, and admins are alerted on the first attempt only. See docs/29-booking-engine/04-payment.md#webhook.
 */
class ProcessCurlecEvent
{
    public function __construct(
        private MarkBookingHoldPaid $markBookingHoldPaid,
        private ConfirmBookingHold $confirmBookingHold,
        private AlertOnlinePaymentProblem $alert,
        private AlertOnlineRefundProblem $alertRefund,
        private RequestOnlineRefund $requestOnlineRefund,
        private CompleteOnlineRefund $completeOnlineRefund,
        private FailOnlineRefund $failOnlineRefund,
    ) {}

    public function __invoke(GatewayEvent $event, bool $isFirstAttempt = true): bool
    {
        if ($event->processed_at !== null) {
            return true;
        }

        $isDone = match ($event->event_type) {
            'order.paid' => $this->orderPaid($event, $isFirstAttempt),
            'payment.failed' => $this->paymentFailed($event),
            'refund.processed' => $this->refundProcessed($event, $isFirstAttempt),
            'refund.failed' => $this->refundFailed($event),
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
            $this->refundLatePayment($payment->refresh());

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

    /**
     * The rooms of a late payment are taken: give all of it back, and tell the guest, staff and admins once.
     */
    private function refundLatePayment(GatewayPayment $payment): void
    {
        $result = ($this->requestOnlineRefund)(null, $payment, [], $payment->amount, "late:{$payment->order_id}", __('Paid after the hold expired and the rooms are taken'));

        if ($result->isRefused()) {
            ($this->alert)($payment, __('The guest paid after the hold expired and the rooms are taken. No booking was made, and the refund was refused (:code). Refund the whole payment from the Curlec dashboard.', ['code' => $result->refusal]));

            return;
        }

        if ($result->replayed || $result->refund === null) {
            return;
        }

        ($this->alertRefund)($result->refund, __('A guest paid :amount after the hold expired and the rooms are taken. No booking was made, and the whole payment is being refunded automatically.', ['amount' => $payment->amount]));

        $hold = $payment->hold;
        $property = $payment->property()->with('settings')->firstOrFail();

        if (filled($hold->guest_email)) {
            Context::scope(fn () => Notification::route('mail', (string) $hold->guest_email)->notify(new LateOnlinePaymentRefunded(
                $property->name,
                (string) $hold->guest_name,
                $property->settings->currency->getCurrencyCode().' '.$payment->amount,
            )), hidden: [MailActorResolver::PROPERTY_CONTEXT_KEY => $property->id]);
        }
    }

    /**
     * Curlec gave the money back. A refund with no row is left alone unless it is on a payment estatell took, which means it
     * was made outside estatell, so admins are told to record it. Auto-refunds of uncaptured payments never have a row.
     */
    private function refundProcessed(GatewayEvent $event, bool $isFirstAttempt): bool
    {
        $entity = data_get($event->payload, 'payload.refund.entity');
        $curlecRefundId = data_get($entity, 'id');
        $refund = $this->refund($event);

        if ($refund === null) {
            $this->alertIfMadeOutsideEstatell($event);

            return true;
        }

        if (! is_string($curlecRefundId) || $curlecRefundId === '') {
            return true;
        }

        $refusal = ($this->completeOnlineRefund)($refund, $curlecRefundId, is_array($entity) ? $entity : null);

        if ($refusal === null) {
            return true;
        }

        if ($isFirstAttempt) {
            ($this->alertRefund)($refund, __('Curlec refunded :amount but the folio refused the line (:code). It will be tried again.', ['amount' => $refund->amount, 'code' => $refusal]));
        }

        return false;
    }

    private function refundFailed(GatewayEvent $event): bool
    {
        $refund = $this->refund($event);

        if ($refund !== null) {
            $entity = data_get($event->payload, 'payload.refund.entity');
            $reason = data_get($event->payload, 'payload.payment.entity.error_description');

            ($this->failOnlineRefund)($refund, is_string($reason) && $reason !== '' ? $reason : __('Curlec could not process the refund.'), is_array($entity) ? $entity : null);
        }

        return true;
    }

    /**
     * The row of a refund event: by Curlec's ID, or by our ID in the notes when the webhook beat the answer to our request.
     */
    private function refund(GatewayEvent $event): ?GatewayRefund
    {
        $entity = data_get($event->payload, 'payload.refund.entity');
        $curlecRefundId = data_get($entity, 'id');

        if (is_string($curlecRefundId) && $curlecRefundId !== '') {
            $refund = GatewayRefund::query()->where('gateway_refund_id', $curlecRefundId)->first();

            if ($refund !== null) {
                return $refund;
            }
        }

        $ourId = data_get($entity, 'notes.refund');

        return data_get($entity, 'notes.app') === 'estatell' && is_numeric($ourId)
            ? GatewayRefund::query()->whereKey((int) $ourId)->whereNull('gateway_refund_id')->first()
            : null;
    }

    private function alertIfMadeOutsideEstatell(GatewayEvent $event): void
    {
        $entity = data_get($event->payload, 'payload.refund.entity');
        $paymentId = data_get($entity, 'payment_id');

        if (data_get($event->payload, 'payload.payment.entity.captured') !== true || data_get($entity, 'notes.app') === 'estatell' || ! is_string($paymentId)) {
            return;
        }

        $payment = GatewayPayment::query()->where('gateway_payment_id', $paymentId)->first();

        if ($payment !== null) {
            ($this->alert)($payment, __('A refund was made outside estatell (:refund). Record it on the manual folio, with the refund ID as its reference.', ['refund' => (string) data_get($entity, 'id')]));
        }
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

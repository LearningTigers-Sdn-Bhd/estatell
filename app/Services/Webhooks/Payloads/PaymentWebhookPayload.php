<?php

namespace App\Services\Webhooks\Payloads;

use App\Enums\WebhookEvent;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment or refund, with the booking it belongs to. A refund is a payment row with `refund_of_id` set.
 */
class PaymentWebhookPayload implements WebhookPayloadBuilder
{
    public function __construct(private BookingWebhookPayload $bookings) {}

    public function build(WebhookEvent $event, Model $subject, array $context): ?array
    {
        if (! $subject instanceof Payment) {
            return null;
        }

        $subject->loadMissing(['paymentMethod', 'folio']);
        $reservation = Reservation::query()->find($subject->folio->reservation_id);

        return [
            'payment' => [
                'id' => $subject->id,
                'amount' => $subject->amount,
                'purpose' => $subject->purpose->value,
                'status' => $subject->status->value,
                'method' => $subject->paymentMethod->name,
                'reference' => $subject->reference,
                'receipt_number' => $subject->receipt_number,
                'received_on' => $subject->received_on->toDateString(),
                'received_at' => $subject->received_at->toIso8601String(),
                'refund_of_id' => $subject->refund_of_id,
                'refund_reason' => $subject->refund_reason,
                'voided' => $subject->voided,
                'folio_number' => $subject->folio->number,
            ],
            'booking' => $reservation === null ? null : $this->bookings->booking($reservation),
        ];
    }
}

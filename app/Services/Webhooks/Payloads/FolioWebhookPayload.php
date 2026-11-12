<?php

namespace App\Services\Webhooks\Payloads;

use App\Enums\WebhookEvent;
use App\Models\Folio;
use App\Models\Reservation;
use App\Services\Folio\FolioBalance;
use Illuminate\Database\Eloquent\Model;

/**
 * A closed folio with its balance, and the booking it belongs to.
 */
class FolioWebhookPayload implements WebhookPayloadBuilder
{
    public function __construct(private BookingWebhookPayload $bookings, private FolioBalance $balance) {}

    public function build(WebhookEvent $event, Model $subject, array $context): ?array
    {
        if (! $subject instanceof Folio) {
            return null;
        }

        $reservation = Reservation::query()->find($subject->reservation_id);

        return [
            'folio' => [
                'id' => $subject->id,
                'number' => $subject->number,
                'type' => $subject->type->value,
                'status' => $subject->status->value,
                'invoice_number' => $subject->invoice_number,
                'unit_id' => $subject->reservation_unit_id,
                'balance' => $this->balance->forFolio($subject),
                'closed_at' => $subject->closed_at?->toIso8601String(),
            ],
            'booking' => $reservation === null ? null : $this->bookings->booking($reservation),
        ];
    }
}

<?php

namespace App\Actions\Kiosk;

use App\Actions\Webhooks\TriggerWebhook;
use App\Enums\WebhookEvent;
use App\Models\KioskWalkInHold;
use App\Models\Reservation;

/**
 * Send `booking.created` for a walk-in once its hold is kept: the first payment or the check-in, whichever comes first.
 * `BookWalkIn` holds the room with an empty booker and sends nothing, so listeners get one `booking.created` with the
 * booker's name, email and phone from the ID step, and a hold nobody pays sends nothing at all. `announced_at` makes
 * a second payment or a check-in after the payment send nothing more. Does nothing for a booking with no walk-in hold.
 * Called inside the caller's transaction. See docs/32-webhooks/01-outgoing-webhooks.md#events.
 */
class AnnounceWalkInBooking
{
    public function __invoke(int $propertyId, int $reservationId): void
    {
        $hold = KioskWalkInHold::query()
            ->where('property_id', $propertyId)
            ->whereNull('released_at')
            ->whereNull('announced_at')
            ->whereHas('stay', fn ($stays) => $stays->where('property_id', $propertyId)->where('reservation_id', $reservationId))
            ->lockForUpdate()
            ->first();

        if ($hold === null) {
            return;
        }

        $hold->forceFill(['announced_at' => now()])->save();

        app(TriggerWebhook::class)(WebhookEvent::BookingCreated, $propertyId, Reservation::class, $reservationId);
    }
}

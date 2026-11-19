<?php

namespace App\Actions\Kiosk;

use App\Actions\Webhooks\TriggerWebhook;
use App\Enums\StayType;
use App\Enums\WebhookEvent;
use App\Models\KioskWalkInHold;
use App\Models\Reservation;
use App\Notifications\ConciergeWalkInConfirmed;
use App\Services\Kiosk\KioskStays;
use App\Services\Notifications\MailActorResolver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Send `booking.created` for a walk-in once its hold is kept: the first payment or the check-in, whichever comes first.
 * `BookWalkIn` holds the room with an empty booker and sends nothing, so listeners get one `booking.created` with the
 * booker's name, email and phone from the ID step, and a hold nobody pays sends nothing at all. `announced_at` makes
 * a second payment or a check-in after the payment send nothing more. It also emails the guest the booking number and the
 * link to check in, when the ID step left an address. Does nothing for a booking with no walk-in hold.
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

        $this->confirmByEmail($hold);
    }

    private function confirmByEmail(KioskWalkInHold $hold): void
    {
        $stay = $hold->stay()->with(['property.settings', 'roomType', 'reservation'])->first();
        $guest = $stay === null ? null : app(KioskStays::class)->guest($stay);

        if ($stay === null || $guest === null || blank($guest->email)) {
            return;
        }

        $dayUse = $stay->stay_type === StayType::DayUse;

        Context::scope(fn () => Notification::route('mail', $guest->email)->notify(new ConciergeWalkInConfirmed(
            $stay->property->name,
            Str::of($guest->full_name)->trim()->before(' ')->toString() ?: __('guest'),
            $stay->reservation->reference,
            $stay->reservation->short_code,
            $stay->roomType->name,
            $stay->arrival_date->toDateString(),
            $dayUse ? null : $stay->departure_date->toDateString(),
            $dayUse ? substr($stay->arrival_time, 0, 5) : null,
            app(IssueBookingPreCheckInToken::class)($stay),
        )), hidden: [MailActorResolver::PROPERTY_CONTEXT_KEY => $stay->property_id]);
    }
}

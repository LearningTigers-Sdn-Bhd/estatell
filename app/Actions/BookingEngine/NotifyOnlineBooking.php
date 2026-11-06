<?php

namespace App\Actions\BookingEngine;

use App\Models\BookingHold;
use App\Models\BookingHoldLine;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\OnlineBookingConfirmed;
use App\Notifications\OnlineBookingReceived;
use App\Services\BookingEngine\BookingManageLink;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Email the guest the confirmation with the Manage booking link, and tell the property's reservation staff. `ConfirmBookingHold`
 * runs this after its transaction commits, once per order. The staff are the active staff of the property with
 * `reservations.manage`, and the notification applies the property and user preferences. The guest email goes to the
 * address on the hold. See docs/29-booking-engine/04-payment.md#confirmation.
 */
class NotifyOnlineBooking
{
    public function __construct(private BookingManageLink $manageLink) {}

    public function __invoke(BookingHold $hold, Reservation $reservation): void
    {
        $hold->loadMissing(['property.settings', 'lines.roomType']);
        $property = $hold->property;
        $guestName = (string) $hold->guest_name;

        if ($hold->guest_email !== null && $hold->guest_email !== '') {
            Notification::route('mail', $hold->guest_email)->notify(new OnlineBookingConfirmed(
                $reservation->reference,
                $reservation->short_code,
                $property->name,
                $guestName,
                $hold->arrival_date->toDateString(),
                $hold->departure_date->toDateString(),
                array_values($hold->lines->map(fn (BookingHoldLine $line): string => $line->roomType->name)->unique()->all()),
                $property->settings->currency->getCurrencyCode().' '.number_format((float) $hold->lines->sum('amount'), 2),
                $this->manageLink->url($hold),
            ));
        }

        $message = new OnlineBookingReceived($reservation->id, $reservation->reference, $property->code, $property->name, $guestName, $hold->arrival_date->toDateString());

        Notification::send($this->staff($reservation, $message), $message);
    }

    /**
     * @return Collection<int, User>
     */
    private function staff(Reservation $reservation, OnlineBookingReceived $message): Collection
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($reservation->property_id);

        try {
            return User::query()
                ->where('property_id', $reservation->property_id)
                ->where('is_admin', false)
                ->whereNull('deactivated_at')
                ->permission((string) $message->type->recipientPermission())
                ->get();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }
}

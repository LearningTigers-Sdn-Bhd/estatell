<?php

namespace App\Actions\BookingEngine;

use App\Models\Reservation;
use App\Models\User;
use App\Notifications\OnlineBookingCancelled;
use App\Notifications\OnlineBookingCancelledByGuest;
use App\Services\BookingEngine\OnlineCancellationQuote;
use App\Services\Notifications\MailActorResolver;
use App\Services\Staff\PropertyStaff;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;

class NotifyOnlineCancellation
{
    public function __construct(private PropertyStaff $propertyStaff) {}

    /**
     * Tell the guest and the reservation staff that a guest cancelled online, with the refund. The guest email goes to the
     * reservation's booker, because a hold empties its guest details after 30 days and a guest can book 6 months ahead. With
     * no email only staff are told. The caller runs this after its transaction has committed.
     * See docs/29-booking-engine/05-cancel-and-refund.md#flow.
     */
    public function __invoke(Reservation $reservation, OnlineCancellationQuote $quote): void
    {
        $reservation->loadMissing(['property.settings', 'booker']);
        $property = $reservation->property;
        $guestName = (string) $reservation->booker?->full_name;
        $email = $reservation->booker?->email;
        $currency = $property->settings->currency->getCurrencyCode();
        $arrival = (string) $reservation->units()->min('arrival_date');

        if ($email !== null && $email !== '') {
            Context::scope(fn () => Notification::route('mail', $email)->notify(new OnlineBookingCancelled(
                $reservation->reference,
                $property->name,
                $guestName,
                $arrival,
                $currency.' '.number_format((float) $quote->fee + (float) $quote->tax, 2),
                $currency.' '.number_format((float) $quote->refund, 2),
                (float) $quote->refund > 0,
            )), hidden: [MailActorResolver::PROPERTY_CONTEXT_KEY => $property->id]);
        }

        $message = new OnlineBookingCancelledByGuest($reservation->id, $reservation->reference, $property->code, $property->name, $guestName, $arrival);

        Notification::send($this->staff($reservation, $message), $message);
    }

    /**
     * @return Collection<int, User>
     */
    private function staff(Reservation $reservation, OnlineBookingCancelledByGuest $message): Collection
    {
        return $this->propertyStaff->withPermission($reservation->property_id, (string) $message->type->recipientPermission());
    }
}

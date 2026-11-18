<?php

namespace App\Actions\BookingEngine;

use App\Enums\PaymentMode;
use App\Models\PropertyBookingSite;
use App\Models\User;

/**
 * Switch the booking website between Test and Live payments. Only new payments use the new mode: a payment keeps the
 * mode it was made in. False, writing nothing, when the site is already in that mode.
 * See docs/29-booking-engine/04-payment.md#payment-mode.
 */
class SetBookingSitePaymentMode
{
    public function __invoke(PropertyBookingSite $site, User $actor, PaymentMode $mode): bool
    {
        $site->update(['payment_mode' => $mode]);

        if (! $site->wasChanged('payment_mode')) {
            return false;
        }

        activity()
            ->performedOn($site)
            ->causedBy($actor)
            ->event('booking-website.payment-mode-set')
            ->withProperties(['property_id' => $site->property_id, 'payment_mode' => $mode->value])
            ->log('Booking website payment mode set');

        return true;
    }
}

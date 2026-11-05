<?php

namespace App\Actions\BookingEngine;

use App\Models\BookingReturnAddress;
use App\Models\User;

/**
 * Stop accepting a return address for new payments. A payment that already named it still returns the guest there.
 */
class RemoveBookingReturnAddress
{
    public function __invoke(User $actor, BookingReturnAddress $address): void
    {
        $address->delete();

        activity()
            ->performedOn($address)
            ->causedBy($actor)
            ->event('booking-website.return-address-removed')
            ->withProperties(['property_id' => $address->property_id, 'url' => $address->url])
            ->log('Booking return address removed');
    }
}

<?php

namespace App\Actions\BookingEngine;

use App\Models\BookingReturnAddress;
use App\Models\Property;
use App\Models\User;

/**
 * Approve a page on the owner's website as a return address. Returns false, writing nothing, when the property already
 * has it or has reached the limit. Most sites need one or two; the cap keeps the list short enough for the owner to check.
 */
class AddBookingReturnAddress
{
    public const int LIMIT = 10;

    public function __invoke(User $actor, Property $property, string $url): bool
    {
        $addresses = $property->bookingReturnAddresses();

        if ((clone $addresses)->where('url', $url)->exists() || (clone $addresses)->count() >= self::LIMIT) {
            return false;
        }

        $address = BookingReturnAddress::query()->createOrFirst(['property_id' => $property->id, 'url' => $url]);

        if (! $address->wasRecentlyCreated) {
            return false;
        }

        activity()
            ->performedOn($address)
            ->causedBy($actor)
            ->event('booking-website.return-address-added')
            ->withProperties(['property_id' => $property->id, 'url' => $url])
            ->log('Booking return address added');

        return true;
    }
}

<?php

namespace App\Actions\BookingEngine;

use App\Models\PropertyBookingSite;
use App\Models\User;

/**
 * Take the booking site offline. Returns false, and writes nothing, when it is not published. Guests who already booked keep their Manage booking link.
 */
class UnpublishBookingSite
{
    public function __invoke(PropertyBookingSite $site, User $actor): bool
    {
        if (! $site->isPublished()) {
            return false;
        }

        $site->forceFill(['published_at' => null])->save();

        activity()
            ->performedOn($site)
            ->causedBy($actor)
            ->event('booking-website.unpublished')
            ->withProperties(['property_id' => $site->property_id, 'mode' => $site->mode->value])
            ->log('Booking website unpublished');

        return true;
    }
}

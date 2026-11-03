<?php

namespace App\Actions\BookingEngine;

use App\Models\Property;
use App\Models\User;

/**
 * Save the General tab: the web address slug and the Google Maps link. The request refuses a slug change once the
 * site is published, so only an admin can do that.
 */
class SaveBookingWebsiteGeneral
{
    public function __invoke(Property $property, User $actor, string $slug, ?string $googleMapsUrl): void
    {
        $property->forceFill(['slug' => $slug, 'google_maps_url' => $googleMapsUrl])->save();

        if (! $property->wasChanged(['slug', 'google_maps_url'])) {
            return;
        }

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('booking-website.general-saved')
            ->withProperties(['slug' => $property->slug, 'google_maps_url' => $property->google_maps_url])
            ->log('Booking website general settings saved');
    }
}

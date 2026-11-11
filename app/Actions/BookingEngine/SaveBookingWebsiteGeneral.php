<?php

namespace App\Actions\BookingEngine;

use App\Models\Property;
use App\Models\User;
use App\Services\BookingEngine\MapsPlace;

/**
 * Save the General tab: the web address slug, the Google Maps link and the about text. The request refuses a slug change once the
 * site is published, so only an admin can do that. The property's coordinates come from the place read from the link;
 * with no link they are cleared, and with a link but no place they stay as they are.
 */
class SaveBookingWebsiteGeneral
{
    public function __invoke(Property $property, User $actor, string $slug, ?string $googleMapsUrl, ?string $aboutText, ?MapsPlace $place = null): void
    {
        $property->forceFill(['slug' => $slug, 'google_maps_url' => $googleMapsUrl]);

        if ($googleMapsUrl === null) {
            $property->forceFill(['latitude' => null, 'longitude' => null]);
        } elseif ($place !== null) {
            $property->forceFill(['latitude' => $place->latitude, 'longitude' => $place->longitude]);
        }

        $property->save();

        $site = $property->bookingSite;
        $site?->update(['about_text' => $aboutText]);

        $aboutChanged = $site?->wasChanged('about_text') ?? false;

        if (! $aboutChanged && ! $property->wasChanged(['slug', 'google_maps_url', 'latitude', 'longitude'])) {
            return;
        }

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('booking-website.general-saved')
            ->withProperties(['slug' => $property->slug, 'google_maps_url' => $property->google_maps_url, 'latitude' => $property->latitude, 'longitude' => $property->longitude, 'about_text' => $aboutText])
            ->log('Booking website general settings saved');
    }
}

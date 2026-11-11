<?php

namespace App\Actions\BookingEngine;

use App\Models\PropertyBookingSite;
use App\Models\User;

/**
 * Empty one image slot. Returns false when the slot has no image.
 */
class RemoveSiteImage
{
    public function __invoke(PropertyBookingSite $site, User $actor, string $slot): bool
    {
        $images = $site->getMedia(PropertyBookingSite::IMAGES, ['slot' => $slot]);

        if ($images->isEmpty()) {
            return false;
        }

        $images->each->delete();

        activity()
            ->performedOn($site)
            ->causedBy($actor)
            ->event('booking-website.image-removed')
            ->withProperties(['property_id' => $site->property_id, 'slot' => $slot])
            ->log('Booking website image removed');

        return true;
    }
}

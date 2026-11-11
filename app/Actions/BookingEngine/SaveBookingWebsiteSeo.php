<?php

namespace App\Actions\BookingEngine;

use App\Models\PropertyBookingSite;
use App\Models\User;

/**
 * Save the SEO tab: the title and description Google shows.
 */
class SaveBookingWebsiteSeo
{
    public function __invoke(PropertyBookingSite $site, User $actor, ?string $title, ?string $description): void
    {
        $site->update(['seo_title' => $title, 'seo_description' => $description]);

        if (! $site->wasChanged(['seo_title', 'seo_description'])) {
            return;
        }

        activity()
            ->performedOn($site)
            ->causedBy($actor)
            ->event('booking-website.seo-saved')
            ->withProperties(['property_id' => $site->property_id, 'seo_title' => $title, 'seo_description' => $description])
            ->log('Booking website SEO saved');
    }
}

<?php

namespace App\Actions\BookingEngine;

use App\Models\PropertyBookingSite;
use App\Models\User;
use App\Services\BookingEngine\BookingSiteChecklist;

/**
 * Put the booking site online. Returns false, and writes nothing, when it is already published or the checklist is not done.
 */
class PublishBookingSite
{
    public function __construct(private BookingSiteChecklist $checklist) {}

    public function __invoke(PropertyBookingSite $site, User $actor): bool
    {
        if ($site->isPublished() || ! $this->checklist->isComplete($site)) {
            return false;
        }

        $site->forceFill(['published_at' => now()])->save();

        activity()
            ->performedOn($site)
            ->causedBy($actor)
            ->event('booking-website.published')
            ->withProperties(['property_id' => $site->property_id, 'mode' => $site->mode->value])
            ->log('Booking website published');

        return true;
    }
}

<?php

namespace App\Actions\Concierge;

use App\Models\Property;
use App\Models\User;

class RemoveConciergeHero
{
    /**
     * Delete the lobby hero photo so the lobby shows the stock photo again. Returns false when there is none.
     */
    public function __invoke(User $actor, Property $property): bool
    {
        if (! $property->hasMedia(Property::HERO)) {
            return false;
        }

        $property->clearMediaCollection(Property::HERO);

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('concierge.settings-updated')
            ->withProperties(['property_id' => $property->id, 'changed' => ['hero_photo']])
            ->log('Concierge hero photo removed');

        return true;
    }
}

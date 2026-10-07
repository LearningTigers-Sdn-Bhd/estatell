<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\User;

class RemovePropertyLogo
{
    /**
     * Delete the logo. Returns false when there is none.
     */
    public function __invoke(User $actor, Property $property): bool
    {
        if (! $property->hasMedia(Property::LOGO)) {
            return false;
        }

        $property->clearMediaCollection(Property::LOGO);

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('property.updated')
            ->withProperties(['property_id' => $property->id, 'changed' => ['logo']])
            ->log('Property logo removed');

        return true;
    }
}

<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\User;

class RemovePropertyPhoto
{
    /**
     * Delete one photo. Returns false when the property has no such photo.
     */
    public function __invoke(User $actor, Property $property, int $mediaId): bool
    {
        $photo = $property->getMedia(Property::PHOTOS)->firstWhere('id', $mediaId);

        if ($photo === null) {
            return false;
        }

        $photo->delete();

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('property.updated')
            ->withProperties(['property_id' => $property->id, 'changed' => ['photos'], 'removed' => 1])
            ->log('Property photo removed');

        return true;
    }
}

<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\User;

class UpdatePropertyProfile
{
    /**
     * Save the property identity: name, address, contact, registration number and TIN. The document prefix keeps
     * its value when the name changes.
     *
     * @param  array<string, mixed>  $attributes  Validated profile fields.
     */
    public function __invoke(User $actor, Property $property, array $attributes): void
    {
        $property->fill($attributes);
        $changes = $property->getDirty();

        if ($changes === []) {
            return;
        }

        $property->save();

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('property.updated')
            ->withProperties(['property_id' => $property->id, 'changed' => array_keys($changes)])
            ->log('Property profile updated');
    }
}

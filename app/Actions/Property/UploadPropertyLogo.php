<?php

namespace App\Actions\Property;

use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class UploadPropertyLogo
{
    /**
     * Store the logo, replacing the one before it.
     */
    public function __invoke(User $actor, Property $property, UploadedFile $file): void
    {
        $property->addMedia($file)->toMediaCollection(Property::LOGO);

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('property.updated')
            ->withProperties(['property_id' => $property->id, 'changed' => ['logo']])
            ->log('Property logo uploaded');
    }
}

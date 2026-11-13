<?php

namespace App\Actions\Concierge;

use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class UploadConciergeHero
{
    /**
     * Store the lobby hero photo, replacing the one before it.
     */
    public function __invoke(User $actor, Property $property, UploadedFile $file): void
    {
        $property->addMedia($file)
            ->usingFileName(Str::uuid().'.'.$file->guessExtension())
            ->toMediaCollection(Property::HERO);

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('concierge.settings-updated')
            ->withProperties(['property_id' => $property->id, 'changed' => ['hero_photo']])
            ->log('Concierge hero photo uploaded');
    }
}

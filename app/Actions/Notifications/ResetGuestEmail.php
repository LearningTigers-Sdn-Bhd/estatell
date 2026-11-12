<?php

namespace App\Actions\Notifications;

use App\Enums\GuestEmail;
use App\Models\Property;
use App\Models\PropertyGuestEmail;
use App\Models\User;

class ResetGuestEmail
{
    /**
     * Go back to the default subject and message. Returns false when the property already uses the default.
     */
    public function __invoke(User $actor, Property $property, GuestEmail $email): bool
    {
        $saved = PropertyGuestEmail::query()->where('property_id', $property->id)->where('email', $email)->first();

        if ($saved === null) {
            return false;
        }

        $saved->delete();

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('guest_email.reset')
            ->withProperties(['property_id' => $property->id, 'email' => $email->value, 'old' => ['subject' => $saved->subject, 'body' => $saved->body]])
            ->log('Guest email reset to default');

        return true;
    }
}

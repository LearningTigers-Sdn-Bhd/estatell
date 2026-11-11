<?php

namespace App\Actions\Notifications;

use App\Enums\GuestEmail;
use App\Models\Property;
use App\Models\PropertyGuestEmail;
use App\Models\User;

class SaveGuestEmail
{
    /**
     * Save the property's own subject and message for a guest email. Logs `guest_email.updated` when something changed.
     */
    public function __invoke(User $actor, Property $property, GuestEmail $email, string $subject, string $body): void
    {
        $saved = PropertyGuestEmail::query()->firstOrNew(['property_id' => $property->id, 'email' => $email]);
        $old = $saved->exists ? ['subject' => $saved->subject, 'body' => $saved->body] : null;
        $saved->fill(['subject' => $subject, 'body' => $body]);

        if ($saved->exists && ! $saved->isDirty()) {
            return;
        }

        $saved->save();

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('guest_email.updated')
            ->withProperties(['property_id' => $property->id, 'email' => $email->value, 'old' => $old, 'attributes' => ['subject' => $subject, 'body' => $body]])
            ->log('Guest email updated');
    }
}

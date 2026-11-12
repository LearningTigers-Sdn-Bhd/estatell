<?php

namespace App\Actions\Concierge;

use App\Models\Property;
use App\Models\User;

class SaveGuestContacts
{
    public const array FIELDS = [
        'front_desk_open_24h', 'front_desk_opens_at', 'front_desk_closes_at', 'front_desk_whatsapp', 'after_hours_message',
        'duty_manager_phone', 'emergency_phone', 'emergency_services_number', 'emergency_instructions',
        'front_desk_phone', 'front_desk_email', 'front_desk_extension',
    ];

    /**
     * Save the front desk hours, the ways to reach it out of hours and who to call in an emergency. A blank value is
     * stored as null. Nothing is written or logged when nothing changed.
     *
     * @param  array<string, mixed>  $data  Validated `FIELDS`.
     */
    public function __invoke(User $actor, Property $property, array $data): void
    {
        $clean = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);

        $settings = $property->settings()->firstOrNew();
        $settings->fill(array_intersect_key($clean, array_flip(self::FIELDS)));
        $settings->front_desk_open_24h = (bool) ($clean['front_desk_open_24h'] ?? true);

        $changed = array_keys($settings->getDirty());

        if ($changed === []) {
            return;
        }

        $settings->save();

        activity()->performedOn($property)->causedBy($actor)->event('concierge.contacts-updated')
            ->withProperties(['property_id' => $property->id, 'changed' => $changed])->log('Guest contacts updated');
    }
}

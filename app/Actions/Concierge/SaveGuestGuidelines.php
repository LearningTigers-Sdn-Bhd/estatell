<?php

namespace App\Actions\Concierge;

use App\Models\Property;
use App\Models\User;

class SaveGuestGuidelines
{
    public const array FIELDS = ['arrival_instructions', 'departure_instructions'];

    /**
     * Save the steps guests follow on arrival and when they leave. A blank value is stored as null.
     * Nothing is written or logged when nothing changed.
     *
     * @param  array<string, string|null>  $data  Validated `FIELDS`.
     */
    public function __invoke(User $actor, Property $property, array $data): void
    {
        $settings = $property->settings()->firstOrNew();

        foreach (self::FIELDS as $field) {
            $value = isset($data[$field]) ? trim($data[$field]) : null;
            $settings->{$field} = $value === '' ? null : $value;
        }

        $changed = array_keys($settings->getDirty());

        if ($changed === []) {
            return;
        }

        $settings->save();

        activity()->performedOn($property)->causedBy($actor)->event('concierge.guest-guidelines-updated')
            ->withProperties(['property_id' => $property->id, 'changed' => $changed])->log('Guest guidelines updated');
    }
}

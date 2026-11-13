<?php

namespace App\Actions\Concierge;

use App\Models\Amenity;
use App\Models\Property;
use App\Models\User;

class SaveAmenityDetail
{
    public const array FIELDS = ['location', 'opening_hours', 'fee_information', 'reservation_instructions', 'guest_notes'];

    /**
     * Save what a guest needs to know about one amenity the property offers. A blank value is stored as null. Returns
     * false, writing nothing, when the property does not offer the amenity. Nothing is logged when nothing changed.
     *
     * @param  array<string, mixed>  $data  Validated `FIELDS` and `reservation_required`.
     */
    public function __invoke(User $actor, Property $property, Amenity $amenity, array $data): bool
    {
        $current = $property->amenities()->whereKey($amenity->id)->first()?->pivot;

        if ($current === null) {
            return false;
        }

        $new = [];

        foreach (self::FIELDS as $field) {
            $value = isset($data[$field]) ? trim((string) $data[$field]) : '';
            $new[$field] = $value === '' ? null : $value;
        }

        $new['reservation_required'] = (bool) ($data['reservation_required'] ?? false);

        $changed = array_keys(array_filter($new, fn (mixed $value, string $field): bool => $current->{$field} !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed === []) {
            return true;
        }

        $property->amenities()->updateExistingPivot($amenity->id, $new);

        activity()->performedOn($property)->causedBy($actor)->event('concierge.amenity-details-updated')
            ->withProperties(['property_id' => $property->id, 'amenity_id' => $amenity->id, 'changed' => $changed])->log('Amenity details updated');

        return true;
    }
}

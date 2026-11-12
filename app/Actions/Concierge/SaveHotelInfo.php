<?php

namespace App\Actions\Concierge;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveHotelInfo
{
    public const array SETTING_FIELDS = ['about', 'arrival_instructions', 'departure_instructions'];

    public const array TRANSPORT_FIELDS = [
        'directions', 'airport_distance_km', 'airport_travel_minutes', 'station_distance_km', 'station_travel_minutes',
        'city_centre_distance_km', 'city_centre_travel_minutes', 'nearest_transit_stop', 'airport_transfer_offered',
        'airport_transfer_price', 'airport_transfer_lead_hours', 'pickup_point', 'shuttle_schedule', 'parking_notes', 'transport_notes',
    ];

    /**
     * Save what the property tells its guests about itself: the description, the arrival and departure instructions and
     * how to get there. A blank value is stored as null. Nothing is written or logged when nothing changed.
     *
     * @param  array<string, mixed>  $data  Validated fields of `SETTING_FIELDS` and `TRANSPORT_FIELDS`.
     */
    public function __invoke(User $actor, Property $property, array $data): void
    {
        $clean = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);

        DB::transaction(function () use ($actor, $property, $clean): void {
            $settings = $property->settings()->firstOrNew();
            $settings->fill(array_intersect_key($clean, array_flip(self::SETTING_FIELDS)));

            $transport = $property->transportDetail()->firstOrNew();
            $transport->fill(array_intersect_key($clean, array_flip(self::TRANSPORT_FIELDS)));
            $transport->airport_transfer_offered = (bool) ($clean['airport_transfer_offered'] ?? false);

            $changed = [...array_keys($settings->getDirty()), ...array_keys($transport->getDirty())];

            if ($changed === []) {
                return;
            }

            $settings->save();
            $transport->save();

            activity()->performedOn($property)->causedBy($actor)->event('concierge.hotel-info-updated')
                ->withProperties(['property_id' => $property->id, 'changed' => $changed])->log('Hotel info updated');
        });
    }
}

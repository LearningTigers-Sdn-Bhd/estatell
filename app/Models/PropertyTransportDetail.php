<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PropertyTransportDetailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a guest gets to a property: directions, distances to the airport, station and city centre, and the airport transfer.
 *
 * @property int $id
 * @property int $property_id
 * @property string|null $directions
 * @property int|null $airport_distance_km
 * @property int|null $airport_travel_minutes
 * @property int|null $station_distance_km
 * @property int|null $station_travel_minutes
 * @property int|null $city_centre_distance_km
 * @property int|null $city_centre_travel_minutes
 * @property string|null $nearest_transit_stop
 * @property bool $airport_transfer_offered
 * @property string|null $airport_transfer_price
 * @property int|null $airport_transfer_lead_hours
 * @property string|null $pickup_point
 * @property string|null $shuttle_schedule
 * @property string|null $parking_notes
 * @property string|null $transport_notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'property_id', 'directions', 'airport_distance_km', 'airport_travel_minutes', 'station_distance_km', 'station_travel_minutes',
    'city_centre_distance_km', 'city_centre_travel_minutes', 'nearest_transit_stop', 'airport_transfer_offered',
    'airport_transfer_price', 'airport_transfer_lead_hours', 'pickup_point', 'shuttle_schedule', 'parking_notes', 'transport_notes',
])]
class PropertyTransportDetail extends Model
{
    /** @use HasFactory<PropertyTransportDetailFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'airport_transfer_offered' => 'boolean',
            'airport_transfer_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}

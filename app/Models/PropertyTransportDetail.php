<?php

namespace App\Models;

use App\Enums\ParkingAvailability;
use App\Enums\ParkingPriceUnit;
use App\Enums\ParkingType;
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
 * @property ParkingAvailability|null $parking_availability
 * @property ParkingType|null $parking_type
 * @property string|null $parking_price
 * @property ParkingPriceUnit|null $parking_price_unit
 * @property int|null $parking_spaces
 * @property string|null $parking_height_limit_m
 * @property bool $parking_ev_charging
 * @property bool $parking_booking_required
 * @property string|null $transport_notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'property_id', 'directions', 'airport_distance_km', 'airport_travel_minutes', 'station_distance_km', 'station_travel_minutes',
    'city_centre_distance_km', 'city_centre_travel_minutes', 'nearest_transit_stop', 'airport_transfer_offered',
    'airport_transfer_price', 'airport_transfer_lead_hours', 'pickup_point', 'shuttle_schedule', 'parking_notes', 'transport_notes',
    'parking_availability', 'parking_type', 'parking_price', 'parking_price_unit', 'parking_spaces', 'parking_height_limit_m',
    'parking_ev_charging', 'parking_booking_required',
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
            'parking_availability' => ParkingAvailability::class,
            'parking_type' => ParkingType::class,
            'parking_price' => 'decimal:2',
            'parking_price_unit' => ParkingPriceUnit::class,
            'parking_height_limit_m' => 'decimal:2',
            'parking_ev_charging' => 'boolean',
            'parking_booking_required' => 'boolean',
            'airport_transfer_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}

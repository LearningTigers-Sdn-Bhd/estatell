<?php

namespace App\Data\Concierge;

use App\Models\Property;
use Spatie\LaravelData\Data;

/**
 * What staff edit on the Hotel Info tab: the raw values, as stored.
 */
class ConciergeHotelInfoSettingsData extends Data
{
    public function __construct(
        public ?string $about,
        public ?string $arrivalInstructions,
        public ?string $departureInstructions,
        public ?string $directions,
        public ?int $airportDistanceKm,
        public ?int $airportTravelMinutes,
        public ?int $stationDistanceKm,
        public ?int $stationTravelMinutes,
        public ?int $cityCentreDistanceKm,
        public ?int $cityCentreTravelMinutes,
        public ?string $nearestTransitStop,
        public bool $airportTransferOffered,
        public ?string $airportTransferPrice,
        public ?int $airportTransferLeadHours,
        public ?string $pickupPoint,
        public ?string $shuttleSchedule,
        public ?string $parkingNotes,
        public ?string $parkingAvailability,
        public ?string $parkingType,
        public ?string $parkingPrice,
        public ?string $parkingPriceUnit,
        public ?int $parkingSpaces,
        public ?string $parkingHeightLimitM,
        public bool $parkingEvCharging,
        public bool $parkingBookingRequired,
        public ?string $transportNotes,
    ) {}

    public static function fromProperty(Property $property): self
    {
        $settings = $property->settings;
        $transport = $property->transportDetail;

        return new self(
            $settings?->about,
            $settings?->arrival_instructions,
            $settings?->departure_instructions,
            $transport?->directions,
            $transport?->airport_distance_km,
            $transport?->airport_travel_minutes,
            $transport?->station_distance_km,
            $transport?->station_travel_minutes,
            $transport?->city_centre_distance_km,
            $transport?->city_centre_travel_minutes,
            $transport?->nearest_transit_stop,
            (bool) $transport?->airport_transfer_offered,
            $transport?->airport_transfer_price,
            $transport?->airport_transfer_lead_hours,
            $transport?->pickup_point,
            $transport?->shuttle_schedule,
            $transport?->parking_notes,
            $transport?->parking_availability?->value,
            $transport?->parking_type?->value,
            $transport?->parking_price,
            $transport?->parking_price_unit?->value,
            $transport?->parking_spaces,
            $transport?->parking_height_limit_m,
            (bool) $transport?->parking_ev_charging,
            (bool) $transport?->parking_booking_required,
            $transport?->transport_notes,
        );
    }
}

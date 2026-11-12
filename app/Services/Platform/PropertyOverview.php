<?php

namespace App\Services\Platform;

use App\Data\Platform\AdminPropertyBookingWebsiteData;
use App\Data\Platform\AdminPropertyOverviewData;
use App\Data\Platform\PropertyFeatureData;
use App\Enums\PlatformFeature;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;

/**
 * Reads the admin Overview figures for one property: how far it is set up and whether it is in use. Every count is
 * scoped to the property. Rooms, room types and rate plans count only the ones not archived.
 */
class PropertyOverview
{
    public function forProperty(Property $property): AdminPropertyOverviewData
    {
        $features = PropertyFeatureData::forProperty($property);
        $bookingEngineOn = collect($features)->contains(fn (PropertyFeatureData $feature): bool => $feature->feature === PlatformFeature::BookingEngine && $feature->active);

        return new AdminPropertyOverviewData(
            rooms: Room::query()->where('property_id', $property->id)->active()->count(),
            roomTypes: RoomType::query()->where('property_id', $property->id)->active()->count(),
            ratePlans: RatePlan::query()->where('property_id', $property->id)->whereNull('archived_at')->count(),
            bookingsLast30Days: Reservation::query()->where('property_id', $property->id)->where('created_at', '>=', now()->subDays(30))->count(),
            staffVerified: $property->users()->whereNotNull('email_verified_at')->count(),
            featuresOn: collect($features)->filter(fn (PropertyFeatureData $feature): bool => $feature->active)->count(),
            featuresTotal: count($features),
            bookingWebsite: $bookingEngineOn ? AdminPropertyBookingWebsiteData::forProperty($property, $property->bookingSite) : null,
        );
    }
}

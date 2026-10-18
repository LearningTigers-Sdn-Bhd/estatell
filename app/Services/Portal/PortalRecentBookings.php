<?php

namespace App\Services\Portal;

use App\Data\Portal\PortalRecentBookingData;
use App\Enums\PropertyStatus;
use App\Models\CompanyUser;
use App\Models\Reservation;
use App\Models\User;

/**
 * Reads the newest bookings on the portal dashboard: reservations of the user's companies through their Active links
 * at Live properties, newest first. Each reservation must belong to a linked company and that company's property.
 * Three queries whatever the number of properties.
 */
class PortalRecentBookings
{
    public const int LIMIT = 5;

    /**
     * @return list<PortalRecentBookingData>
     */
    public function for(User $user): array
    {
        $links = CompanyUser::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereHas('property', fn ($query) => $query->where('status', PropertyStatus::Live->value))
            ->get(['company_id', 'property_id']);

        if ($links->isEmpty()) {
            return [];
        }

        return Reservation::query()
            ->where(function ($query) use ($links): void {
                foreach ($links as $link) {
                    $query->orWhere(fn ($pair) => $pair
                        ->where('reservations.company_id', $link->company_id)
                        ->where('reservations.property_id', $link->property_id));
                }
            })
            ->with('property:id,name,code', 'booker:id,full_name')
            ->withMin('units as first_arrival', 'arrival_date')
            ->withMax('units as last_departure', 'departure_date')
            ->orderByDesc('reservations.id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Reservation $reservation): PortalRecentBookingData => PortalRecentBookingData::fromReservation($reservation))
            ->values()
            ->all();
    }
}

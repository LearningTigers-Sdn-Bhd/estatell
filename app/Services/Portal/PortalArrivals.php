<?php

namespace App\Services\Portal;

use App\Data\Portal\PortalArrivalData;
use App\Enums\CompanyUserStatus;
use App\Enums\PropertyStatus;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reads the upcoming arrivals on the portal dashboard: Confirmed reservations of the user's companies, through their
 * Active links at Live properties, arriving from each property's current business date up to `ARRIVAL_DAYS` days
 * later. One query for all properties. Each reservation must belong to the linked company and to that company's
 * property, so another company's or property's booking never shows.
 */
class PortalArrivals
{
    /**
     * @param  int|null  $propertyId  Only this property, for its dashboard.
     * @return list<PortalArrivalData>
     */
    public function for(User $user, ?int $propertyId = null): array
    {
        return array_values(DB::table('company_users')
            ->join('companies', 'companies.id', '=', 'company_users.company_id')
            ->join('properties', 'properties.id', '=', 'companies.property_id')
            ->join('reservations', function ($join): void {
                $join->on('reservations.company_id', '=', 'companies.id')
                    ->on('reservations.property_id', '=', 'companies.property_id');
            })
            ->join('guests', 'guests.id', '=', 'reservations.booker_guest_id')
            ->join('reservation_units', 'reservation_units.reservation_id', '=', 'reservations.id')
            ->join('business_dates', function ($join): void {
                $join->on('business_dates.property_id', '=', 'reservations.property_id')
                    ->where('business_dates.status', '<>', 'closed');
            })
            ->where('company_users.user_id', $user->id)
            ->when($propertyId !== null, fn ($query) => $query->where('company_users.property_id', $propertyId))
            ->where('company_users.status', CompanyUserStatus::Active->value)
            ->where('properties.status', PropertyStatus::Live->value)
            ->where('reservations.status', ReservationStatus::Confirmed->value)
            ->where('reservation_units.status', UnitStatus::Confirmed->value)
            ->whereRaw('reservation_units.arrival_date >= business_dates.date')
            ->whereRaw('reservation_units.arrival_date <= business_dates.date + ?::int', [PortalProperties::ARRIVAL_DAYS])
            ->groupBy('reservations.id', 'reservations.reference', 'reservations.external_reference', 'reservations.group_name', 'guests.full_name', 'properties.name', 'properties.code')
            ->selectRaw('reservations.id, reservations.reference, reservations.external_reference, COALESCE(reservations.group_name, guests.full_name) AS title, properties.name AS property_name, properties.code AS property_code, MIN(reservation_units.arrival_date) AS arrival_date')
            ->orderByRaw('MIN(reservation_units.arrival_date), reservations.id')
            ->get()
            ->map(fn (object $row): PortalArrivalData => new PortalArrivalData(
                reservationId: (int) $row->id,
                propertyName: $row->property_name,
                propertyCode: $row->property_code,
                reference: $row->reference,
                externalReference: $row->external_reference,
                title: $row->title,
                arrivalDate: (string) $row->arrival_date,
            ))
            ->all());
    }
}

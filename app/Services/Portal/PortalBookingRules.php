<?php

namespace App\Services\Portal;

use App\Enums\PortalCancelMode;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Services\NightAudit\CurrentBusinessDate;
use App\Services\Property\PropertyToday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * When a company user can edit guests, cancel, or only ask to cancel. All of it is read from the units and the
 * property's business date. `reservation->units` must be loaded. See docs/23-company-portal/01-company-portal.md#bookings.
 */
class PortalBookingRules
{
    public function __construct(private CurrentBusinessDate $currentBusinessDate, private PropertyToday $propertyToday) {}

    public function businessDate(Property $property): CarbonImmutable
    {
        return $this->currentBusinessDate->date($property) ?? $this->propertyToday->date($property);
    }

    /**
     * The first arrival of the units that can still be cancelled or edited (Confirmed), or null when there is none.
     */
    public function arrival(Reservation $reservation): ?CarbonImmutable
    {
        $arrival = $this->confirmed($reservation)->min(fn (ReservationUnit $unit): string => $unit->arrival_date->toDateString());

        return $arrival === null ? null : CarbonImmutable::parse($arrival);
    }

    /**
     * The last business date on which the company can cancel free, or null when it has no cut-off.
     */
    public function freeUntil(Reservation $reservation, Company $company): ?CarbonImmutable
    {
        $arrival = $this->arrival($reservation);

        if ($arrival === null || $company->portal_cancel_cutoff_days === null) {
            return null;
        }

        return $arrival->subDays($company->portal_cancel_cutoff_days);
    }

    public function cancelMode(Reservation $reservation, Company $company, CarbonImmutable $businessDate): PortalCancelMode
    {
        if (! $this->isOpen($reservation)) {
            return PortalCancelMode::Closed;
        }

        $freeUntil = $this->freeUntil($reservation, $company);

        return $freeUntil !== null && $businessDate->lte($freeUntil) ? PortalCancelMode::Free : PortalCancelMode::Request;
    }

    /**
     * Guests can change until check-in and not after the arrival date.
     */
    public function canEditGuests(Reservation $reservation, CarbonImmutable $businessDate): bool
    {
        $arrival = $this->arrival($reservation);

        return $this->isOpen($reservation) && $arrival !== null && $businessDate->lte($arrival);
    }

    /**
     * Whether the booking is still before check-in: Confirmed, with a Confirmed unit and no unit checked in or out.
     */
    private function isOpen(Reservation $reservation): bool
    {
        return $reservation->status === ReservationStatus::Confirmed
            && $this->confirmed($reservation)->isNotEmpty()
            && $reservation->units->doesntContain(fn (ReservationUnit $unit): bool => in_array($unit->status, [UnitStatus::CheckedIn, UnitStatus::CheckedOut], true));
    }

    /**
     * @return Collection<int, ReservationUnit>
     */
    private function confirmed(Reservation $reservation): Collection
    {
        return $reservation->units->filter(fn (ReservationUnit $unit): bool => $unit->status === UnitStatus::Confirmed);
    }
}

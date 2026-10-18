<?php

namespace App\Actions\Portal;

use App\Actions\FrontDesk\CancelUnits;
use App\Enums\CompanyStatus;
use App\Enums\PortalCancelMode;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\User;
use App\Notifications\PortalBookingCancelled;
use App\Services\Portal\PortalBookingRules;

class CancelPortalReservation
{
    public function __construct(
        private CancelUnits $cancelUnits,
        private PortalBookingRules $rules,
        private NotifyReservationStaff $notifyReservationStaff,
    ) {}

    /**
     * Cancel the whole reservation for the company, on or before its free cancellation cut-off (the business date is
     * on or before arrival minus `portal_cancel_cutoff_days`). `CancelUnits` cancels every Confirmed unit with no
     * charge and the reason "Cancelled by the company in the portal", all or nothing. After the cut-off, with no
     * cut-off set, after check-in or for a Suspended company it is refused and nothing is written. The caller has
     * already found the reservation through the link's company and property. `PortalBookingCancelled` goes to the
     * reservation staff after the save. See docs/23-company-portal/01-company-portal.md#bookings.
     */
    public function __invoke(User $user, CompanyUser $link, Reservation $reservation): PortalBookingResult
    {
        $company = Company::query()->where('property_id', $link->property_id)->findOrFail($link->company_id);

        if ($company->status === CompanyStatus::Suspended) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_COMPANY_SUSPENDED);
        }

        $reservation = Reservation::query()->with('units')->where('property_id', $link->property_id)->where('company_id', $company->id)->findOrFail($reservation->id);

        if ($this->rules->cancelMode($reservation, $company, $this->rules->businessDate($link->property)) !== PortalCancelMode::Free) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_PAST_CUT_OFF);
        }

        $unitIds = array_values($reservation->units
            ->filter(fn (ReservationUnit $unit): bool => $unit->status === UnitStatus::Confirmed)
            ->map(fn (ReservationUnit $unit): int => $unit->id)
            ->all());

        $result = ($this->cancelUnits)($user, $reservation, $unitIds, doNotCharge: true, reason: __('Cancelled by the company in the portal'));

        if ($result->isRefused()) {
            return PortalBookingResult::refused((string) $result->refusal);
        }

        ($this->notifyReservationStaff)($reservation, PortalBookingCancelled::class);

        return PortalBookingResult::done($reservation);
    }
}

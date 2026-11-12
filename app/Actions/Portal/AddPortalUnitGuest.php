<?php

namespace App\Actions\Portal;

use App\Actions\Reservations\AddGuestToUnit;
use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\CompanyStatus;
use App\Enums\IdentityDocumentType;
use App\Enums\UnitStatus;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Portal\PortalBookingRules;

class AddPortalUnitGuest
{
    public function __construct(
        private AddGuestToUnit $addGuestToUnit,
        private PortalBookingRules $rules,
    ) {}

    /**
     * Add a sharer to one room or bed of a company booking: always a new guest (the portal has no guest search), made
     * from the typed profile and ID, then put on the unit by `AddGuestToUnit`. The same window as guest edits applies:
     * until check-in and not after the arrival date, and not while the company is suspended. The unit must be
     * Confirmed; `AttachGuest` refuses a full unit (`unit_full`, `bed_unit_full`). A refusal writes nothing. The
     * company user is the causer of the guest and attach log entries. See
     * docs/23-company-portal/01-company-portal.md#bookings.
     *
     * @param  array<string, mixed>  $profile  Validated profile fields, the phone number in E.164.
     */
    public function __invoke(User $user, CompanyUser $link, Reservation $reservation, int $unitId, array $profile, ?IdentityDocumentType $idType, ?string $idNumber): PortalBookingResult
    {
        $company = Company::query()->where('property_id', $link->property_id)->findOrFail($link->company_id);

        if ($company->status === CompanyStatus::Suspended) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_COMPANY_SUSPENDED);
        }

        $reservation = Reservation::query()->with('units')->where('property_id', $link->property_id)->where('company_id', $company->id)->findOrFail($reservation->id);
        $unit = $reservation->units->firstWhere('id', $unitId);

        if ($unit === null) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_GUEST_NOT_FOUND);
        }

        if ($unit->status !== UnitStatus::Confirmed || ! $this->rules->canEditGuests($reservation, $this->rules->businessDate($link->property))) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_NOT_EDITABLE);
        }

        $identity = $idType !== null && filled($idNumber) ? new IdentityDocumentInputData($idType, $idNumber, null, null) : null;
        $result = ($this->addGuestToUnit)($user, $link->property, $unit, null, $profile, identity: $identity);

        return $result->isRefused()
            ? PortalBookingResult::refused((string) $result->refusal)
            : PortalBookingResult::done($reservation);
    }
}

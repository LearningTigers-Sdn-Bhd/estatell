<?php

namespace App\Actions\Portal;

use App\Actions\Guests\AddIdentityDocument;
use App\Actions\Guests\UpdateGuest;
use App\Actions\Guests\UpdateIdentityDocument;
use App\Data\Guests\IdentityDocumentInputData;
use App\Enums\CompanyStatus;
use App\Enums\IdentityDocumentType;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\UnitGuest;
use App\Models\User;
use App\Services\Portal\PortalBookingRules;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdatePortalReservationGuests
{
    public function __construct(
        private UpdateGuest $updateGuest,
        private AddIdentityDocument $addIdentityDocument,
        private UpdateIdentityDocument $updateIdentityDocument,
        private PortalBookingRules $rules,
    ) {}

    /**
     * Change the name, phone and ID of the guests of a booking, until check-in and not after the arrival date (the
     * property's business date is on or before the arrival). Only the booking's own guests can change; a guest id
     * that is not one of them refuses the whole save. Dates, rooms, plans and prices cannot change here. A new ID
     * number replaces the number on the guest's first ID document, or adds a document when the guest has none. One
     * `reservation.guests_updated` entry on the reservation names the guests, with the company user as causer. All
     * or nothing. See docs/23-company-portal/01-company-portal.md#bookings.
     *
     * @param  list<array{guest_id: int, full_name: string, phone: ?string, id_type: ?string, id_number: ?string}>  $guests
     */
    public function __invoke(User $user, CompanyUser $link, Reservation $reservation, array $guests): PortalBookingResult
    {
        $company = Company::query()->where('property_id', $link->property_id)->findOrFail($link->company_id);

        if ($company->status === CompanyStatus::Suspended) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_COMPANY_SUSPENDED);
        }

        $reservation = Reservation::query()->with('units')->where('property_id', $link->property_id)->where('company_id', $company->id)->findOrFail($reservation->id);

        if (! $this->rules->canEditGuests($reservation, $this->rules->businessDate($link->property))) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_NOT_EDITABLE);
        }

        $own = UnitGuest::query()->whereIn('reservation_unit_id', $reservation->units->modelKeys())->pluck('guest_id')->push($reservation->booker_guest_id)->unique();

        if (array_diff(array_column($guests, 'guest_id'), $own->all()) !== []) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_GUEST_NOT_FOUND);
        }

        try {
            DB::transaction(function () use ($user, $reservation, $guests): void {
                $this->save($user, $reservation, $guests);
            });
        } catch (DomainException) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_GUEST_NOT_FOUND);
        }

        return PortalBookingResult::done($reservation);
    }

    /**
     * @param  list<array{guest_id: int, full_name: string, phone: ?string, id_type: ?string, id_number: ?string}>  $guests
     *
     * @throws DomainException when a guest profile is closed (merged or anonymized), so the transaction rolls back
     */
    private function save(User $user, Reservation $reservation, array $guests): void
    {
        foreach ($guests as $row) {
            $guest = Guest::query()->where('property_id', $reservation->property_id)->findOrFail($row['guest_id']);

            if (! ($this->updateGuest)($user, $guest, ['full_name' => $row['full_name'], 'phone' => $row['phone']]) || ! $this->saveId($user, $guest, $row)) {
                throw new DomainException('The guest profile is closed.');
            }
        }

        activity()
            ->performedOn($reservation)
            ->causedBy($user)
            ->event('reservation.guests_updated')
            ->withProperties(['property_id' => $reservation->property_id, 'guest_ids' => array_column($guests, 'guest_id')])
            ->log('Guest details updated by the company');
    }

    /**
     * @param  array{id_type: ?string, id_number: ?string}  $row
     */
    private function saveId(User $user, Guest $guest, array $row): bool
    {
        if (blank($row['id_type'] ?? null) || blank($row['id_number'] ?? null)) {
            return true;
        }

        $type = IdentityDocumentType::from($row['id_type']);
        $document = $guest->documents()->oldest('id')->first();

        if ($document === null) {
            return ($this->addIdentityDocument)($user, $guest, new IdentityDocumentInputData($type, $row['id_number'], null, null)) !== null;
        }

        return ($this->updateIdentityDocument)($user, $document, new IdentityDocumentInputData($type, $row['id_number'], $document->issuing_country_code, $document->expires_on?->toDateString()));
    }
}

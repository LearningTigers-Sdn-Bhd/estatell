<?php

namespace App\Actions\Portal;

use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Reservations\ReservationResult;
use App\Data\Portal\NewPortalBookingData;
use App\Data\Portal\NewPortalLineData;
use App\Data\Reservations\NewBookingData;
use App\Data\Reservations\NewBookingGuestData;
use App\Data\Reservations\NewUnitData;
use App\Enums\BillingMode;
use App\Enums\BookingSourceType;
use App\Enums\CompanyStatus;
use App\Enums\SellUnit;
use App\Models\BookingSource;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\RoomType;
use App\Models\User;
use App\Notifications\PortalBookingCreated;
use App\Services\Portal\PortalBookingQuote;
use App\Services\Portal\PortalRatePlans;
use App\Services\Rates\RestrictionCheck;
use App\Services\Reservations\AutoAddOns;
use Brick\Math\BigDecimal;

class CreatePortalReservation
{
    public function __construct(
        private CreateBooking $createBooking,
        private PortalRatePlans $portalRatePlans,
        private PortalBookingQuote $portalBookingQuote,
        private AutoAddOns $autoAddOns,
        private RestrictionCheck $restrictionCheck,
        private NotifyReservationStaff $notifyReservationStaff,
    ) {}

    /**
     * Book what a company user sent, for the company of their Active link. Nothing from the client is trusted: the
     * company comes from the link, each plan must be visible to the company, the guests and the total are checked
     * again, and the auto-add items are added here. The booking uses the Company / Travel agent source, bills the
     * company as the Master folio payer (MasterPays), has no override of any kind and is Confirmed at once. Every
     * guest is a new guest record. The first line's guest is the booker. The shared `CreateBooking` saves it all or
     * nothing, so a refusal writes nothing. `PortalBookingCreated` goes to the reservation staff after the save.
     * See docs/23-company-portal/01-company-portal.md#booking.
     */
    public function __invoke(User $user, CompanyUser $link, NewPortalBookingData $data): PortalBookingResult
    {
        $company = Company::query()->where('property_id', $link->property_id)->findOrFail($link->company_id);

        if ($company->status === CompanyStatus::Suspended) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_COMPANY_SUSPENDED);
        }

        if ($data->lines === [] || count($data->lines) > 20) {
            return PortalBookingResult::refused(ReservationResult::REFUSAL_UNITS_REQUIRED);
        }

        $property = $link->property;
        $roomTypes = RoomType::query()->where('property_id', $property->id)->active()
            ->whereIn('id', array_map(fn (NewPortalLineData $line): int => $line->roomTypeId, $data->lines))
            ->get()->keyBy('id');

        $refusal = $this->checkLines($company, $data, $roomTypes->all());

        if ($refusal !== null) {
            return $refusal;
        }

        $source = BookingSource::query()->where('property_id', $property->id)->where('type', BookingSourceType::Company->value)->where('is_system', true)->first();

        if ($source === null) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_BOOKING_SOURCE_MISSING);
        }

        $result = ($this->createBooking)($user, $property, $this->bookingData($company, $source, $data, $roomTypes->all()));

        if ($result->isRefused()) {
            return $this->refusal($result, $data);
        }

        ($this->notifyReservationStaff)($result->reservation, PortalBookingCreated::class);

        return PortalBookingResult::done($result->reservation);
    }

    /**
     * The checks the shared Actions do not make, in line order: a guest name, a visible plan, the room type's limits.
     * Then the total the page showed against the total now. A line whose quote is refused is left to `CreateBooking`,
     * which names the reason.
     *
     * @param  array<int, RoomType>  $roomTypes
     */
    private function checkLines(Company $company, NewPortalBookingData $data, array $roomTypes): ?PortalBookingResult
    {
        $total = BigDecimal::zero();
        $complete = true;

        foreach ($data->lines as $index => $line) {
            if (trim((string) $line->guestName) === '') {
                return PortalBookingResult::refused(BookingResult::REFUSAL_GUEST_NAME_REQUIRED, $index);
            }

            $roomType = $roomTypes[$line->roomTypeId] ?? null;

            if ($line->ratePlanId === null || ! $this->portalRatePlans->isVisible($company, $line->ratePlanId)) {
                return PortalBookingResult::refused(PortalBookingResult::REFUSAL_PLAN_NOT_VISIBLE, $index);
            }

            if ($roomType === null) {
                return PortalBookingResult::refused(ReservationResult::REFUSAL_ROOM_TYPE_NOT_FOUND, $index);
            }

            $occupancy = $this->portalBookingQuote->occupancyRefusal($roomType, $line);

            if ($occupancy !== null) {
                return PortalBookingResult::refused($occupancy, $index);
            }

            $lineTotal = $this->portalBookingQuote->lineTotal($company->property, $roomType, $line, $data->arrivalDate, $data->departureDate);

            if ($lineTotal === null) {
                $complete = false;
            } else {
                $total = $total->plus($lineTotal);
            }
        }

        if ($complete && ! $total->isEqualTo($data->expectedTotal)) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_PRICE_CHANGED);
        }

        return null;
    }

    /**
     * @param  array<int, RoomType>  $roomTypes
     */
    private function bookingData(Company $company, BookingSource $source, NewPortalBookingData $data, array $roomTypes): NewBookingData
    {
        $addOns = $this->autoAddOns->forUnit($company->property);
        $units = [];
        $guests = [];
        $unitIndex = 0;

        foreach ($data->lines as $line) {
            $isBed = $roomTypes[$line->roomTypeId]->sell_unit === SellUnit::Bed;
            $beds = $isBed ? max(1, $line->beds) : 1;

            $units[] = new NewUnitData(
                roomTypeId: $line->roomTypeId,
                ratePlanId: (int) $line->ratePlanId,
                arrivalDate: $data->arrivalDate,
                departureDate: $data->departureDate,
                adults: $isBed ? 1 : $line->adults,
                childAges: $isBed ? [] : $line->childAges,
                beds: $beds,
                addOns: $addOns,
            );

            $guests[] = new NewBookingGuestData(
                profile: array_filter(['full_name' => trim((string) $line->guestName), 'phone' => $line->guestPhone], fn (?string $value): bool => $value !== null && $value !== ''),
                unitIndex: $unitIndex,
            );
            $unitIndex += $beds;
        }

        return new NewBookingData(
            bookingSource: $source,
            units: $units,
            guests: $guests,
            billingMode: BillingMode::MasterPays,
            externalReference: filled($data->externalReference) ? trim((string) $data->externalReference) : null,
            groupName: filled($data->groupName) ? trim((string) $data->groupName) : null,
            companyId: $company->id,
        );
    }

    private function refusal(BookingResult $result, NewPortalBookingData $data): PortalBookingResult
    {
        $line = $result->lineIndex ?? $result->guestIndex;
        $violations = [];

        if ($result->refusal === ReservationResult::REFUSAL_RESTRICTED && $line !== null) {
            $item = $data->lines[$line];
            $violations = $this->restrictionCheck->violations((int) $item->ratePlanId, $item->roomTypeId, $data->arrivalDate, $data->departureDate);
        }

        return PortalBookingResult::refused((string) $result->refusal, $line, $result->date, $violations);
    }
}

<?php

namespace App\Actions\Portal;

use App\Actions\Guests\AddIdentityDocument;
use App\Actions\Reservations\AttachGuestResult;
use App\Actions\Reservations\BookingRefused;
use App\Actions\Reservations\BookingResult;
use App\Actions\Reservations\CreateBooking;
use App\Actions\Reservations\ReservationResult;
use App\Data\Guests\IdentityDocumentInputData;
use App\Data\Portal\NewPortalBookingData;
use App\Data\Portal\NewPortalGuestData;
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
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Notifications\PortalBookingCreated;
use App\Services\Availability\AvailabilityService;
use App\Services\Portal\PortalBookingQuote;
use App\Services\Portal\PortalRatePlans;
use App\Services\Rates\RestrictionCheck;
use App\Services\Reservations\AutoAddOns;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class CreatePortalReservation
{
    /** The most rooms and beds one portal booking can have: each needs a guest name. */
    public const int MAX_UNITS = 99;

    public function __construct(
        private CreateBooking $createBooking,
        private PortalRatePlans $portalRatePlans,
        private PortalBookingQuote $portalBookingQuote,
        private AutoAddOns $autoAddOns,
        private RestrictionCheck $restrictionCheck,
        private AvailabilityService $availability,
        private AddIdentityDocument $addIdentityDocument,
        private NotifyReservationStaff $notifyReservationStaff,
    ) {}

    /**
     * Book what a company user sent, for the company of their Active link. Nothing from the client is trusted: the
     * company comes from the link, each plan must be visible to the company, the rooms left, the guests and the total
     * are checked again, and the auto-add items are added here. A line with N rooms books N units; a dorm line books
     * one unit for each bed. Every unit needs its own lead guest, always a new guest record, with an optional ID. The
     * first unit's guest is the booker. The booking uses the Company / Travel agent source, bills the company as the
     * Master folio payer (MasterPays), has no override of any kind and is Confirmed at once. The shared `CreateBooking`
     * and the IDs are saved in one transaction, so a refusal writes nothing. `PortalBookingCreated` goes to the
     * reservation staff after the save. See docs/23-company-portal/01-company-portal.md#booking.
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
            ->whereIn('id', array_map(fn (NewPortalLineData $line): int => (int) $line->roomTypeId, $data->lines))
            ->get()->keyBy('id');

        $refusal = $this->checkLines($company, $data, $roomTypes->all()) ?? $this->checkGuests($data, $roomTypes->all());

        if ($refusal !== null) {
            return $refusal;
        }

        $source = BookingSource::query()->where('property_id', $property->id)->where('type', BookingSourceType::Company->value)->where('is_system', true)->first();

        if ($source === null) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_BOOKING_SOURCE_MISSING);
        }

        [$bookingData, $lineOfUnit] = $this->bookingData($company, $source, $data, $roomTypes->all());

        try {
            $result = DB::transaction(function () use ($user, $property, $bookingData, $data): BookingResult {
                $result = ($this->createBooking)($user, $property, $bookingData);

                if (! $result->isRefused()) {
                    $this->saveIds($user, $result->reservation, $data->guests);
                }

                return $result;
            });
        } catch (BookingRefused $refused) {
            $result = $refused->result;
        }

        if ($result->isRefused()) {
            return $this->refusal($result, $data, $lineOfUnit);
        }

        ($this->notifyReservationStaff)($result->reservation, PortalBookingCreated::class);

        return PortalBookingResult::done($result->reservation);
    }

    /**
     * The checks the shared Actions do not make, in line order: a visible plan, the room type's limits and what is left
     * of it, less the lines above. Then the total the page showed against the total now. A line whose quote is refused
     * is left to `CreateBooking`, which names the reason.
     *
     * @param  array<int, RoomType>  $roomTypes
     */
    private function checkLines(Company $company, NewPortalBookingData $data, array $roomTypes): ?PortalBookingResult
    {
        $total = BigDecimal::zero();
        $complete = true;
        $taken = [];
        $units = 0;

        foreach ($data->lines as $index => $line) {
            if ($line->ratePlanId === null || ! $this->portalRatePlans->isVisible($company, $line->ratePlanId)) {
                return PortalBookingResult::refused(PortalBookingResult::REFUSAL_PLAN_NOT_VISIBLE, $index);
            }

            $roomType = $roomTypes[(int) $line->roomTypeId] ?? null;

            if ($roomType === null) {
                return PortalBookingResult::refused(ReservationResult::REFUSAL_ROOM_TYPE_NOT_FOUND, $index);
            }

            $occupancy = $this->portalBookingQuote->occupancyRefusal($roomType, $line);

            if ($occupancy !== null) {
                return PortalBookingResult::refused($occupancy, $index);
            }

            $needed = $this->portalBookingQuote->units($roomType, $line);
            $left = max(0, $this->availability->availableForStay($roomType, $data->arrivalDate, $data->departureDate) - ($taken[$roomType->id] ?? 0));

            if ($left > 0 && $needed > $left) {
                return PortalBookingResult::refused(PortalBookingResult::REFUSAL_NOT_ENOUGH_LEFT, $index, left: $left);
            }

            $taken[$roomType->id] = ($taken[$roomType->id] ?? 0) + $needed;
            $units += $needed;
            $lineTotal = $this->portalBookingQuote->lineTotal($company->property, $roomType, $line, $data->arrivalDate, $data->departureDate);

            if ($lineTotal === null) {
                $complete = false;
            } else {
                $total = $total->plus($lineTotal);
            }
        }

        if ($units > self::MAX_UNITS) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_TOO_MANY_UNITS);
        }

        if ($complete && ! $total->isEqualTo($data->expectedTotal)) {
            return PortalBookingResult::refused(PortalBookingResult::REFUSAL_PRICE_CHANGED);
        }

        return null;
    }

    /**
     * Every unit needs a guest name, in unit order.
     *
     * @param  array<int, RoomType>  $roomTypes
     */
    private function checkGuests(NewPortalBookingData $data, array $roomTypes): ?PortalBookingResult
    {
        $units = array_sum(array_map(fn (NewPortalLineData $line): int => $this->portalBookingQuote->units($roomTypes[(int) $line->roomTypeId], $line), $data->lines));

        for ($index = 0; $index < $units; $index++) {
            if (trim((string) ($data->guests[$index]->fullName ?? '')) === '') {
                return PortalBookingResult::refused(BookingResult::REFUSAL_GUEST_NAME_REQUIRED, guestIndex: $index);
            }
        }

        return null;
    }

    /**
     * The shared booking, and for each of its unit lines the portal line it came from.
     *
     * @param  array<int, RoomType>  $roomTypes
     * @return array{0: NewBookingData, 1: list<int>}
     */
    private function bookingData(Company $company, BookingSource $source, NewPortalBookingData $data, array $roomTypes): array
    {
        $addOns = $this->autoAddOns->forUnit($company->property);
        $units = [];
        $lineOfUnit = [];
        $guests = [];

        foreach ($data->lines as $index => $line) {
            $isBed = $roomTypes[(int) $line->roomTypeId]->sell_unit === SellUnit::Bed;

            foreach (range(1, $isBed ? 1 : max(1, $line->rooms)) as $ignored) {
                $units[] = new NewUnitData(
                    roomTypeId: (int) $line->roomTypeId,
                    ratePlanId: (int) $line->ratePlanId,
                    arrivalDate: $data->arrivalDate,
                    departureDate: $data->departureDate,
                    adults: $isBed ? 1 : $line->adults,
                    childAges: $isBed ? [] : $line->childAges,
                    beds: $isBed ? max(1, $line->beds) : 1,
                    addOns: $addOns,
                );
                $lineOfUnit[] = $index;
            }
        }

        $unitCount = array_sum(array_map(fn (NewPortalLineData $line): int => $this->portalBookingQuote->units($roomTypes[(int) $line->roomTypeId], $line), $data->lines));

        for ($index = 0; $index < $unitCount; $index++) {
            $guest = $data->guests[$index];
            $guests[] = new NewBookingGuestData(
                profile: [...$guest->profile, ...array_filter(['full_name' => trim((string) $guest->fullName), 'phone' => $guest->phone], fn (?string $value): bool => $value !== null && $value !== '')],
                unitIndex: $index,
            );
        }

        return [new NewBookingData(
            bookingSource: $source,
            units: $units,
            guests: $guests,
            billingMode: BillingMode::MasterPays,
            externalReference: filled($data->externalReference) ? trim((string) $data->externalReference) : null,
            groupName: filled($data->groupName) ? trim((string) $data->groupName) : null,
            companyId: $company->id,
        ), $lineOfUnit];
    }

    /**
     * Saves the ID typed for a unit's guest as that guest's first ID document. The guests are new, so none has one yet.
     *
     * @param  list<NewPortalGuestData>  $guests
     *
     * @throws BookingRefused when an ID cannot be saved, so the booking rolls back
     */
    private function saveIds(User $user, Reservation $reservation, array $guests): void
    {
        $units = null;

        foreach ($guests as $index => $guest) {
            if ($guest->idType === null || blank($guest->idNumber)) {
                continue;
            }

            $units ??= $reservation->units()->orderBy('id')->with('guests')->get()->values();
            $guestId = $units->get($index)?->guests->first()?->guest_id;
            $record = $guestId === null ? null : Guest::query()->find($guestId);

            if ($record === null || ($this->addIdentityDocument)($user, $record, new IdentityDocumentInputData($guest->idType, (string) $guest->idNumber, null, null)) === null) {
                throw new BookingRefused(BookingResult::refused(AttachGuestResult::REFUSAL_GUEST_NOT_FOUND, guestIndex: $index));
            }
        }
    }

    /**
     * @param  list<int>  $lineOfUnit
     */
    private function refusal(BookingResult $result, NewPortalBookingData $data, array $lineOfUnit): PortalBookingResult
    {
        $line = $result->lineIndex === null ? null : ($lineOfUnit[$result->lineIndex] ?? null);
        $violations = [];

        if ($result->refusal === ReservationResult::REFUSAL_RESTRICTED && $line !== null) {
            $item = $data->lines[$line];
            $violations = $this->restrictionCheck->violations((int) $item->ratePlanId, (int) $item->roomTypeId, $data->arrivalDate, $data->departureDate);
        }

        return PortalBookingResult::refused((string) $result->refusal, $line, $result->date, $violations, $result->guestIndex);
    }
}

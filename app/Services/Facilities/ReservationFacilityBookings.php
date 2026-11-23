<?php

namespace App\Services\Facilities;

use App\Data\Facilities\FacilityBookingRowData;
use App\Data\Facilities\FacilityOptionData;
use App\Enums\FacilityBookingStatus;
use App\Enums\PermissionEnum;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Folio\FolioStatement;
use App\Services\NightAudit\CurrentBusinessDate;
use Illuminate\Database\Eloquent\Collection;

/**
 * The facility bookings of a reservation: the props of the reservation page's Facilities tab, and the count of live
 * bookings for the warning on the Cancel and No-show forms. The reservations module reads them only through this
 * Service. See docs/33-facilities/04-facility-bookings.md#reservation-facilities-tab.
 */
class ReservationFacilityBookings
{
    public function __construct(
        private FacilityBookingChecks $checks,
        private ReservationChargeTo $chargeTo,
        private FolioStatement $statement,
        private CurrentBusinessDate $currentBusinessDate,
    ) {}

    /**
     * Bookings by date and time, Cancelled ones last. `canBook` needs `facilities.book` and a Confirmed or In-house
     * unit. `canCreateFolio` (the **+ New third-party folio…** choice) also needs `folio.transfer`. `defaultChargeTo`
     * is the routed folio for each facility, by facility id. `facilityDefaultDate` is the business date when it falls
     * inside the stay, else the arrival date.
     *
     * @param  array<int, string>  $unitLabels  Unit label by unit id
     * @return array<string, mixed>
     */
    public function tabProps(User $user, Property $property, Reservation $reservation, array $unitLabels): array
    {
        $canBook = $user->can(PermissionEnum::FacilitiesBook->value) && $this->checks->isLive($reservation);
        $bookings = FacilityBooking::query()
            ->where('reservation_id', $reservation->id)
            ->with(['facility', 'folio.payerGuest', 'folio.payerCompany:id,name'])
            ->orderByRaw('status = ?', [FacilityBookingStatus::Cancelled->value])
            ->ordered()
            ->get();
        $facilities = $canBook ? $this->facilities($property) : new Collection;

        return [
            'facilityBookings' => $bookings
                ->map(fn (FacilityBooking $booking): FacilityBookingRowData => FacilityBookingRowData::fromModel(
                    $booking,
                    $canBook,
                    $booking->folio instanceof Folio ? $this->statement->optionLabel($booking->folio, $unitLabels) : null,
                ))
                ->values()
                ->all(),
            'canBookFacility' => $canBook,
            'canCreateChargeToFolio' => $canBook && $user->can(PermissionEnum::FolioTransfer->value),
            'facilities' => array_values($facilities->map(fn (Facility $facility): FacilityOptionData => FacilityOptionData::fromModel($facility))->all()),
            'chargeToOptions' => $canBook ? $this->chargeTo->options($reservation, $unitLabels) : [],
            'defaultChargeTo' => $facilities->mapWithKeys(fn (Facility $facility): array => [$facility->id => $this->chargeTo->defaultFor($reservation, $facility)])->all(),
            'facilityDefaultDate' => $this->defaultDate($property, $reservation),
        ];
    }

    private function defaultDate(Property $property, Reservation $reservation): string
    {
        $arrival = (string) $reservation->units()->min('arrival_date');
        $departure = (string) $reservation->units()->max('departure_date');
        $businessDate = $this->currentBusinessDate->date($property)?->toDateString();
        $arrival = substr($arrival, 0, 10);

        return $businessDate !== null && $businessDate >= $arrival && $businessDate <= substr($departure, 0, 10)
            ? $businessDate
            : $arrival;
    }

    /**
     * Live bookings of the reservation that are not yet cancelled.
     */
    public function liveCount(Reservation $reservation): int
    {
        return FacilityBooking::query()->where('reservation_id', $reservation->id)->live()->count();
    }

    /**
     * The active facilities of the property for the Book facility Sheet, in their order.
     *
     * @return Collection<int, Facility>
     */
    public function facilities(Property $property): Collection
    {
        return Facility::query()
            ->where('property_id', $property->id)
            ->where('active', true)
            ->with(['chargeItem.transactionCode', 'sessions' => fn ($query) => $query->ordered()])
            ->ordered()
            ->get();
    }
}

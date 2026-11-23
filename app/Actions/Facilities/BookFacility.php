<?php

namespace App\Actions\Facilities;

use App\Actions\Folio\CreateFolio;
use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\EventStatus;
use App\Enums\FacilityBookingStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Facilities\FacilityBookingChecks;
use App\Services\Facilities\ReservationChargeTo;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class BookFacility
{
    public function __construct(
        private FacilityBookingChecks $checks,
        private ReservationChargeTo $chargeTo,
        private CreateFolio $createFolio,
    ) {}

    /**
     * Book a facility on an event, charged to the event folio. The event and the facility rows are locked, so two staff
     * cannot take the last slot together. Refuses `event_not_open`, `facility_inactive`, `booking_outside_event`,
     * `facility_session_not_found`, `folio_not_open`, `facility_full`, writing and logging
     * nothing. The booking takes the event's status and copies the list price unless staff changed it (null for
     * "Charge by hand"). Logs `facility_bookings.booked` on the event, and `facility_bookings.price_changed` when the
     * price is not the list price. See docs/33-facilities/04-facility-bookings.md.
     */
    public function forEvent(User $actor, Event $event, FacilityBookingInputData $data): FacilityBookingResult
    {
        return DB::transaction(function () use ($actor, $event, $data): FacilityBookingResult {
            $current = Event::query()->lockForUpdate()->findOrFail($event->id);
            $facility = Facility::query()
                ->where('property_id', $current->property_id)
                ->with('chargeItem')
                ->lockForUpdate()
                ->find($data->facilityId);

            if ($facility === null || ! $facility->active) {
                return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_INACTIVE, $facility?->name);
            }

            $refusal = $this->checks->forEvent($current, $facility, $data);

            if ($refusal !== null) {
                return FacilityBookingResult::refused($refusal, $facility->name);
            }

            $folio = Folio::query()->where('event_id', $current->id)->where('type', FolioType::Event)->where('status', '!=', FolioStatus::Voided)->first();

            if ($folio === null || $folio->status !== FolioStatus::Open) {
                return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_FOLIO_NOT_OPEN, $facility->name);
            }

            return FacilityBookingResult::done($this->save($actor, $current, $facility, $folio, $data,
                $current->status === EventStatus::Tentative ? FacilityBookingStatus::Tentative : FacilityBookingStatus::Confirmed));
        });
    }

    /**
     * Book a facility on a reservation, charged to the folio picked in Charge to (`$data->chargeTo`). The reservation
     * and the facility rows are locked. Refuses `reservation_not_live`, `facility_inactive`,
     * `facility_session_not_found`, `folio_not_of_owner`, `folio_not_open`, `facility_full`,
     * writing and logging nothing. A unit with no folio yet gets one, as `PostCharge` does. The booking is Confirmed.
     * Logs on the reservation, as `forEvent` does on the event. See docs/33-facilities/04-facility-bookings.md#charge-to.
     */
    public function forReservation(User $actor, Reservation $reservation, FacilityBookingInputData $data): FacilityBookingResult
    {
        return DB::transaction(function () use ($actor, $reservation, $data): FacilityBookingResult {
            $current = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $facility = Facility::query()
                ->where('property_id', $current->property_id)
                ->with('chargeItem')
                ->lockForUpdate()
                ->find($data->facilityId);

            if ($facility === null || ! $facility->active) {
                return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_INACTIVE, $facility?->name);
            }

            $refusal = $this->checks->forReservation($current, $facility, $data);

            if ($refusal !== null) {
                return FacilityBookingResult::refused($refusal, $facility->name);
            }

            $target = $this->chargeTo->resolve($current, $data->chargeTo);

            if ($target === null) {
                return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_FOLIO_NOT_OF_OWNER, $facility->name);
            }

            if ($target['folio'] !== null && $target['folio']->status !== FolioStatus::Open) {
                return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_FOLIO_NOT_OPEN, $facility->name);
            }

            $folio = $target['folio'] ?? ($this->createFolio)($current, $target['unit']);

            return FacilityBookingResult::done($this->save($actor, $current, $facility, $folio, $data, FacilityBookingStatus::Confirmed));
        });
    }

    /**
     * Save the booking with its price and log it on its owner. The list price is copied unless staff changed it (null
     * for "Charge by hand"). A private booking's list price is the facility's private price.
     */
    private function save(User $actor, Event|Reservation $owner, Facility $facility, Folio $folio, FacilityBookingInputData $data, FacilityBookingStatus $status): FacilityBooking
    {
        $listPrice = $data->private ? $facility->private_amount : $facility->chargeItem->default_amount;
        $unitAmount = $listPrice === null ? null : ($data->unitAmount ?? (string) $listPrice);

        $booking = new FacilityBooking([
            'facility_id' => $facility->id,
            'event_id' => $owner instanceof Event ? $owner->id : null,
            'reservation_id' => $owner instanceof Reservation ? $owner->id : null,
            'folio_id' => $folio->id,
            'booking_date' => $data->bookingDate,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->endsAt,
            'quantity' => $data->quantity,
            'private' => $data->private,
            'unit_amount' => $unitAmount,
            'status' => $status,
            'notes' => filled($data->notes) ? trim((string) $data->notes) : null,
            'created_by' => $actor->id,
        ]);
        $booking->property_id = $owner->property_id;
        $booking->save();

        activity()
            ->performedOn($owner)
            ->causedBy($actor)
            ->event('facility_bookings.booked')
            ->withProperties([
                'property_id' => $owner->property_id,
                'facility_booking_id' => $booking->id,
                'facility_id' => $facility->id,
                'booking_date' => $data->bookingDate,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
                'quantity' => $data->quantity,
                'private' => $data->private,
            ])
            ->log('Facility booked');

        if ($unitAmount !== null && ! BigDecimal::of($unitAmount)->isEqualTo(BigDecimal::of((string) $listPrice))) {
            activity()
                ->performedOn($owner)
                ->causedBy($actor)
                ->event('facility_bookings.price_changed')
                ->withProperties(['property_id' => $owner->property_id, 'facility_booking_id' => $booking->id, 'list_price' => (string) $listPrice, 'price' => $unitAmount])
                ->log('Facility booking price changed');
        }

        return $booking;
    }
}

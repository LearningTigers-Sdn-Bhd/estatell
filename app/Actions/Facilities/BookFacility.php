<?php

namespace App\Actions\Facilities;

use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\EventStatus;
use App\Enums\FacilityBookingStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\Folio;
use App\Models\User;
use App\Services\Facilities\FacilityBookingChecks;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class BookFacility
{
    public function __construct(private FacilityBookingChecks $checks) {}

    /**
     * Book a facility on an event, charged to the event folio. The event and the facility rows are locked, so two staff
     * cannot take the last slot together. Refuses `event_not_open`, `facility_inactive`, `booking_outside_event`,
     * `facility_session_not_found`, `folio_not_open`, `facility_clash` and `facility_full`, writing and logging
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

            $listPrice = $facility->chargeItem->default_amount;
            $unitAmount = $listPrice === null ? null : ($data->unitAmount ?? (string) $listPrice);

            $booking = new FacilityBooking([
                'facility_id' => $facility->id,
                'event_id' => $current->id,
                'folio_id' => $folio->id,
                'booking_date' => $data->bookingDate,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
                'quantity' => $data->quantity,
                'unit_amount' => $unitAmount,
                'status' => $current->status === EventStatus::Tentative ? FacilityBookingStatus::Tentative : FacilityBookingStatus::Confirmed,
                'notes' => filled($data->notes) ? trim((string) $data->notes) : null,
                'created_by' => $actor->id,
            ]);
            $booking->property_id = $current->property_id;
            $booking->save();

            activity()
                ->performedOn($current)
                ->causedBy($actor)
                ->event('facility_bookings.booked')
                ->withProperties([
                    'property_id' => $current->property_id,
                    'facility_booking_id' => $booking->id,
                    'facility_id' => $facility->id,
                    'booking_date' => $data->bookingDate,
                    'starts_at' => $data->startsAt,
                    'ends_at' => $data->endsAt,
                    'quantity' => $data->quantity,
                ])
                ->log('Facility booked');

            if ($unitAmount !== null && ! BigDecimal::of($unitAmount)->isEqualTo(BigDecimal::of((string) $listPrice))) {
                activity()
                    ->performedOn($current)
                    ->causedBy($actor)
                    ->event('facility_bookings.price_changed')
                    ->withProperties(['property_id' => $current->property_id, 'facility_booking_id' => $booking->id, 'list_price' => (string) $listPrice, 'price' => $unitAmount])
                    ->log('Facility booking price changed');
            }

            return FacilityBookingResult::done($booking);
        });
    }
}

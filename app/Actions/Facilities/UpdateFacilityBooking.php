<?php

namespace App\Actions\Facilities;

use App\Actions\Folio\CreateFolio;
use App\Data\Facilities\FacilityBookingInputData;
use App\Enums\FacilityBookingStatus;
use App\Enums\FolioStatus;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Facilities\FacilityBookingChecks;
use App\Services\Facilities\ReservationChargeTo;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class UpdateFacilityBooking
{
    public function __construct(
        private FacilityBookingChecks $checks,
        private ReservationChargeTo $chargeTo,
        private CreateFolio $createFolio,
    ) {}

    /**
     * Change a booking's date, time, quantity, price and notes, and a reservation booking's Charge to. The facility
     * stays. Runs the same checks as `BookFacility`, leaving this booking out of the clash check. A Cancelled booking is
     * refused (`facility_booking_cancelled`). A null price keeps the booking's price; switching to or from a private
     * booking takes the list price of the new kind instead, and a reservation booking with no
     * Charge to keeps its folio. Logs `facility_bookings.updated` on the owner, and `facility_bookings.price_changed`
     * when the price changes to one that is not the list price. Once posted (a `facility:{id}` line exists), only the
     * notes can change: any other change is refused (`facility_booking_posted`), and staff cancel and book again.
     * See docs/33-facilities/04-facility-bookings.md#actions.
     */
    public function __invoke(User $actor, FacilityBooking $booking, FacilityBookingInputData $data): FacilityBookingResult
    {
        return DB::transaction(function () use ($actor, $booking, $data): FacilityBookingResult {
            $owner = $booking->event_id !== null
                ? Event::query()->lockForUpdate()->findOrFail($booking->event_id)
                : Reservation::query()->lockForUpdate()->findOrFail($booking->reservation_id);
            $current = FacilityBooking::query()->lockForUpdate()->findOrFail($booking->id);
            $facility = Facility::query()->with('chargeItem')->lockForUpdate()->findOrFail($current->facility_id);

            if ($current->status === FacilityBookingStatus::Cancelled) {
                return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_BOOKING_CANCELLED, $facility->name);
            }

            if (FolioLine::query()->where('idempotency_key', 'facility:'.$current->id)->exists()) {
                return $this->updatePostedNotes($actor, $owner, $current, $facility, $data);
            }

            $refusal = $owner instanceof Event
                ? $this->checks->forEvent($owner, $facility, $data, $current->id)
                : $this->checks->forReservation($owner, $facility, $data, $current->id);

            if ($refusal !== null) {
                return FacilityBookingResult::refused($refusal, $facility->name);
            }

            if ($owner instanceof Reservation && $data->chargeTo !== null) {
                $target = $this->chargeTo->resolve($owner, $data->chargeTo);

                if ($target === null) {
                    return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_FOLIO_NOT_OF_OWNER, $facility->name);
                }

                if ($target['folio'] !== null && $target['folio']->status !== FolioStatus::Open) {
                    return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_FOLIO_NOT_OPEN, $facility->name);
                }

                $current->folio_id = ($target['folio'] ?? ($this->createFolio)($owner, $target['unit']))->id;
            }

            $listPrice = $data->private ? $facility->private_amount : $facility->chargeItem->default_amount;
            $priceBefore = $current->unit_amount;
            $switchesPrivate = $data->private !== $current->private;
            $keptPrice = $switchesPrivate ? ($listPrice === null ? null : (string) $listPrice) : $current->unit_amount;

            $current->fill([
                'booking_date' => $data->bookingDate,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
                'quantity' => $data->quantity,
                'private' => $data->private,
                'unit_amount' => $keptPrice === null ? null : ($data->unitAmount ?? $keptPrice),
                'notes' => filled($data->notes) ? trim((string) $data->notes) : null,
            ]);
            $changes = array_keys($current->getDirty());
            $current->save();

            activity()
                ->performedOn($owner)
                ->causedBy($actor)
                ->event('facility_bookings.updated')
                ->withProperties(['property_id' => $owner->property_id, 'facility_booking_id' => $current->id, 'changed' => $changes])
                ->log('Facility booking updated');

            $price = $current->unit_amount;

            if ($price !== null && $listPrice !== null
                && ! BigDecimal::of($price)->isEqualTo(BigDecimal::of((string) $priceBefore))
                && ! BigDecimal::of($price)->isEqualTo(BigDecimal::of((string) $listPrice))) {
                activity()
                    ->performedOn($owner)
                    ->causedBy($actor)
                    ->event('facility_bookings.price_changed')
                    ->withProperties(['property_id' => $owner->property_id, 'facility_booking_id' => $current->id, 'list_price' => (string) $listPrice, 'price' => $price])
                    ->log('Facility booking price changed');
            }

            return FacilityBookingResult::done($current);
        });
    }

    /**
     * A posted booking keeps its date, time, quantity, price and folio. Only the notes are saved.
     */
    private function updatePostedNotes(User $actor, Event|Reservation $owner, FacilityBooking $current, Facility $facility, FacilityBookingInputData $data): FacilityBookingResult
    {
        $changesCharge = $data->bookingDate !== $current->booking_date->toDateString()
            || substr($data->startsAt, 0, 5) !== substr($current->starts_at, 0, 5)
            || substr($data->endsAt, 0, 5) !== substr($current->ends_at, 0, 5)
            || $data->quantity !== $current->quantity
            || $data->private !== $current->private
            || ($data->unitAmount !== null && $current->unit_amount !== null && ! BigDecimal::of($data->unitAmount)->isEqualTo(BigDecimal::of($current->unit_amount)))
            || ($owner instanceof Reservation && $data->chargeTo !== null && $data->chargeTo !== 'f'.$current->folio_id);

        if ($changesCharge) {
            return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_POSTED, $facility->name);
        }

        $current->notes = filled($data->notes) ? trim((string) $data->notes) : null;

        if ($current->isDirty('notes')) {
            $current->save();

            activity()
                ->performedOn($owner)
                ->causedBy($actor)
                ->event('facility_bookings.updated')
                ->withProperties(['property_id' => $owner->property_id, 'facility_booking_id' => $current->id, 'changed' => ['notes']])
                ->log('Facility booking updated');
        }

        return FacilityBookingResult::done($current);
    }
}

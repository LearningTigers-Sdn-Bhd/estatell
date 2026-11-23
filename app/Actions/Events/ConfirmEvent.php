<?php

namespace App\Actions\Events;

use App\Actions\Facilities\FacilityBookingResult;
use App\Enums\EventStatus;
use App\Enums\FacilityBookingStatus;
use App\Models\Event;
use App\Models\Facility;
use App\Models\FacilityBooking;
use App\Models\User;
use App\Services\Facilities\FacilityAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConfirmEvent
{
    public function __construct(private FacilityAvailability $availability) {}

    /**
     * Move a Tentative event to Confirmed, with its Tentative facility bookings. Each booking runs the clash check in
     * turn, so two bookings of this event that clash with each other are caught too. Any other status is refused
     * (`event_not_tentative`), and a booking that does not fit is refused (`facility_clash` or `facility_full`, with the
     * facility's name): nothing is written or logged. Logs `events.confirmed`.
     * See docs/33-facilities/03-events-and-event-folios.md.
     */
    public function __invoke(User $actor, Event $event): FacilityBookingResult
    {
        DB::beginTransaction();

        try {
            $current = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($current->status !== EventStatus::Tentative) {
                DB::rollBack();

                return FacilityBookingResult::refused(FacilityBookingResult::REFUSAL_EVENT_NOT_TENTATIVE);
            }

            $bookings = FacilityBooking::query()
                ->where('event_id', $current->id)
                ->where('status', FacilityBookingStatus::Tentative)
                ->orderBy('facility_id')
                ->ordered()
                ->get();
            $facilities = Facility::query()
                ->whereKey($bookings->pluck('facility_id')->unique()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($bookings as $booking) {
                $facility = $facilities->get($booking->facility_id);
                $refusal = $this->availability->refusalFor($facility, $booking->booking_date->toDateString(), $booking->starts_at, $booking->ends_at, $booking->quantity, $booking->id);

                if ($refusal !== null) {
                    DB::rollBack();

                    return FacilityBookingResult::refused($refusal, $facility->name);
                }

                $booking->status = FacilityBookingStatus::Confirmed;
                $booking->save();
            }

            $current->status = EventStatus::Confirmed;
            $current->save();

            activity()
                ->performedOn($current)
                ->causedBy($actor)
                ->event('events.confirmed')
                ->withProperties(['property_id' => $current->property_id, 'facility_booking_ids' => $bookings->pluck('id')->all()])
                ->log('Event confirmed');

            DB::commit();

            return FacilityBookingResult::done();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }
}

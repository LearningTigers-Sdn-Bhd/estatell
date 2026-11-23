<?php

namespace App\Actions\Events;

use App\Actions\Facilities\CancelFacilityBookings;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancelEvent
{
    public function __construct(private CancelFacilityBookings $cancelFacilityBookings) {}

    /**
     * Cancel a Tentative or Confirmed event. Any other status is refused (false), and nothing is written or logged.
     * Its facility bookings are cancelled with it (`CancelFacilityBookings`). The folio stays Open: staff refund the
     * deposit or post a cancellation fee, then close it at 0.00. Logs `events.cancelled`.
     * See docs/33-facilities/03-events-and-event-folios.md.
     */
    public function __invoke(User $actor, Event $event): bool
    {
        return DB::transaction(function () use ($actor, $event): bool {
            $current = Event::query()->lockForUpdate()->findOrFail($event->id);

            if (! $current->status->canTransitionTo(EventStatus::Cancelled)) {
                return false;
            }

            $current->forceFill([
                'status' => EventStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ])->save();

            $this->cancelFacilityBookings->forEvent($actor, $current);

            activity()
                ->performedOn($current)
                ->causedBy($actor)
                ->event('events.cancelled')
                ->withProperties(['property_id' => $current->property_id])
                ->log('Event cancelled');

            return true;
        });
    }
}

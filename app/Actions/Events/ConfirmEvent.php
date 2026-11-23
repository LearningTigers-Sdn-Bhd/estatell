<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConfirmEvent
{
    /**
     * Move a Tentative event to Confirmed. Any other status is refused (false), and nothing is written or logged.
     * Logs `events.confirmed`. The facility bookings item adds the clash check here.
     * See docs/33-facilities/03-events-and-event-folios.md.
     */
    public function __invoke(User $actor, Event $event): bool
    {
        return DB::transaction(function () use ($actor, $event): bool {
            $current = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($current->status !== EventStatus::Tentative) {
                return false;
            }

            $current->status = EventStatus::Confirmed;
            $current->save();

            activity()
                ->performedOn($current)
                ->causedBy($actor)
                ->event('events.confirmed')
                ->withProperties(['property_id' => $current->property_id])
                ->log('Event confirmed');

            return true;
        });
    }
}

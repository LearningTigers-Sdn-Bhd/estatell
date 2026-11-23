<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Folio;

class CompleteEvent
{
    /**
     * Mark the event of a closed event folio Completed. Only a Confirmed event completes: a Tentative or Cancelled
     * event keeps its status. `CloseFolio` calls it inside its transaction. See
     * docs/33-facilities/03-events-and-event-folios.md.
     */
    public function __invoke(Folio $folio): void
    {
        if ($folio->event_id === null) {
            return;
        }

        Event::query()
            ->whereKey($folio->event_id)
            ->where('status', EventStatus::Confirmed)
            ->update(['status' => EventStatus::Completed, 'updated_at' => now()]);
    }
}

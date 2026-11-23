<?php

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Folio;

class ReopenEvent
{
    /**
     * Put the Completed event of a reopened event folio back to Confirmed. `ReopenFolio` calls it inside its
     * transaction. See docs/33-facilities/03-events-and-event-folios.md.
     */
    public function __invoke(Folio $folio): void
    {
        if ($folio->event_id === null) {
            return;
        }

        Event::query()
            ->whereKey($folio->event_id)
            ->where('status', EventStatus::Completed)
            ->update(['status' => EventStatus::Confirmed, 'updated_at' => now()]);
    }
}

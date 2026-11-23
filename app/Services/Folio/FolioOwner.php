<?php

namespace App\Services\Folio;

use App\Models\Event;
use App\Models\Folio;
use App\Models\Reservation;

/**
 * What a folio belongs to: its reservation, or its event for an event folio. The folio's activity is logged on it.
 * See docs/33-facilities/03-events-and-event-folios.md.
 */
class FolioOwner
{
    public function of(Folio $folio): Reservation|Event
    {
        return $folio->reservation_id !== null
            ? $folio->reservation()->firstOrFail()
            : $folio->event()->firstOrFail();
    }
}

<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One activity log entry in readable form. `reason` holds an override reason when the entry has one. `kind` is the log event
 * (`reservation.unit_added`), which the page maps to an icon. `createdAt` is in the property time zone
 * (`2026-10-14T20:00:00+08:00`), so the page reads the day and time from the string and shows the same text on the
 * server and in any browser.
 */
class ReservationActivityData extends Data
{
    public function __construct(
        public int $id,
        public ?string $kind,
        public string $text,
        public ?string $reason,
        public ?string $causerName,
        public string $createdAt,
    ) {}
}

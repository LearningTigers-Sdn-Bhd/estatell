<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One activity log entry in readable form. `reason` holds an override reason when the entry has one.
 */
class ReservationActivityData extends Data
{
    public function __construct(
        public int $id,
        public string $text,
        public ?string $reason,
        public ?string $causerName,
        public string $createdAt,
    ) {}
}

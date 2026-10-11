<?php

namespace App\Data\Folio;

use Spatie\LaravelData\Data;

/**
 * Scheduled charges of one room that the chosen rules send to the target: "Room nights 15–16 Oct · 2 nights".
 */
class MoveIncomingScheduleData extends Data
{
    public function __construct(
        public int $unitId,
        public string $label,
        public string $text,
        public string $amount,
    ) {}
}

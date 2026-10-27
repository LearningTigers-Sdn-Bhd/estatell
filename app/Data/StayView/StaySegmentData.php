<?php

namespace App\Data\StayView;

use Spatie\LaravelData\Data;

/**
 * Consecutive nights of one unit in the same room or bed. A room move starts a new segment. `firstNight` and
 * `lastNight` are `Y-m-d`, both included, and cut to the window.
 */
class StaySegmentData extends Data
{
    public function __construct(
        public string $rowKey,
        public string $firstNight,
        public string $lastNight,
    ) {}
}

<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/** One night in the Change guests Dialog: its price now, its price after, and why it keeps its price. */
class UnitPartyNightData extends Data
{
    public function __construct(
        public string $date,
        public string $was,
        public string $will,
        public bool $charged,
        public bool $overridden,
        public bool $changes,
    ) {}
}

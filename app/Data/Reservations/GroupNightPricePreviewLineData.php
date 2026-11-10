<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * One unit in the group price preview: its nights, its current price per night (`was`, and `wasHigh` when its nights
 * differ), how many of its nights are charged, and with a price typed, the difference with tax or the refusal.
 */
class GroupNightPricePreviewLineData extends Data
{
    public function __construct(
        public int $unitId,
        public string $label,
        public int $nights,
        public ?string $was,
        public ?string $wasHigh,
        public int $charged,
        public ?string $difference,
        public ?string $error,
    ) {}
}

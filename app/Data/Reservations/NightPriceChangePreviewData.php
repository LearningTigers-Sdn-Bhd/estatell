<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Change price Dialog shows: every night of the unit, the current price of the ticked nights (`was`, and
 * `wasHigh` when they differ), whether one had a discount, and with a price typed, the charged nights that will be
 * reversed and charged again, the Scheduled nights that only change, and the difference with tax, or the refusal.
 */
class NightPriceChangePreviewData extends Data
{
    /**
     * @param  list<NightPriceNightData>  $nights
     * @param  list<string>  $correctedDates
     * @param  list<string>  $scheduledDates
     */
    public function __construct(
        public array $nights,
        public ?string $was,
        public ?string $wasHigh,
        public bool $hadDiscount,
        public array $correctedDates,
        public array $scheduledDates,
        public ?string $difference,
        public ?string $error,
    ) {}
}

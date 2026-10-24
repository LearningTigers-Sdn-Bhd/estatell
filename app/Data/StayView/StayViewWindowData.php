<?php

namespace App\Data\StayView;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * The nights the Stay View shows, with the dates it marks. All are `Y-m-d`. A Room View window has `from` equal to `to`.
 */
class StayViewWindowData extends Data
{
    public function __construct(
        public string $from,
        public string $to,
        public string $today,
        public string $businessDate,
    ) {}

    public static function forDates(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today, CarbonImmutable $businessDate): self
    {
        return new self(
            from: $from->toDateString(),
            to: $to->toDateString(),
            today: $today->toDateString(),
            businessDate: $businessDate->toDateString(),
        );
    }
}

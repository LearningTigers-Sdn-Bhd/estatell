<?php

namespace App\Data\Rates;

use App\Models\RateHourPrice;
use Spatie\LaravelData\Data;

/** The price of a day use block of a number of hours on one date, shown in the rate calendar. */
class RateCalendarHourData extends Data
{
    public function __construct(
        public int $hours,
        public string $amount,
    ) {}

    public static function fromModel(RateHourPrice $price): self
    {
        return new self(hours: $price->hours, amount: $price->amount);
    }
}

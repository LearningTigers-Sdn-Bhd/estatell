<?php

namespace App\Data\Rates;

use App\Models\RateOccupancyPrice;
use Spatie\LaravelData\Data;

/** An explicit price for a number of adults on one date, shown in the rate calendar's date editor. */
class RateCalendarOccupancyData extends Data
{
    public function __construct(
        public int $adults,
        public string $amount,
    ) {}

    public static function fromModel(RateOccupancyPrice $price): self
    {
        return new self(adults: $price->adults, amount: $price->amount);
    }
}

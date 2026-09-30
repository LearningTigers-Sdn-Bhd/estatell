<?php

namespace App\Data\Rates;

use App\Models\Rate;
use Spatie\LaravelData\Data;

/** One date of one plan row in the rate calendar: the price shown and the selling restrictions. */
class RateCalendarCellData extends Data
{
    public function __construct(
        public string $date,
        public ?string $amount,
        public ?int $minStay,
        public ?int $maxStay,
        public bool $closedToArrival,
        public bool $closedToDeparture,
        public bool $stopSell,
    ) {}

    /**
     * @param  string|null  $amount  The price to show. A derived plan passes its calculated price.
     */
    public static function fromRate(Rate $rate, ?string $amount): self
    {
        return new self(
            date: $rate->date->toDateString(),
            amount: $amount,
            minStay: $rate->min_stay,
            maxStay: $rate->max_stay,
            closedToArrival: $rate->closed_to_arrival,
            closedToDeparture: $rate->closed_to_departure,
            stopSell: $rate->stop_sell,
        );
    }

    /**
     * A derived plan date with a calculated price and no restriction row of its own.
     */
    public static function priceOnly(string $date, ?string $amount): self
    {
        return new self($date, $amount, null, null, false, false, false);
    }
}

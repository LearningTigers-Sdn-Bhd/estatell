<?php

namespace App\Data\Rates;

use App\Models\Rate;
use Spatie\LaravelData\Data;

/** One date of one plan row in the rate calendar: the price shown and the selling restrictions. */
class RateCalendarCellData extends Data
{
    /**
     * @param  list<RateCalendarOccupancyData>  $occupancy  Explicit prices by adult count, by adults. Empty for a derived plan.
     * @param  bool  $arrivalNotAllowed  A package date whose weekday the package cannot start on. The price is still shown.
     */
    public function __construct(
        public string $date,
        public ?string $amount,
        public ?int $minStay,
        public ?int $maxStay,
        public bool $closedToArrival,
        public bool $closedToDeparture,
        public bool $stopSell,
        public array $occupancy = [],
        public bool $arrivalNotAllowed = false,
    ) {}

    /**
     * Load the rate's `occupancyPrices` first.
     *
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
            occupancy: $rate->occupancyPrices->sortBy('adults')->map(RateCalendarOccupancyData::fromModel(...))->values()->all(),
        );
    }

    /**
     * A package date in a price period: the price of the whole stay for guests arriving that day, with the restrictions
     * of the date's rate row when it has one. A package has no prices by adults.
     */
    public static function forPackage(?Rate $rate, string $date, string $amount, bool $arrivalNotAllowed): self
    {
        return new self(
            date: $date,
            amount: $amount,
            minStay: $rate?->min_stay,
            maxStay: $rate?->max_stay,
            closedToArrival: $rate->closed_to_arrival ?? false,
            closedToDeparture: $rate->closed_to_departure ?? false,
            stopSell: $rate->stop_sell ?? false,
            occupancy: [],
            arrivalNotAllowed: $arrivalNotAllowed,
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

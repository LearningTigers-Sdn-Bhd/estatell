<?php

namespace App\Data\Rates;

use Spatie\LaravelData\Data;

/** The saved prices of one plan for a date range: a group per room type the plan sells, each with the plan's one row. */
class RatePlanSavedPricesData extends Data
{
    /**
     * @param  list<string>  $dates  Every date of the range as `Y-m-d`.
     * @param  list<RateCalendarRoomTypeData>  $roomTypes  Each holds only this plan, and no availability.
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $dates,
        public array $roomTypes,
    ) {}
}

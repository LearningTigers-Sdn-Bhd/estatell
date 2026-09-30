<?php

namespace App\Data\Rates;

use Spatie\LaravelData\Data;

/** The rate calendar for one month: the room type groups and the plans whose prices are running out. */
class RateCalendarData extends Data
{
    /**
     * @param  list<string>  $dates  Every date of the month as `Y-m-d`.
     * @param  list<RateCalendarRoomTypeData>  $roomTypes
     * @param  list<RateCalendarEndingData>  $ending
     */
    public function __construct(
        public string $month,
        public array $dates,
        public array $roomTypes,
        public array $ending,
    ) {}
}

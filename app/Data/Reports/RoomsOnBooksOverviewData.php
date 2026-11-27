<?php

namespace App\Data\Reports;

use Spatie\LaravelData\Data;

/**
 * The tables of Rooms on books › Overview (the top of Tanini's sheet). `impact` and `lengthOfStay` cover the changes
 * made in the dates; `pace` and `recent` are counted from `asOf`, the open business date. Money is a decimal string
 * with two decimals. See docs/22-reports/05-rooms-on-books.md#overview.
 */
class RoomsOnBooksOverviewData extends Data
{
    /**
     * @param  list<RoomsOnBooksImpactData>  $impact  One line for each kind of change, then Net.
     * @param  list<RoomsOnBooksPaceData>  $pace  Next 10 days, 11–20 days, 21–30 days, Next 30 days.
     * @param  list<RoomsOnBooksRecentDayData>  $recent  The 10 closed dates before `asOf`, oldest first.
     * @param  list<RoomsOnBooksLengthOfStayData>  $lengthOfStay  The new bookings by nights: 1–3, 4–7, 8–10, 11+.
     */
    public function __construct(
        public string $asOf,
        public array $impact,
        public array $pace,
        public array $recent,
        public array $lengthOfStay,
    ) {}
}

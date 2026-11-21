<?php

namespace App\Services\Reports\BookingPerformance;

use App\Models\Property;

/**
 * How Booking performance puts unit rows into group rows and names them. One class per `ReportGroup` tab, chosen by
 * `ReportGroup::bookingPerformanceGrouping()`. See docs/22-reports/02-guest-list-and-daily-report.md#5-booking-performance.
 */
interface BookingPerformanceGrouping
{
    /**
     * The group of one unit row of `BookingPerformanceReport::units()`. Null puts the unit in the "none" row.
     *
     * @param  object{room_type_id: int, rate_plan_id: int|null, status: string, booking_source_id: int, sales_person_id: int|null, market_segment_id: int|null, booked_on: string}  $unit
     */
    public function idOf(object $unit): int|string|null;

    /**
     * The names (and badges) of the groups on the page, read with at most one query.
     *
     * @param  list<int|string>  $ids
     * @return array<int|string, GroupLabel>
     */
    public function labels(Property $property, array $ids): array;

    /**
     * The name of the row of units with no group, for example "No sales person".
     */
    public function noneLabel(): string;
}

<?php

namespace App\Data\Reports;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * One row of Rooms on books › On the books: one date. The property names are the column keys of
 * `ReportKey::OnTheBooks`. Rooms and beds count as one unit each. `in_house` is the units booked for the night before
 * (the rooms full on the morning of the date); `rooms_on_books` the units booked for the night of the date. `arr` is
 * the booked price of the paid nights over their count. See docs/22-reports/05-rooms-on-books.md.
 */
class RoomsOnBooksRow extends Data implements ReportRow
{
    public function __construct(
        public string $key,
        public string $date,
        public int $in_house,
        public int $arrivals,
        public int $departures,
        public int $group_arrivals,
        public int $rooms_on_books,
        public int $complimentary,
        public int $available,
        public string $occupancy,
        public string $arr,
        public string $room_revenue,
    ) {}

    /**
     * @return array<string, string>
     */
    public function cells(): array
    {
        return [
            'date' => CarbonImmutable::parse($this->date)->format('D j M Y'),
            'in_house' => (string) $this->in_house,
            'arrivals' => (string) $this->arrivals,
            'departures' => (string) $this->departures,
            'group_arrivals' => (string) $this->group_arrivals,
            'rooms_on_books' => (string) $this->rooms_on_books,
            'complimentary' => (string) $this->complimentary,
            'available' => (string) $this->available,
            'occupancy' => number_format((float) $this->occupancy, 2),
            'arr' => number_format((float) $this->arr, 2),
            'room_revenue' => number_format((float) $this->room_revenue, 2),
        ];
    }

    /**
     * @return array<string, int|float|string>
     */
    public function values(): array
    {
        return [
            'date' => $this->date,
            'in_house' => $this->in_house,
            'arrivals' => $this->arrivals,
            'departures' => $this->departures,
            'group_arrivals' => $this->group_arrivals,
            'rooms_on_books' => $this->rooms_on_books,
            'complimentary' => $this->complimentary,
            'available' => $this->available,
            'occupancy' => round((float) $this->occupancy, 2),
            'arr' => round((float) $this->arr, 2),
            'room_revenue' => round((float) $this->room_revenue, 2),
        ];
    }
}

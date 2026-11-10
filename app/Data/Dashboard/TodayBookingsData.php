<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

class TodayBookingsData extends Data
{
    /** @param list<TodayBookingRowData> $rows */
    public function __construct(public array $rows, public int $total, public string $date) {}
}

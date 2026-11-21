<?php

namespace App\Services\Reports\BookingPerformance;

use App\Models\Property;

/**
 * By the date the booking was made, in the property time zone. The row name is the date (`Y-m-d`).
 */
class BookingDateGrouping implements BookingPerformanceGrouping
{
    public function idOf(object $unit): string
    {
        return (string) $unit->booked_on;
    }

    public function labels(Property $property, array $ids): array
    {
        return array_combine($ids, array_map(fn (int|string $id): GroupLabel => new GroupLabel((string) $id), $ids));
    }

    public function noneLabel(): string
    {
        return '';
    }
}

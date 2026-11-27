<?php

namespace App\Services\Reports\BookingPerformance;

use App\Data\Reservations\BookingSourceBadgeData;
use App\Models\BookingSource;
use App\Models\Property;

/**
 * By booking source, with the source badge. Every booking has a source.
 */
class SourceGrouping implements BookingPerformanceGrouping
{
    public function idOf(object $unit): int
    {
        return (int) $unit->booking_source_id;
    }

    public function labels(Property $property, array $ids): array
    {
        return BookingSource::query()
            ->where('property_id', $property->id)
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (BookingSource $source): array => [$source->id => new GroupLabel($source->name, source: BookingSourceBadgeData::fromModel($source))])
            ->all();
    }

    public function noneLabel(): string
    {
        return __('No source');
    }
}

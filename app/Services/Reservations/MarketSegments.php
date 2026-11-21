<?php

namespace App\Services\Reservations;

use App\Data\Reservations\MarketSegmentData;
use App\Models\MarketSegment;
use App\Models\Property;

/**
 * The market segments of a property. See docs/12-reservations/15-market-segments.md.
 */
class MarketSegments
{
    /**
     * Every segment for Settings, the active ones first, then by name.
     *
     * @return list<MarketSegmentData>
     */
    public function all(Property $property): array
    {
        return array_values($property->marketSegments()
            ->orderByDesc('active')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (MarketSegment $segment): MarketSegmentData => MarketSegmentData::fromModel($segment))
            ->all());
    }

    /**
     * The picker list: the active segments by name, in one query. Empty when the property has none, so the booking
     * form hides the field.
     *
     * @return list<MarketSegmentData>
     */
    public function options(Property $property): array
    {
        return array_values($property->marketSegments()
            ->active()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (MarketSegment $segment): MarketSegmentData => MarketSegmentData::fromModel($segment))
            ->all());
    }

    public function isAvailable(Property|int $property, int $segmentId): bool
    {
        return MarketSegment::query()
            ->where('property_id', $property instanceof Property ? $property->id : $property)
            ->active()
            ->whereKey($segmentId)
            ->exists();
    }

    /**
     * True when another segment of the property has the name, ignoring case.
     */
    public function nameTaken(int $propertyId, string $name, ?int $exceptId = null): bool
    {
        return MarketSegment::query()
            ->where('property_id', $propertyId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();
    }
}

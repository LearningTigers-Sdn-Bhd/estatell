<?php

namespace App\Data\BookingEngine;

use App\Enums\AmenityType;
use App\Models\Amenity;
use Illuminate\Database\Eloquent\Collection;
use Spatie\LaravelData\Data;

/**
 * What an amenity picker shows: the amenities that can be ticked, and the ids ticked now. A retired amenity is in the
 * list only while it is ticked, so the owner can untick it.
 */
class AmenityPickerData extends Data
{
    /**
     * @param  list<AmenityData>  $amenities
     * @param  list<int>  $selectedIds
     */
    public function __construct(
        public array $amenities,
        public array $selectedIds,
    ) {}

    /**
     * @param  Collection<int, Amenity>  $selected  The amenities linked to the property or room type now.
     */
    public static function forType(AmenityType $type, Collection $selected): self
    {
        $selectedIds = $selected->pluck('id')->all();

        return new self(
            amenities: array_values(Amenity::query()->with('category')->ofType($type)
                ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $selectedIds))
                ->ordered()->get()
                ->map(fn (Amenity $amenity): AmenityData => AmenityData::fromModel($amenity))
                ->all()),
            selectedIds: array_values($selectedIds),
        );
    }
}

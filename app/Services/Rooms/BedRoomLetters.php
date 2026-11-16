<?php

namespace App\Services\Rooms;

use App\Enums\SellUnit;
use App\Models\Property;

/**
 * The default bed prefix of each active Bed room: its letter by its place among the property's active Bed rooms
 * in room order (the first is A). Read only.
 */
class BedRoomLetters
{
    public function __construct(private BedLabelGenerator $labels) {}

    /**
     * @return array<int, string> The letter of each active Bed room, keyed by room id.
     */
    public function forProperty(Property $property): array
    {
        return $property->rooms()->active()
            ->whereHas('roomType', fn ($query) => $query->where('sell_unit', SellUnit::Bed->value))
            ->ordered()
            ->pluck('id')
            ->values()
            ->mapWithKeys(fn (int $id, int $index): array => [$id => $this->labels->letter($index)])
            ->all();
    }
}

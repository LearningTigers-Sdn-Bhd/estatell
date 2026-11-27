<?php

namespace App\Services\Reports;

use App\Models\Property;
use App\Models\RoomType;
use App\Services\Availability\AvailabilityService;
use Carbon\CarbonImmutable;

/**
 * The rooms and beds on sale on each date: the active units of every room type that no open block takes off the
 * night, as the night audit counts rooms available. Used by History & forecast and Rooms on books.
 */
class RoomSupply
{
    public function __construct(private AvailabilityService $availability) {}

    /**
     * @return array<string, int> Keyed by `Y-m-d`, both dates included.
     */
    public function between(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $supply = [];

        foreach ($this->availability->nightsForRoomTypes(RoomType::query()->where('property_id', $property->id)->get(), $from, $to->addDay()) as $nights) {
            foreach ($nights as $night) {
                $date = $night->date->toDateString();
                $supply[$date] = ($supply[$date] ?? 0) + max(0, $night->total - $night->blocked);
            }
        }

        return $supply;
    }
}

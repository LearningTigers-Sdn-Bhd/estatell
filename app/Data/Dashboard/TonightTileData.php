<?php

namespace App\Data\Dashboard;

use Spatie\LaravelData\Data;

/**
 * The Tonight tile: occupancy and free units on the business date, then seven nights and the room types.
 */
class TonightTileData extends Data
{
    /**
     * @param  int  $occupancy  Percent of the units on sale that are sold tonight.
     * @param  int  $roomsFree  Free units of room types that sell rooms.
     * @param  int|null  $bedsFree  Free units of room types that sell beds, or null when the property has none.
     * @param  list<TonightNightData>  $nights  Seven nights from the business date.
     * @param  list<TonightRoomTypeData>  $roomTypes  Tonight by room type.
     */
    public function __construct(
        public int $occupancy,
        public int $roomsFree,
        public ?int $bedsFree,
        public array $nights,
        public array $roomTypes,
    ) {}
}

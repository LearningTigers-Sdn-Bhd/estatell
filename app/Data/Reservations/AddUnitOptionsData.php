<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Add unit Sheet needs to offer: the room types for sale and the rate plans sold for them.
 */
class AddUnitOptionsData extends Data
{
    /**
     * @param  list<RoomTypeOptionData>  $roomTypes
     * @param  list<RatePlanOptionData>  $ratePlans
     */
    public function __construct(
        public array $roomTypes,
        public array $ratePlans,
    ) {}
}

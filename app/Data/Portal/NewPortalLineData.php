<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;

/**
 * One line of a portal search or booking: a rate plan and room type with the guests of each room and the number of
 * rooms, or a dorm line with its beds. A dorm line always has one adult for each bed and no children. A search may
 * send a line without a room type (not picked yet) or without a plan.
 */
class NewPortalLineData extends Data
{
    /**
     * @param  list<int>  $childAges
     */
    public function __construct(
        public ?int $ratePlanId,
        public ?int $roomTypeId,
        public int $adults,
        public array $childAges = [],
        public int $beds = 1,
        public int $rooms = 1,
    ) {}
}

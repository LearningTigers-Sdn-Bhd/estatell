<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;

/**
 * One line of a portal search or booking: a room type with its guests, or a dorm line with its beds. A dorm line
 * always has one adult for each bed and no children. `ratePlanId` is empty in a search. `guestName` and `guestPhone`
 * are the lead guest of the line, always saved as a new guest record.
 */
class NewPortalLineData extends Data
{
    /**
     * @param  list<int>  $childAges
     */
    public function __construct(
        public int $roomTypeId,
        public int $adults,
        public array $childAges = [],
        public int $beds = 1,
        public ?int $ratePlanId = null,
        public ?string $guestName = null,
        public ?string $guestPhone = null,
    ) {}
}

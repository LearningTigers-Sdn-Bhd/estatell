<?php

namespace App\Data\BookingEngine;

use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/**
 * One row of the Rates tab: a rate plan guests may book online, and the room types it sells.
 */
class BookingWebsiteRatePlanData extends Data
{
    /**
     * @param  list<string>  $roomTypeNames  The active room types the plan sells, in display order.
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $bookableOnline,
        public array $roomTypeNames,
    ) {}

    /**
     * Load the plan's `roomTypes` first. Archived room types are left out.
     */
    public static function fromModel(RatePlan $ratePlan): self
    {
        return new self(
            id: $ratePlan->id,
            name: $ratePlan->name,
            bookableOnline: $ratePlan->bookable_online,
            roomTypeNames: $ratePlan->roomTypes->whereNull('archived_at')->sortBy(['position', 'id'])->pluck('name')->values()->all(),
        );
    }
}

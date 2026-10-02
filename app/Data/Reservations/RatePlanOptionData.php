<?php

namespace App\Data\Reservations;

use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/**
 * A rate plan to pick in the Add unit Sheet, with the room types it is sold for. Expects `roomTypes` to be loaded.
 */
class RatePlanOptionData extends Data
{
    /**
     * @param  list<int>  $roomTypeIds
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $roomTypeIds,
    ) {}

    public static function fromModel(RatePlan $ratePlan): self
    {
        return new self($ratePlan->id, $ratePlan->name, $ratePlan->roomTypes->pluck('id')->map(intval(...))->values()->all());
    }
}

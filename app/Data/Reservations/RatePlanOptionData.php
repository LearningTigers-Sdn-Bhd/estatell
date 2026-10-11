<?php

namespace App\Data\Reservations;

use App\Enums\PricingBasis;
use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/**
 * A rate plan to pick in the Add unit Sheet, with the room types it is sold for. `fixedNights` is set for a package, which sells a fixed stay length. A Day use plan has `isDayUse` and its hours range and Sold between window (`H:i`), and sells only day use lines; the other plans sell only overnight lines. Expects `roomTypes` to be loaded.
 */
class RatePlanOptionData extends Data
{
    /**
     * @param  list<int>  $roomTypeIds
     * @param  list<int>  $companyIds  The companies the plan is linked to. Empty means open to everyone.
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $roomTypeIds,
        public ?int $fixedNights,
        public array $companyIds = [],
        public bool $isDayUse = false,
        public ?int $dayUseMinHours = null,
        public ?int $dayUseMaxHours = null,
        public ?string $dayUseFrom = null,
        public ?string $dayUseUntil = null,
    ) {}

    public static function fromModel(RatePlan $ratePlan): self
    {
        return new self(
            $ratePlan->id,
            $ratePlan->name,
            $ratePlan->roomTypes->pluck('id')->map(intval(...))->values()->all(),
            $ratePlan->pricing_basis === PricingBasis::Package ? $ratePlan->fixed_nights : null,
            $ratePlan->companies->pluck('id')->map(intval(...))->values()->all(),
            $ratePlan->pricing_basis === PricingBasis::DayUse,
            $ratePlan->day_use_min_hours,
            $ratePlan->day_use_max_hours,
            $ratePlan->day_use_from === null ? null : substr($ratePlan->day_use_from, 0, 5),
            $ratePlan->day_use_until === null ? null : substr($ratePlan->day_use_until, 0, 5),
        );
    }
}

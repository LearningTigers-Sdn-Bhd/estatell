<?php

namespace App\Data\Rates;

use App\Enums\PricingBasis;
use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/** One plan row under a room type in the rate calendar. Only dates with a price or a restriction have a cell. */
class RateCalendarPlanData extends Data
{
    /**
     * @param  string|null  $followsName  The parent plan name when this plan is derived.
     * @param  list<RateCalendarCellData>  $cells
     */
    public function __construct(
        public int $id,
        public string $name,
        public PricingBasis $pricingBasis,
        public bool $derived,
        public ?string $followsName,
        public array $cells,
    ) {}

    /**
     * Load the plan's `parent` first when it is derived.
     *
     * @param  list<RateCalendarCellData>  $cells
     */
    public static function fromModel(RatePlan $ratePlan, array $cells): self
    {
        return new self(
            id: $ratePlan->id,
            name: $ratePlan->name,
            pricingBasis: $ratePlan->pricing_basis,
            derived: $ratePlan->parent_rate_plan_id !== null,
            followsName: $ratePlan->parent?->name,
            cells: $cells,
        );
    }
}

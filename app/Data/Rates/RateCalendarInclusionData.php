<?php

namespace App\Data\Rates;

use App\Models\RatePlanInclusion;
use Spatie\LaravelData\Data;

/** One inclusion of a plan as the rate calendar popover lists it: the charge item, the quantity and the charging unit. */
class RateCalendarInclusionData extends Data
{
    public function __construct(
        public string $name,
        public int $quantity,
        public string $chargingUnit,
    ) {}

    /**
     * Load the inclusion's `chargeItem` first.
     */
    public static function fromModel(RatePlanInclusion $inclusion): self
    {
        return new self(
            name: $inclusion->chargeItem->name,
            quantity: $inclusion->quantity,
            chargingUnit: $inclusion->charging_unit->label(),
        );
    }
}

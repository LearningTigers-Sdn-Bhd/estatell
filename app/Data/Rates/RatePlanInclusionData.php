<?php

namespace App\Data\Rates;

use App\Enums\ChargingUnit;
use App\Enums\InclusionEnd;
use App\Enums\InclusionStart;
use App\Models\RatePlanInclusion;
use Spatie\LaravelData\Data;

/** One row of the plan editor's Inclusions tab. */
class RatePlanInclusionData extends Data
{
    public function __construct(
        public int $id,
        public int $chargeItemId,
        public ?int $mealPeriodId,
        public ChargingUnit $chargingUnit,
        public int $quantity,
        public string $allocatedAmount,
        public InclusionStart $startsOn,
        public InclusionEnd $endsOn,
    ) {}

    public static function fromModel(RatePlanInclusion $inclusion): self
    {
        return new self(
            id: $inclusion->id,
            chargeItemId: $inclusion->charge_item_id,
            mealPeriodId: $inclusion->meal_period_id,
            chargingUnit: $inclusion->charging_unit,
            quantity: $inclusion->quantity,
            allocatedAmount: $inclusion->allocated_amount,
            startsOn: $inclusion->starts_on,
            endsOn: $inclusion->ends_on,
        );
    }
}

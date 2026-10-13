<?php

namespace App\Services\Rates;

use App\Models\RatePlanInclusion;

/**
 * Whether a rate plan includes a charge item. Reads only. Any inclusion row counts, whatever its amount or dates, because
 * the inclusion means the price already covers the item. Other modules call this instead of the Rates models.
 */
class RatePlanIncludes
{
    public function includesChargeItem(int $ratePlanId, int $chargeItemId): bool
    {
        return RatePlanInclusion::query()
            ->where('rate_plan_id', $ratePlanId)
            ->where('charge_item_id', $chargeItemId)
            ->exists();
    }
}

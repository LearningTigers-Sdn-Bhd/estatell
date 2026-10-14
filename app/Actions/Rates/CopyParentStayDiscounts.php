<?php

namespace App\Actions\Rates;

use App\Models\RatePlan;
use App\Models\RatePlanStayDiscount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CopyParentStayDiscounts
{
    public function __construct(private SaveRatePlanStayDiscounts $saveRatePlanStayDiscounts) {}

    /**
     * Save a copy of the parent's long-stay discounts on a derived plan that has none. The copy is not linked to the parent.
     *
     * Returns false for an archived plan, a plan that follows no plan, a plan that already has discounts,
     * or a parent with none.
     */
    public function __invoke(User $actor, RatePlan $ratePlan): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan): bool {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null || $ratePlan->parent_rate_plan_id === null || $ratePlan->stayDiscounts()->exists()) {
                return false;
            }

            $rules = array_values($ratePlan->parent->stayDiscounts()->orderBy('min_nights')->get()
                ->map(fn (RatePlanStayDiscount $discount): array => [
                    'min_nights' => $discount->min_nights,
                    'discount_type' => $discount->discount_type->value,
                    'value' => $discount->value,
                    'from_night' => $discount->from_night,
                ])
                ->all());

            return $rules !== [] && ($this->saveRatePlanStayDiscounts)($actor, $ratePlan, $rules);
        });
    }
}

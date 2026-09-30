<?php

namespace App\Actions\Rates;

use App\Enums\PricingBasis;
use App\Enums\StayDiscountType;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveRatePlanStayDiscounts
{
    /**
     * Replace the plan's long-stay discounts with the given list. Rows are matched by `min_nights`.
     *
     * Returns false for an archived plan or a Package plan. A save that changes nothing is not logged.
     *
     * @param  list<array{min_nights: int, discount_type: string, value: string|int|float, from_night: int}>  $discounts
     */
    public function __invoke(User $actor, RatePlan $ratePlan, array $discounts): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan, $discounts): bool {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null || $ratePlan->pricing_basis === PricingBasis::Package) {
                return false;
            }

            $existing = $ratePlan->stayDiscounts()->get()->keyBy('min_nights');
            $changed = false;

            foreach ($discounts as $discount) {
                $attributes = [
                    'discount_type' => StayDiscountType::from($discount['discount_type']),
                    'value' => number_format((float) $discount['value'], 2, '.', ''),
                    'from_night' => $discount['from_night'],
                ];
                $row = $existing->get($discount['min_nights']);

                if ($row === null) {
                    $ratePlan->stayDiscounts()->create(['min_nights' => $discount['min_nights'], ...$attributes]);
                    $changed = true;
                } elseif (
                    $row->discount_type !== $attributes['discount_type']
                    || $row->value !== $attributes['value']
                    || $row->from_night !== $attributes['from_night']
                ) {
                    $row->update($attributes);
                    $changed = true;
                }
            }

            $removed = $existing->keys()->diff(array_column($discounts, 'min_nights'));

            if ($removed->isNotEmpty()) {
                $ratePlan->stayDiscounts()->whereIn('min_nights', $removed->all())->delete();
                $changed = true;
            }

            if ($changed) {
                activity()
                    ->performedOn($ratePlan)
                    ->causedBy($actor)
                    ->event('rate_plan.stay_discounts_updated')
                    ->withProperties(['property_id' => $ratePlan->property_id])
                    ->log('Rate plan long-stay discounts updated');
            }

            return true;
        });
    }
}

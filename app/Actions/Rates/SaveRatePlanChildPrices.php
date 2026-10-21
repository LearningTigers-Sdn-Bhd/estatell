<?php

namespace App\Actions\Rates;

use App\Enums\AgeBandPricing;
use App\Events\RatesChanged;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveRatePlanChildPrices
{
    /**
     * Save one child price per age group of the property, and clear the "Check child prices" flag.
     *
     * Returns false for an archived plan, a plan that does not price children, or a list that is not exactly the
     * property's current age groups. A save that changes no price is not logged.
     *
     * @param  list<array{property_age_group_id: int, pricing: string, value: string|int|float|null}>  $prices
     */
    public function __invoke(User $actor, RatePlan $ratePlan, array $prices): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan, $prices): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($ratePlan->property_id);
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null || ! $ratePlan->pricing_basis->usesChildPrices($ratePlan->package_unit)) {
                return false;
            }

            $groupIds = $property->ageGroups()->pluck('id')->all();
            $wantedIds = array_column($prices, 'property_age_group_id');

            if (count($wantedIds) !== count($groupIds) || array_diff($wantedIds, $groupIds) !== []) {
                return false;
            }

            $existing = $ratePlan->childPrices()->get()->keyBy('property_age_group_id');
            $changed = false;

            foreach ($prices as $price) {
                $pricing = AgeBandPricing::from($price['pricing']);
                $value = $pricing === AgeBandPricing::Free ? null : number_format((float) $price['value'], 2, '.', '');
                $row = $existing->get($price['property_age_group_id']);

                if ($row === null) {
                    $ratePlan->childPrices()->create([
                        'property_age_group_id' => $price['property_age_group_id'],
                        'pricing' => $pricing,
                        'value' => $value,
                    ]);
                    $changed = true;
                } elseif ($row->pricing !== $pricing || $row->value !== $value) {
                    $row->update(['pricing' => $pricing, 'value' => $value]);
                    $changed = true;
                }
            }

            if ($ratePlan->needs_child_price_review) {
                $ratePlan->forceFill(['needs_child_price_review' => false])->save();
            }

            if ($changed) {
                activity()
                    ->performedOn($ratePlan)
                    ->causedBy($actor)
                    ->event('rate_plan.child_prices_updated')
                    ->withProperties(['property_id' => $ratePlan->property_id])
                    ->log('Rate plan child prices updated');
            }

            event(RatesChanged::forPlan($ratePlan));

            return true;
        });
    }
}

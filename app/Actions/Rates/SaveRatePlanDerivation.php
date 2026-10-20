<?php

namespace App\Actions\Rates;

use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Events\RatesChanged;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RateOccupancyPrice;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Rates\DerivedRatePlans;
use Illuminate\Support\Facades\DB;

class SaveRatePlanDerivation
{
    public function __construct(private DerivedRatePlans $derivedRatePlans) {}

    /**
     * Make an active plan follow a parent plan, or stop following with a null parent.
     *
     * Following clears the plan's own prices and keeps its restrictions, because it takes the parent's price per date.
     * Stopping leaves the plan with no prices until staff set them.
     *
     * Returns false for an archived plan, a parent that is not an eligible parent, a percent of -100 or lower, or an amount on a package.
     * A save that changes nothing is not logged.
     */
    public function __invoke(User $actor, RatePlan $ratePlan, ?int $parentId, ?RateDerivation $derivation = null, ?string $value = null): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan, $parentId, $derivation, $value): bool {
            Property::query()->lockForUpdate()->findOrFail($ratePlan->property_id);
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null) {
                return false;
            }

            if ($parentId === null) {
                return $this->stopFollowing($actor, $ratePlan);
            }

            if ($ratePlan->pricing_basis === PricingBasis::DayUse) {
                return false;
            }

            if ($derivation === null || $value === null || ($derivation === RateDerivation::Percent && (float) $value <= -100) || ($ratePlan->pricing_basis === PricingBasis::Package && $derivation !== RateDerivation::Percent)) {
                return false;
            }

            $parent = $this->derivedRatePlans->eligibleParents($ratePlan)->firstWhere('id', $parentId);

            if ($parent === null) {
                return false;
            }

            $value = number_format((float) $value, 2, '.', '');
            $ratePlan->fill(['parent_rate_plan_id' => $parent->id, 'derivation' => $derivation, 'derivation_value' => $value]);
            $changes = $ratePlan->getDirty();

            if ($changes === []) {
                return true;
            }

            $ratePlan->save();
            $cleared = $this->clearOwnPrices($ratePlan);

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.derivation_saved')
                ->withProperties([
                    'property_id' => $ratePlan->property_id,
                    'parent_rate_plan_id' => $parent->id,
                    'derivation' => $derivation->value,
                    'derivation_value' => $value,
                    'own_prices_cleared' => $cleared,
                ])
                ->log('Rate plan follows another plan');

            event(RatesChanged::forPlan($ratePlan));

            return true;
        });
    }

    private function stopFollowing(User $actor, RatePlan $ratePlan): bool
    {
        if ($ratePlan->parent_rate_plan_id === null) {
            return true;
        }

        $parentId = $ratePlan->parent_rate_plan_id;
        $ratePlan->forceFill(['parent_rate_plan_id' => null, 'derivation' => null, 'derivation_value' => null])->save();

        activity()
            ->performedOn($ratePlan)
            ->causedBy($actor)
            ->event('rate_plan.derivation_removed')
            ->withProperties(['property_id' => $ratePlan->property_id, 'parent_rate_plan_id' => $parentId])
            ->log('Rate plan stopped following another plan');

        event(RatesChanged::forPlan($ratePlan));

        return true;
    }

    /**
     * Removes the plan's own amounts, occupancy prices and package prices. Restriction columns and arrival weekdays stay.
     */
    private function clearOwnPrices(RatePlan $ratePlan): int
    {
        if ($ratePlan->pricing_basis === PricingBasis::Package) {
            return $ratePlan->packagePrices()->delete();
        }

        $rateIds = Rate::query()->where('rate_plan_id', $ratePlan->id)->whereNotNull('amount')->pluck('id');

        RateOccupancyPrice::query()->whereIn('rate_id', Rate::query()->where('rate_plan_id', $ratePlan->id)->select('id'))->delete();
        Rate::query()->whereIn('id', $rateIds)->update(['amount' => null]);

        return $rateIds->count();
    }
}

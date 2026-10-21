<?php

namespace App\Actions\Rates;

use App\Enums\AgeBandPricing;
use App\Enums\PricingBasis;
use App\Enums\PropertyStatus;
use App\Events\RatesChanged;
use App\Models\Property;
use App\Models\Rate;
use App\Models\RatePlan;
use App\Models\User;
use App\Services\Rates\DerivedRatePlans;
use Illuminate\Support\Facades\DB;

class ChangeRatePlanBasis
{
    public function __construct(private DerivedRatePlans $derivedRatePlans) {}

    /**
     * Switch a nightly plan between Per room and Per guest while the property is still in setup. After go-live the basis
     * is fixed. The plan's prices are cleared, because an amount means something else on the other basis. A plan that
     * leaves Per guest loses its child prices, and a plan that enters it gets the starter ones (youngest group free,
     * every other group 50%), as a new Per guest plan does.
     *
     * Returns false for an archived plan, a property that is not onboarding, a plan on a basis other than Per room or
     * Per guest, a plan that follows another plan or is followed, or the new basis being neither. Choosing the current
     * basis changes nothing and returns true.
     */
    public function __invoke(User $actor, RatePlan $ratePlan, PricingBasis $basis): bool
    {
        return DB::transaction(function () use ($actor, $ratePlan, $basis): bool {
            $property = Property::query()->lockForUpdate()->findOrFail($ratePlan->property_id);
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);
            $allowed = [PricingBasis::PerRoom, PricingBasis::PerGuest];

            if (
                $property->status !== PropertyStatus::Onboarding
                || $ratePlan->archived_at !== null
                || ! in_array($ratePlan->pricing_basis, $allowed, true)
                || ! in_array($basis, $allowed, true)
                || $ratePlan->parent_rate_plan_id !== null
                || $this->derivedRatePlans->followers($ratePlan)->isNotEmpty()
            ) {
                return false;
            }

            if ($ratePlan->pricing_basis === $basis) {
                return true;
            }

            $from = $ratePlan->pricing_basis;

            Rate::query()->where('rate_plan_id', $ratePlan->id)->delete();
            $ratePlan->forceFill(['pricing_basis' => $basis])->save();

            if ($basis === PricingBasis::PerGuest) {
                foreach ($property->ageGroups()->ordered()->get() as $index => $ageGroup) {
                    $ratePlan->childPrices()->create($index === 0
                        ? ['property_age_group_id' => $ageGroup->id, 'pricing' => AgeBandPricing::Free, 'value' => null]
                        : ['property_age_group_id' => $ageGroup->id, 'pricing' => AgeBandPricing::Percent, 'value' => '50.00']);
                }
            } else {
                $ratePlan->childPrices()->delete();
            }

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.basis_changed')
                ->withProperties(['property_id' => $property->id, 'from' => $from->value, 'to' => $basis->value])
                ->log('Rate plan basis changed');

            event(RatesChanged::forPlan($ratePlan));

            return true;
        });
    }
}

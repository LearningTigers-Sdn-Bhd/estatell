<?php

namespace App\Services\Property;

use App\Data\Property\CountryChangeCountsData;
use App\Enums\OnboardingStep;
use App\Enums\PaymentMethodType;
use App\Enums\StayDiscountType;
use App\Models\OnboardingSkip;
use App\Models\Property;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Counts what a country change after setup started will remove or clear. Reads only: the Change country dialog shows
 * these counts, and the activity log keeps them.
 */
class CountryChangeCounts
{
    /**
     * The onboarding sections whose data the reset clears, so their skips go too ("We don't charge tax" must not survive
     * a switch to a country with taxes).
     *
     * @return list<OnboardingStep>
     */
    public static function resetSteps(): array
    {
        return [
            OnboardingStep::CheckInEssentials,
            OnboardingStep::Taxes,
            OnboardingStep::PaymentMethods,
            OnboardingStep::BasicRate,
            OnboardingStep::ChargeItems,
            OnboardingStep::Discounts,
        ];
    }

    public function forProperty(Property $property): CountryChangeCountsData
    {
        $planIds = self::planIds($property);
        $rateIds = DB::table('rates')->whereIn('rate_plan_id', $planIds)->select('id');

        $prices = DB::table('rates')->whereIn('rate_plan_id', $planIds)->whereNotNull('amount')->count()
            + DB::table('rate_occupancy_prices')->whereIn('rate_id', $rateIds)->count()
            + DB::table('rate_hour_prices')->whereIn('rate_id', $rateIds)->count()
            + DB::table('package_prices')->whereIn('rate_plan_id', $planIds)->count()
            + DB::table('rate_plan_child_prices')->whereIn('rate_plan_id', $planIds)->whereNotNull('value')->count()
            + DB::table('rate_plan_stay_discounts')->whereIn('rate_plan_id', $planIds)
                ->where('discount_type', StayDiscountType::Amount->value)->count()
            + DB::table('rate_plans')->where('property_id', $property->id)
                ->where(fn (Builder $query) => $query->where('extra_adult_amount', '!=', 0)
                    ->orWhere('extra_child_amount', '!=', 0)
                    ->orWhere('single_supplement', '!=', 0))
                ->count();

        return new CountryChangeCountsData(
            taxes: $property->taxes()->count(),
            paymentMethods: $property->paymentMethods()->where('type', '!=', PaymentMethodType::OldSystem->value)->count(),
            chargeItems: $property->chargeItems()->count(),
            discounts: $property->discounts()->count(),
            prices: $prices,
            inclusions: DB::table('rate_plan_inclusions')->whereIn('rate_plan_id', $planIds)->count(),
            termsDocuments: $property->termsDocuments()->count(),
            skippedSteps: OnboardingSkip::query()
                ->where('property_id', $property->id)
                ->whereIn('step', array_map(fn (OnboardingStep $step): string => $step->value, self::resetSteps()))
                ->count(),
        );
    }

    /**
     * The ids of the property's rate plans, as a subquery.
     */
    public static function planIds(Property $property): Builder
    {
        return DB::table('rate_plans')->where('property_id', $property->id)->select('id');
    }
}

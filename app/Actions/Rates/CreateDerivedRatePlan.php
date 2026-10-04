<?php

namespace App\Actions\Rates;

use App\Enums\CompanyStatus;
use App\Enums\PricingBasis;
use App\Enums\RateDerivation;
use App\Models\Company;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateDerivedRatePlan
{
    public function __construct(
        private CreateRatePlan $createRatePlan,
        private SaveRatePlanDerivation $saveRatePlanDerivation,
    ) {}

    /**
     * Create a plan that follows the parent, sell it on the chosen room types and link it to the chosen companies.
     * See docs/08-rates/04-derived-rate-plans.md.
     *
     * Returns null, without writing, when the parent is archived, a Package or already a follower, when a room type
     * is not an active room type of the parent, when a company is not an Active company of the property, or when
     * the derivation is refused.
     *
     * @param  string  $name  Already checked to be free among the property's active plans.
     * @param  string  $signedValue  Negative for Down, positive for Up.
     * @param  list<int>  $roomTypeIds
     * @param  list<int>  $companyIds
     */
    public function __invoke(User $actor, RatePlan $parent, string $name, RateDerivation $derivation, string $signedValue, array $roomTypeIds, array $companyIds = []): ?RatePlan
    {
        DB::beginTransaction();

        try {
            $property = Property::query()->lockForUpdate()->findOrFail($parent->property_id);
            $parent = RatePlan::query()->with('roomTypes')->lockForUpdate()->findOrFail($parent->id);
            $companies = Company::query()->where('property_id', $property->id)->where('status', CompanyStatus::Active)->whereIn('id', $companyIds)->get();
            $parentRoomTypeIds = $parent->roomTypes->whereNull('archived_at')->pluck('id')->all();

            $refused = $parent->archived_at !== null
                || $parent->parent_rate_plan_id !== null
                || $parent->pricing_basis === PricingBasis::Package
                || $roomTypeIds === []
                || array_diff($roomTypeIds, $parentRoomTypeIds) !== []
                || $companies->count() !== count(array_unique($companyIds));

            $ratePlan = $refused ? null : ($this->createRatePlan)($property, $actor, ['name' => $name, 'pricing_basis' => $parent->pricing_basis->value], $roomTypeIds);

            if ($ratePlan === null || ! ($this->saveRatePlanDerivation)($actor, $ratePlan, $parent->id, $derivation, $signedValue)) {
                DB::rollBack();

                return null;
            }

            foreach ($companies as $company) {
                $company->ratePlans()->attach($ratePlan->id);

                activity()
                    ->performedOn($company)
                    ->causedBy($actor)
                    ->event('company.rates_updated')
                    ->withProperties(['property_id' => $property->id, 'added' => [$ratePlan->id], 'removed' => []])
                    ->log('Company rate plans updated');
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $ratePlan->refresh();
    }
}

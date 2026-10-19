<?php

namespace App\Actions\Rates;

use App\Enums\CompanyAccess;
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
        private SetRatePlanCompanyAccess $setRatePlanCompanyAccess,
        private AddRatePlanCompanies $addRatePlanCompanies,
    ) {}

    /**
     * Create a plan that follows the parent, sell it on the chosen room types and make it Only these companies for the chosen companies (Everyone when none are chosen).
     * See docs/08-rates/04-derived-rate-plans.md.
     *
     * A package parent gives a package. Its nights and charged unit are copied, and only a percent is allowed.
     *
     * Returns null, without writing, when the parent is archived or already a follower, when an amount follows a package,
     * when a room type is not an active room type of the parent, when a company is not an Active company of the property, or when
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
                || $parent->pricing_basis === PricingBasis::DayUse
                || $parent->parent_rate_plan_id !== null
                || ($parent->pricing_basis === PricingBasis::Package && $derivation !== RateDerivation::Percent)
                || $roomTypeIds === []
                || array_diff($roomTypeIds, $parentRoomTypeIds) !== []
                || $companies->count() !== count(array_unique($companyIds));

            $ratePlan = $refused ? null : ($this->createRatePlan)($property, $actor, $this->attributes($parent, $name), $roomTypeIds);

            if ($ratePlan === null || ! ($this->saveRatePlanDerivation)($actor, $ratePlan, $parent->id, $derivation, $signedValue)) {
                DB::rollBack();

                return null;
            }

            if ($companies->isNotEmpty()) {
                $refusal = ($this->setRatePlanCompanyAccess)($actor, $ratePlan, CompanyAccess::Selected)
                    ?? ($this->addRatePlanCompanies)($actor, $ratePlan, $companies->pluck('id')->all());

                if ($refusal !== null) {
                    DB::rollBack();

                    return null;
                }
            }

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return $ratePlan->refresh();
    }

    /**
     * @return array{name: string, pricing_basis: string, fixed_nights?: int|null, package_unit?: string}
     */
    private function attributes(RatePlan $parent, string $name): array
    {
        $attributes = ['name' => $name, 'pricing_basis' => $parent->pricing_basis->value];

        return $parent->pricing_basis === PricingBasis::Package
            ? [...$attributes, 'fixed_nights' => $parent->fixed_nights, 'package_unit' => $parent->package_unit->value]
            : $attributes;
    }
}

<?php

namespace App\Services\Portal;

use App\Enums\CompanyAccess;
use App\Enums\CompanyRateMarket;
use App\Models\Company;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which rate plans a company can see in the portal: active nightly plans (Day use is never offered) that are All
 * companies with Portal on, or Only these companies with a row for this company that has Portal on, and whose market is
 * open to the company's market. It reads only.
 * See docs/23-company-portal/01-company-portal.md#rules.
 */
class PortalRatePlans
{
    /**
     * @return Builder<RatePlan>
     */
    public function visibleTo(Company $company): Builder
    {
        return RatePlan::query()
            ->where('rate_plans.property_id', $company->property_id)
            ->active()
            ->nightly()
            ->whereIn('company_market', CompanyRateMarket::valuesOfferedTo($company->market))
            ->where(fn (Builder $plans) => $plans
                ->where(fn (Builder $open) => $open
                    ->where('company_access', CompanyAccess::All->value)
                    ->where('company_in_portal', true))
                ->orWhere(fn (Builder $selected) => $selected
                    ->where('company_access', CompanyAccess::Selected->value)
                    ->whereHas('companies', fn (Builder $links) => $links
                        ->where('companies.id', $company->id)
                        ->where('company_rate_plans.in_portal', true))));
    }

    /**
     * The visible plans in display order, each with its active room types and its inclusions, for the book page.
     *
     * @return Builder<RatePlan>
     */
    public function bookablePlansFor(Company $company): Builder
    {
        return $this->visibleTo($company)
            ->with(['roomTypes' => fn ($roomTypes) => $roomTypes->active()->ordered(), 'inclusions.chargeItem'])
            ->ordered();
    }

    /**
     * The active room types that at least one visible plan sells, in display order.
     *
     * @return Builder<RoomType>
     */
    public function roomTypesFor(Company $company): Builder
    {
        return RoomType::query()
            ->where('property_id', $company->property_id)
            ->active()
            ->whereIn('id', DB::table('rate_plan_room_types')->whereIn('rate_plan_id', $this->visibleTo($company)->select('rate_plans.id'))->select('room_type_id'))
            ->ordered();
    }

    /**
     * Whether a plan is visible to the company.
     */
    public function isVisible(Company $company, int $ratePlanId): bool
    {
        return $this->visibleTo($company)->whereKey($ratePlanId)->exists();
    }
}

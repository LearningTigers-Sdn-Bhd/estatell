<?php

namespace App\Services\Rates;

use App\Data\CityLedger\CompanyRateData;
use App\Data\Rates\RatePlanData;
use App\Enums\CompanyAccess;
use App\Enums\CompanyRateMarket;
use App\Models\Company;
use App\Models\RatePlan;
use Illuminate\Database\Eloquent\Builder;

/**
 * The active rate plans a company can use, for its read-only Rates tab: the plans that list it (Only these companies)
 * and the All companies plans, left out when the plan's market does not match the company's, with where each applies. It reads only. See docs/15-city-ledger/01-companies.md.
 */
class CompanyRatePlans
{
    /**
     * @return list<CompanyRateData>
     */
    public function forCompany(Company $company): array
    {
        $plans = RatePlan::query()
            ->where('property_id', $company->property_id)
            ->active()
            ->whereIn('company_market', CompanyRateMarket::valuesOfferedTo($company->market))
            ->where(fn (Builder $query) => $query
                ->where('company_access', CompanyAccess::All->value)
                ->orWhere(fn (Builder $selected) => $selected
                    ->where('company_access', CompanyAccess::Selected->value)
                    ->whereHas('companies', fn (Builder $rows) => $rows->where('companies.id', $company->id))))
            ->with(['roomTypes', 'parent', 'companies' => fn ($rows) => $rows->where('companies.id', $company->id)])
            ->ordered()
            ->get();

        return array_values($plans->map(function (RatePlan $plan): CompanyRateData {
            $row = $plan->companies->first()?->pivot;
            $listed = $plan->company_access === CompanyAccess::Selected;

            return CompanyRateData::fromModel(
                $plan,
                RatePlanData::fromModel($plan)->pricingLabel,
                $listed ? (bool) $row?->at_desk : $plan->company_at_desk,
                $listed ? (bool) $row?->in_portal : $plan->company_in_portal,
            );
        })->all());
    }
}

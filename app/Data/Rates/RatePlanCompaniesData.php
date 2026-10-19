<?php

namespace App\Data\Rates;

use App\Enums\CompanyAccess;
use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/**
 * The values of the plan editor's Companies tab: who can use the rate, the Front desk and Portal choice for All
 * companies, and the companies listed for Only these companies.
 */
class RatePlanCompaniesData extends Data
{
    /**
     * @param  list<RatePlanCompanyRowData>  $companies
     */
    public function __construct(
        public CompanyAccess $companyAccess,
        public bool $isDerived,
        public bool $atDesk,
        public bool $inPortal,
        public array $companies,
    ) {}

    /**
     * Load the plan's `companies.portalLink.user` first.
     */
    public static function fromModel(RatePlan $ratePlan): self
    {
        return new self(
            companyAccess: $ratePlan->company_access,
            isDerived: $ratePlan->parent_rate_plan_id !== null,
            atDesk: $ratePlan->company_at_desk,
            inPortal: $ratePlan->company_in_portal,
            companies: array_values($ratePlan->companies->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->map(RatePlanCompanyRowData::fromModel(...))->all()),
        );
    }
}

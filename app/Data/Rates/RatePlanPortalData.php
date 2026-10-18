<?php

namespace App\Data\Rates;

use App\Enums\PortalAccess;
use App\Models\RatePlan;
use Spatie\LaravelData\Data;

/**
 * The values of the plan editor's Portal tab: who can book the plan in the company portal, and the companies linked to
 * the plan (read only here; links are made on each company's Rates tab).
 */
class RatePlanPortalData extends Data
{
    /**
     * @param  list<RatePlanPortalCompanyData>  $companies
     */
    public function __construct(
        public PortalAccess $portalAccess,
        public bool $isDerived,
        public array $companies,
    ) {}

    /**
     * Load the plan's `companies` first.
     */
    public static function fromModel(RatePlan $ratePlan): self
    {
        return new self(
            portalAccess: $ratePlan->portal_access,
            isDerived: $ratePlan->parent_rate_plan_id !== null,
            companies: array_values($ratePlan->companies->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->map(fn ($company): RatePlanPortalCompanyData => new RatePlanPortalCompanyData($company->id, $company->name))->all()),
        );
    }
}

<?php

namespace App\Services\Portal;

use App\Enums\PortalAccess;
use App\Models\Company;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which rate plans a company can see in the portal: active nightly plans (Day use is never offered) with portal
 * access All and no link to another company, or Selected and linked to this company. It reads only. See
 * docs/23-company-portal/01-company-portal.md#rules.
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
            ->where(fn (Builder $plans) => $plans
                ->where(fn (Builder $open) => $open
                    ->where('portal_access', PortalAccess::All->value)
                    ->whereDoesntHave('companies', fn (Builder $links) => $links->where('companies.id', '<>', $company->id)))
                ->orWhere(fn (Builder $selected) => $selected
                    ->where('portal_access', PortalAccess::Selected->value)
                    ->whereHas('companies', fn (Builder $links) => $links->where('companies.id', $company->id))));
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

    /**
     * Whether saving `$wantedPlanIds` as the company's plans would take the last company away from a Selected plan.
     * Only the plans the company loses count.
     *
     * @param  list<int>  $wantedPlanIds
     */
    public function wouldEmptySelectedPlan(Company $company, array $wantedPlanIds): bool
    {
        return RatePlan::query()
            ->where('property_id', $company->property_id)
            ->active()
            ->where('portal_access', PortalAccess::Selected->value)
            ->whereHas('companies', fn (Builder $links) => $links->where('companies.id', $company->id))
            ->whereNotIn('rate_plans.id', $wantedPlanIds)
            ->whereDoesntHave('companies', fn (Builder $links) => $links->where('companies.id', '<>', $company->id))
            ->exists();
    }
}

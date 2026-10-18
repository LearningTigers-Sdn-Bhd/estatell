<?php

namespace App\Actions\Rates;

use App\Enums\PortalAccess;
use App\Enums\PricingBasis;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateRatePlanPortalAccess
{
    public const string REFUSAL_PLAN_CLOSED = 'plan_closed';

    public const string REFUSAL_NOT_DERIVED = 'not_derived';

    public const string REFUSAL_NEEDS_COMPANY = 'needs_company';

    /**
     * Set who can book the plan in the company portal. Returns null when saved, otherwise the refusal code and
     * nothing is written: an archived plan or a Day use plan (front desk only) cannot be shown in the portal, Selected
     * is only for derived plans (the only plans with company links), and Selected needs at least one linked company.
     * A save that changes nothing is not logged. See docs/08-rates/01-rate-plans.md.
     */
    public function __invoke(User $actor, RatePlan $ratePlan, PortalAccess $access): ?string
    {
        return DB::transaction(function () use ($actor, $ratePlan, $access): ?string {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null || $ratePlan->pricing_basis === PricingBasis::DayUse) {
                return self::REFUSAL_PLAN_CLOSED;
            }

            if ($access === PortalAccess::Selected && $ratePlan->parent_rate_plan_id === null) {
                return self::REFUSAL_NOT_DERIVED;
            }

            if ($access === PortalAccess::Selected && ! $ratePlan->companies()->exists()) {
                return self::REFUSAL_NEEDS_COMPANY;
            }

            $before = $ratePlan->portal_access;

            if ($before === $access) {
                return null;
            }

            $ratePlan->portal_access = $access;
            $ratePlan->save();

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.portal_access_updated')
                ->withProperties(['property_id' => $ratePlan->property_id, 'from' => $before->value, 'to' => $access->value])
                ->log('Rate plan portal access updated');

            return null;
        });
    }
}

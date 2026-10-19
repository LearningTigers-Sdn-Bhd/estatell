<?php

namespace App\Actions\Rates;

use App\Models\Company;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveRatePlanCompany
{
    public const string REFUSAL_PLAN_CLOSED = 'plan_closed';

    public const string REFUSAL_NOT_LISTED = 'not_listed';

    /**
     * Take a company off the plan. The last company can go too: the plan then has no company that can use it.
     * Bookings already made keep their plan and price. Returns null when removed, otherwise the refusal code and
     * nothing is written. See docs/08-rates/01-rate-plans.md#who-can-use-this-rate.
     */
    public function __invoke(User $actor, RatePlan $ratePlan, Company $company): ?string
    {
        return DB::transaction(function () use ($actor, $ratePlan, $company): ?string {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null) {
                return self::REFUSAL_PLAN_CLOSED;
            }

            if ($ratePlan->companies()->detach($company->id) === 0) {
                return self::REFUSAL_NOT_LISTED;
            }

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.company_removed')
                ->withProperties(['property_id' => $ratePlan->property_id, 'company_id' => $company->id])
                ->log('Company removed from rate plan');

            return null;
        });
    }
}

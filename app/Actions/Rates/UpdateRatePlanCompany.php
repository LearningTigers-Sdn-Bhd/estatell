<?php

namespace App\Actions\Rates;

use App\Models\Company;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateRatePlanCompany
{
    public const string REFUSAL_PLAN_CLOSED = 'plan_closed';

    public const string REFUSAL_NOT_LISTED = 'not_listed';

    public const string REFUSAL_NOTHING_ON = 'nothing_on';

    /**
     * Save the Front desk and Portal switches of one listed company. Returns null when saved, otherwise the refusal
     * code and nothing is written: an archived plan, a company that is not listed, or both switches off (the company
     * is removed instead). A save that changes nothing is not logged. See
     * docs/08-rates/01-rate-plans.md#who-can-use-this-rate.
     */
    public function __invoke(User $actor, RatePlan $ratePlan, Company $company, bool $atDesk, bool $inPortal): ?string
    {
        return DB::transaction(function () use ($actor, $ratePlan, $company, $atDesk, $inPortal): ?string {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null) {
                return self::REFUSAL_PLAN_CLOSED;
            }

            $row = DB::table('company_rate_plans')->where('rate_plan_id', $ratePlan->id)->where('company_id', $company->id)->lockForUpdate()->first();

            if ($row === null) {
                return self::REFUSAL_NOT_LISTED;
            }

            if (! $atDesk && ! $inPortal) {
                return self::REFUSAL_NOTHING_ON;
            }

            if ((bool) $row->at_desk === $atDesk && (bool) $row->in_portal === $inPortal) {
                return null;
            }

            $ratePlan->companies()->updateExistingPivot($company->id, ['at_desk' => $atDesk, 'in_portal' => $inPortal]);

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.company_updated')
                ->withProperties([
                    'property_id' => $ratePlan->property_id,
                    'company_id' => $company->id,
                    'from' => ['at_desk' => (bool) $row->at_desk, 'in_portal' => (bool) $row->in_portal],
                    'to' => ['at_desk' => $atDesk, 'in_portal' => $inPortal],
                ])
                ->log('Rate plan company updated');

            return null;
        });
    }
}

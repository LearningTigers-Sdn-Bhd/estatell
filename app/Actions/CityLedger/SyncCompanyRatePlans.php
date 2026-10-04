<?php

namespace App\Actions\CityLedger;

use App\Models\Company;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SyncCompanyRatePlans
{
    /**
     * Replace the derived plans linked to the company. Every id must be an active derived plan of the property,
     * otherwise nothing is written and false comes back. Links to archived plans are left alone, so restoring a plan
     * brings its link back. Nothing is logged when nothing changed. See docs/15-city-ledger/01-companies.md.
     *
     * @param  list<int>  $ratePlanIds
     */
    public function __invoke(User $actor, Company $company, array $ratePlanIds): bool
    {
        $wanted = array_values(array_unique($ratePlanIds));

        return DB::transaction(function () use ($actor, $company, $wanted): bool {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);

            $managed = RatePlan::query()
                ->where('property_id', $company->property_id)
                ->active()
                ->whereNotNull('parent_rate_plan_id')
                ->pluck('id')
                ->all();

            if (array_diff($wanted, $managed) !== []) {
                return false;
            }

            $current = $company->ratePlans()->pluck('rate_plans.id')->all();
            $added = array_values(array_diff($wanted, $current));
            $removed = array_values(array_intersect(array_diff($current, $wanted), $managed));

            if ($added === [] && $removed === []) {
                return true;
            }

            $company->ratePlans()->attach($added);
            $company->ratePlans()->detach($removed);

            activity()
                ->performedOn($company)
                ->causedBy($actor)
                ->event('company.rates_updated')
                ->withProperties(['property_id' => $company->property_id, 'added' => $added, 'removed' => $removed])
                ->log('Company rate plans updated');

            return true;
        });
    }
}

<?php

namespace App\Actions\Rates;

use App\Enums\CompanyAccess;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AddRatePlanCompanies
{
    public const string REFUSAL_PLAN_CLOSED = 'plan_closed';

    public const string REFUSAL_NOT_SELECTED = 'not_selected';

    public const string REFUSAL_COMPANY_UNAVAILABLE = 'company_unavailable';

    /**
     * Add companies to a plan that is Only these companies, with Front desk and Portal both on. Companies already
     * listed are left as they are. Returns null when done, otherwise the refusal code and nothing is written: an
     * archived plan, a plan that is not Only these companies, or an id that is not an Active company of the property.
     * Nothing is logged when no company is new. See docs/08-rates/01-rate-plans.md#who-can-use-this-rate.
     *
     * @param  list<int>  $companyIds
     */
    public function __invoke(User $actor, RatePlan $ratePlan, array $companyIds): ?string
    {
        $wanted = array_values(array_unique($companyIds));

        return DB::transaction(function () use ($actor, $ratePlan, $wanted): ?string {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null) {
                return self::REFUSAL_PLAN_CLOSED;
            }

            if ($ratePlan->company_access !== CompanyAccess::Selected) {
                return self::REFUSAL_NOT_SELECTED;
            }

            $active = Company::query()
                ->where('property_id', $ratePlan->property_id)
                ->where('status', CompanyStatus::Active)
                ->whereIn('id', $wanted)
                ->pluck('id')
                ->all();

            if (count($active) !== count($wanted)) {
                return self::REFUSAL_COMPANY_UNAVAILABLE;
            }

            $added = array_values(array_diff($wanted, $ratePlan->companies()->pluck('companies.id')->all()));

            if ($added === []) {
                return null;
            }

            $ratePlan->companies()->attach(array_fill_keys($added, ['at_desk' => true, 'in_portal' => true]));

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.companies_added')
                ->withProperties(['property_id' => $ratePlan->property_id, 'company_ids' => $added])
                ->log('Companies added to rate plan');

            return null;
        });
    }
}

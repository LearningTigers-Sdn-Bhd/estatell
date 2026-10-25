<?php

namespace App\Actions\Rates;

use App\Enums\CompanyAccess;
use App\Enums\CompanyRateMarket;
use App\Enums\PricingBasis;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SetRatePlanCompanyAccess
{
    public const string REFUSAL_PLAN_CLOSED = 'plan_closed';

    public const string REFUSAL_NOTHING_ON = 'nothing_on';

    /**
     * Set who can use the plan. For All companies the Front desk and Portal switches are saved too; the other choices
     * leave them alone. Returns null when saved, otherwise the refusal code and nothing is written: an archived plan or
     * a Day use plan (front desk only) cannot change, and All companies needs at least one switch on. Listed companies are kept when the choice moves away from Only these companies.
     * The market (null keeps the current one) limits the plan to local or international companies; Everyone always
     * resets it to all. A save that changes nothing is not logged. See docs/08-rates/01-rate-plans.md#who-can-use-this-rate.
     */
    public function __invoke(User $actor, RatePlan $ratePlan, CompanyAccess $access, bool $atDesk = true, bool $inPortal = true, ?CompanyRateMarket $market = null): ?string
    {
        return DB::transaction(function () use ($actor, $ratePlan, $access, $atDesk, $inPortal, $market): ?string {
            $ratePlan = RatePlan::query()->lockForUpdate()->findOrFail($ratePlan->id);

            if ($ratePlan->archived_at !== null || $ratePlan->pricing_basis === PricingBasis::DayUse) {
                return self::REFUSAL_PLAN_CLOSED;
            }

            if ($access === CompanyAccess::All && ! $atDesk && ! $inPortal) {
                return self::REFUSAL_NOTHING_ON;
            }

            $before = ['access' => $ratePlan->company_access->value, 'at_desk' => $ratePlan->company_at_desk, 'in_portal' => $ratePlan->company_in_portal, 'market' => $ratePlan->company_market->value];

            $ratePlan->company_access = $access;
            $ratePlan->company_market = $access === CompanyAccess::Everyone ? CompanyRateMarket::All : ($market ?? $ratePlan->company_market);

            if ($access === CompanyAccess::All) {
                $ratePlan->company_at_desk = $atDesk;
                $ratePlan->company_in_portal = $inPortal;
            }

            if (! $ratePlan->isDirty()) {
                return null;
            }

            $ratePlan->save();

            activity()
                ->performedOn($ratePlan)
                ->causedBy($actor)
                ->event('rate_plan.company_access_updated')
                ->withProperties([
                    'property_id' => $ratePlan->property_id,
                    'from' => $before,
                    'to' => ['access' => $access->value, 'at_desk' => $ratePlan->company_at_desk, 'in_portal' => $ratePlan->company_in_portal, 'market' => $ratePlan->company_market->value],
                ])
                ->log('Rate plan company access updated');

            return null;
        });
    }
}

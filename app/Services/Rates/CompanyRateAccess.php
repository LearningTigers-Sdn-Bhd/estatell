<?php

namespace App\Services\Rates;

use App\Enums\CompanyAccess;
use App\Enums\CompanyMarket;
use App\Enums\CompanyRateMarket;
use App\Models\RatePlan;
use Illuminate\Support\Facades\DB;

/**
 * Whether a company (or no company) can use a rate plan, from the plan's "Who can use this rate" and Market settings.
 * It reads only. See docs/08-rates/01-rate-plans.md#who-can-use-this-rate.
 */
class CompanyRateAccess
{
    /**
     * Whether a booking with this company (null for none) may use the plan at the front desk: Everyone plans always,
     * All companies plans with Front desk on once a company is picked, Only these companies plans when the company is
     * listed with Front desk on. A plan limited to a market also needs the company's market to match.
     */
    public function deskAllows(RatePlan $ratePlan, ?int $companyId): bool
    {
        return match ($ratePlan->company_access) {
            CompanyAccess::Everyone => true,
            CompanyAccess::All => $companyId !== null && $ratePlan->company_at_desk && $this->marketAllows($ratePlan, $companyId),
            CompanyAccess::Selected => $companyId !== null && $this->listed($ratePlan, $companyId, 'at_desk') && $this->marketAllows($ratePlan, $companyId),
        };
    }

    /**
     * Whether the company (null for none) is one the plan is open to, at the desk or in the portal. Used to keep a
     * booking's units valid when its company changes.
     */
    public function usableBy(RatePlan $ratePlan, ?int $companyId): bool
    {
        return match ($ratePlan->company_access) {
            CompanyAccess::Everyone => true,
            CompanyAccess::All => $companyId !== null && $this->marketAllows($ratePlan, $companyId),
            CompanyAccess::Selected => $companyId !== null && $this->listed($ratePlan, $companyId) && $this->marketAllows($ratePlan, $companyId),
        };
    }

    /**
     * Whether the plan's market is open to the company's market. Plans open to every market skip the lookup.
     */
    private function marketAllows(RatePlan $ratePlan, int $companyId): bool
    {
        if ($ratePlan->company_market === CompanyRateMarket::All) {
            return true;
        }

        $market = DB::table('companies')->where('id', $companyId)->value('market');

        return $ratePlan->company_market->offersTo($market === null ? null : CompanyMarket::from($market));
    }

    private function listed(RatePlan $ratePlan, int $companyId, ?string $switch = null): bool
    {
        return DB::table('company_rate_plans')
            ->where('rate_plan_id', $ratePlan->id)
            ->where('company_id', $companyId)
            ->when($switch !== null, fn ($rows) => $rows->where($switch, true))
            ->exists();
    }
}

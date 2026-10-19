<?php

namespace App\Services\Rates;

use App\Enums\CompanyAccess;
use App\Models\RatePlan;
use Illuminate\Support\Facades\DB;

/**
 * Whether a company (or no company) can use a rate plan, from the plan's "Who can use this rate" setting. It reads
 * only. See docs/08-rates/01-rate-plans.md#who-can-use-this-rate.
 */
class CompanyRateAccess
{
    /**
     * Whether a booking with this company (null for none) may use the plan at the front desk: Everyone plans always,
     * All companies plans with Front desk on once a company is picked, Only these companies plans when the company is
     * listed with Front desk on.
     */
    public function deskAllows(RatePlan $ratePlan, ?int $companyId): bool
    {
        return match ($ratePlan->company_access) {
            CompanyAccess::Everyone => true,
            CompanyAccess::All => $companyId !== null && $ratePlan->company_at_desk,
            CompanyAccess::Selected => $companyId !== null && $this->listed($ratePlan, $companyId, 'at_desk'),
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
            CompanyAccess::All => $companyId !== null,
            CompanyAccess::Selected => $companyId !== null && $this->listed($ratePlan, $companyId),
        };
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

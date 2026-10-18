<?php

namespace App\Services\CityLedger;

use App\Data\CityLedger\CompanyPortalData;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanyUser;

/**
 * Reads a company's portal login for the Details tab.
 */
class CompanyPortalState
{
    public function for(Company $company): CompanyPortalData
    {
        $link = $company->companyUsers()->open()->with('user')->first();

        $linkedElsewhere = $link instanceof CompanyUser && CompanyUser::query()->active()
            ->where('user_id', $link->user_id)
            ->where('property_id', '<>', $company->property_id)
            ->exists();

        return CompanyPortalData::fromLink($link, $company->portal_cancel_cutoff_days, $linkedElsewhere, $company->status === CompanyStatus::Suspended);
    }
}

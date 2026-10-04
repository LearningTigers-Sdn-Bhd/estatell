<?php

namespace App\Actions\CityLedger;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReactivateCompany
{
    /**
     * Make a suspended company Active again. Returns false when it is already active.
     */
    public function __invoke(User $actor, Company $company): bool
    {
        return DB::transaction(function () use ($actor, $company): bool {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $previousStatus = $company->status;

            if (! $previousStatus->canTransitionTo(CompanyStatus::Active)) {
                return false;
            }

            $company->update(['status' => CompanyStatus::Active]);

            activity()
                ->performedOn($company)
                ->causedBy($actor)
                ->event('company.reactivated')
                ->withProperties(['property_id' => $company->property_id, 'from' => $previousStatus->value, 'to' => CompanyStatus::Active->value])
                ->log('Company reactivated');

            return true;
        });
    }
}

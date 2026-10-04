<?php

namespace App\Actions\CityLedger;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SuspendCompany
{
    /**
     * Suspend the company, so it cannot be picked on new bookings. Returns false when it is already suspended.
     */
    public function __invoke(User $actor, Company $company): bool
    {
        return DB::transaction(function () use ($actor, $company): bool {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $previousStatus = $company->status;

            if (! $previousStatus->canTransitionTo(CompanyStatus::Suspended)) {
                return false;
            }

            $company->update(['status' => CompanyStatus::Suspended]);

            activity()
                ->performedOn($company)
                ->causedBy($actor)
                ->event('company.suspended')
                ->withProperties(['property_id' => $company->property_id, 'from' => $previousStatus->value, 'to' => CompanyStatus::Suspended->value])
                ->log('Company suspended');

            return true;
        });
    }
}

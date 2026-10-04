<?php

namespace App\Actions\CityLedger;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateCompanyBilling
{
    public function __construct(private UpdateCompany $updateCompany) {}

    /**
     * Save the tax numbers and billing address as hotel staff. Returns false, without saving, when the company manages
     * them itself in the company portal.
     *
     * @param  array<string, mixed>  $attributes  Validated tax and billing address fields.
     */
    public function __invoke(User $actor, Company $company, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $company, $attributes): bool {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);

            if ($company->profile_managed_by_company) {
                return false;
            }

            ($this->updateCompany)($actor, $company, $attributes);

            return true;
        });
    }
}

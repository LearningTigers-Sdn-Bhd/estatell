<?php

namespace App\Actions\CityLedger;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCompany
{
    public function __construct(private SetCompanyPortalLogin $setPortalLogin) {}

    /**
     * Create an Active company of the property. A `portal_email` key sets the company's portal login in the same
     * transaction. A refused email throws a validation error on `portal_email` and nothing is saved.
     *
     * @param  array<string, mixed>  $attributes  Validated company fields.
     */
    public function __invoke(User $actor, Property $property, array $attributes): Company
    {
        $portalEmail = Arr::pull($attributes, 'portal_email');

        return DB::transaction(function () use ($actor, $property, $attributes, $portalEmail): Company {
            $company = $property->companies()->create([...$attributes, 'status' => CompanyStatus::Active]);

            activity()
                ->performedOn($company)
                ->causedBy($actor)
                ->event('company.created')
                ->withProperties(['property_id' => $property->id])
                ->log('Company created');

            if (filled($portalEmail)) {
                $result = ($this->setPortalLogin)($actor, $company, $portalEmail);

                if ($result->isRefused()) {
                    throw ValidationException::withMessages(['portal_email' => $result->refusal->message()]);
                }
            }

            return $company;
        });
    }
}

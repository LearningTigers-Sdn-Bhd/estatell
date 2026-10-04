<?php

namespace App\Actions\CityLedger;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateCompany
{
    /**
     * Create an Active company of the property.
     *
     * @param  array<string, mixed>  $attributes  Validated company fields.
     */
    public function __invoke(User $actor, Property $property, array $attributes): Company
    {
        return DB::transaction(function () use ($actor, $property, $attributes): Company {
            $company = $property->companies()->create([...$attributes, 'status' => CompanyStatus::Active]);

            activity()
                ->performedOn($company)
                ->causedBy($actor)
                ->event('company.created')
                ->withProperties(['property_id' => $property->id])
                ->log('Company created');

            return $company;
        });
    }
}

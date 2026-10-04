<?php

namespace App\Actions\CityLedger;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateCompany
{
    /**
     * Save the company's fields. The log keeps the old and new value of each changed field. Nothing is logged when
     * nothing changed.
     *
     * @param  array<string, mixed>  $attributes  Validated company fields.
     */
    public function __invoke(User $actor, Company $company, array $attributes): void
    {
        DB::transaction(function () use ($actor, $company, $attributes): void {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $company->fill($attributes);

            $changed = array_keys($company->getDirty());

            if ($changed === []) {
                return;
            }

            $old = array_intersect_key($company->getRawOriginal(), array_flip($changed));
            $company->save();

            activity()
                ->performedOn($company)
                ->causedBy($actor)
                ->event('company.updated')
                ->withProperties([
                    'property_id' => $company->property_id,
                    'old' => $old,
                    'attributes' => array_intersect_key($company->getAttributes(), array_flip($changed)),
                ])
                ->log('Company updated');
        });
    }
}

<?php

namespace App\Actions\CityLedger;

use App\Enums\CompanyStatus;
use App\Enums\PortalLoginRefusal;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCompany
{
    public function __construct(private SetCompanyPortalLogin $setPortalLogin) {}

    /**
     * Save the company's fields. The log keeps the old and new value of each changed field. Nothing is logged when
     * nothing changed. A `portal_email` key (empty clears it) sets the company's portal login in the same transaction.
     * A refused email, or a portal change on a Suspended company, throws a validation error and nothing is saved.
     *
     * @param  array<string, mixed>  $attributes  Validated company fields.
     */
    public function __invoke(User $actor, Company $company, array $attributes): void
    {
        $hasPortalEmail = array_key_exists('portal_email', $attributes);
        $portalEmail = Arr::pull($attributes, 'portal_email');

        DB::transaction(function () use ($actor, $company, $attributes, $hasPortalEmail, $portalEmail): void {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $company->fill($attributes);

            if ($company->status === CompanyStatus::Suspended && $company->isDirty('portal_cancel_cutoff_days')) {
                throw ValidationException::withMessages(['portal_cancel_cutoff_days' => PortalLoginRefusal::CompanySuspended->message()]);
            }

            $changed = array_keys($company->getDirty());

            if ($changed !== []) {
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
            }

            if (! $hasPortalEmail) {
                return;
            }

            $result = ($this->setPortalLogin)($actor, $company, $portalEmail);

            if ($result->isRefused()) {
                throw ValidationException::withMessages(['portal_email' => $result->refusal->message()]);
            }
        });
    }
}

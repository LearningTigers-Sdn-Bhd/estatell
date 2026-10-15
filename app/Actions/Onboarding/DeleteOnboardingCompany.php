<?php

namespace App\Actions\Onboarding;

use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DeleteOnboardingCompany
{
    /** The two Postgres codes for a delete that something still refers to: restrict and foreign key violation. */
    private const REFERENCED_CODES = ['23001', '23503'];

    /**
     * Remove a company, with its rate plan links, while the property is not live. Returns false, and writes nothing,
     * after go-live (a company is suspended then) or when a reservation, folio, invoice or payment already refers to it.
     */
    public function __invoke(User $actor, Company $company): bool
    {
        if (Property::query()->whereKey($company->property_id)->whereNotNull('went_live_at')->exists()) {
            return false;
        }

        try {
            DB::transaction(function () use ($actor, $company): void {
                $company->delete();

                activity()->causedBy($actor)->event('company.deleted')
                    ->withProperties(['property_id' => $company->property_id, 'name' => $company->name])
                    ->log('Company deleted');
            });
        } catch (QueryException $exception) {
            if (! in_array((string) $exception->getCode(), self::REFERENCED_CODES, true)) {
                throw $exception;
            }

            return false;
        }

        return true;
    }
}

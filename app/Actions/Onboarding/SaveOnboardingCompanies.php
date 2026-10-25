<?php

namespace App\Actions\Onboarding;

use App\Actions\CityLedger\CreateCompany;
use App\Actions\CityLedger\EnableCityLedger;
use App\Actions\CityLedger\UpdateCompany;
use App\Enums\OnboardingStep;
use App\Models\Company;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveOnboardingCompanies
{
    public function __construct(
        private CreateCompany $createCompany,
        private UpdateCompany $updateCompany,
        private EnableCityLedger $enableCityLedger,
        private UnskipOnboardingStep $unskipStep,
    ) {}

    /**
     * Save the Companies table in one transaction. A row with an id updates that Active company, a row without one
     * creates an Active company. Saved companies without a row are left alone: companies are never deleted. A company
     * that manages its own profile in the portal keeps its tax numbers. When any row has Direct bill ticked and City
     * ledger is off, City ledger is turned on. Saving at least one row removes a Skip.
     *
     * Returns true when City ledger was turned on.
     *
     * @param  list<array<string, mixed>>  $rows  Validated company fields per row, plus `id` (int|null).
     */
    public function __invoke(Property $property, User $actor, array $rows): bool
    {
        return DB::transaction(function () use ($property, $actor, $rows): bool {
            Property::query()->lockForUpdate()->findOrFail($property->id);

            $saved = $property->companies()->get()->keyBy('id');

            foreach ($rows as $row) {
                $id = $row['id'];
                unset($row['id']);

                if ($id === null) {
                    ($this->createCompany)($actor, $property, $row);

                    continue;
                }

                $company = $saved->findOrFail($id);

                if ($company->profile_managed_by_company) {
                    unset($row['brn'], $row['tin'], $row['sst_registration_number']);
                }

                ($this->updateCompany)($actor, $company, $row);
            }

            if ($rows !== []) {
                ($this->unskipStep)($property, OnboardingStep::Companies);
            }

            return array_any($rows, fn (array $row): bool => $row['direct_bill_enabled'])
                && ($this->enableCityLedger)($property, $actor);
        });
    }
}

<?php

namespace App\Actions\Onboarding;

use App\Actions\Catalogues\UpdatePaymentMethod;
use App\Actions\CityLedger\CreateCompany;
use App\Actions\CityLedger\UpdateCompany;
use App\Enums\OnboardingStep;
use App\Enums\PaymentMethodType;
use App\Models\Company;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveOnboardingCompanies
{
    public function __construct(
        private CreateCompany $createCompany,
        private UpdateCompany $updateCompany,
        private UpdatePaymentMethod $updatePaymentMethod,
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
                && $this->turnOnCityLedger($property, $actor);
        });
    }

    private function turnOnCityLedger(Property $property, User $actor): bool
    {
        $cityLedger = $property->paymentMethods()
            ->where('type', PaymentMethodType::DirectBill)
            ->with('transactionCode')
            ->first();

        if (! $cityLedger instanceof PaymentMethod || $cityLedger->active) {
            return false;
        }

        return ($this->updatePaymentMethod)($actor, $cityLedger, [
            'name' => $cityLedger->name,
            'code' => $cityLedger->transactionCode->code,
            'surcharge_type' => $cityLedger->surcharge_type->value,
            'surcharge_value' => $cityLedger->surcharge_value,
            'surcharge_charge_item_id' => $cityLedger->surcharge_charge_item_id,
            'requires_reference' => $cityLedger->requires_reference,
            'active' => true,
        ]);
    }
}

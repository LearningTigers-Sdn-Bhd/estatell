<?php

namespace App\Services\Catalogues;

use App\Models\Property;
use App\Models\PropertyBankAccount;

/**
 * The property's available bank accounts as the lines a customer document prints, in their order:
 * "Maybank · Sand Bay Resort Sdn Bhd · 5123 4567 8901 · SWIFT MBBEMYKL". See docs/06-catalogues/07-bank-accounts.md.
 */
class BankAccountLines
{
    /** @return list<string> */
    public function for(Property $property): array
    {
        return array_values($property->bankAccounts()
            ->active()
            ->ordered()
            ->get()
            ->map(fn (PropertyBankAccount $account): string => implode(' · ', array_filter([
                $account->bank_name,
                $account->account_name,
                $account->account_number,
                $account->swift_code === null ? null : __('SWIFT :code', ['code' => $account->swift_code]),
            ])))
            ->all());
    }
}

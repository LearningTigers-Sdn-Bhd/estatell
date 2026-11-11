<?php

namespace App\Actions\Catalogues;

use App\Models\Property;
use App\Models\PropertyBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBankAccount
{
    /**
     * Add a bank account at the end of the property's available list. Logs `bank_account.created`.
     *
     * @param  array{bank_name: string, account_name: string, account_number: string, swift_code: ?string, active: bool}  $attributes
     */
    public function __invoke(Property $property, User $actor, array $attributes): PropertyBankAccount
    {
        return DB::transaction(function () use ($property, $actor, $attributes): PropertyBankAccount {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            $bankAccount = new PropertyBankAccount($attributes);
            $bankAccount->property_id = $property->id;
            $bankAccount->position = ((int) $property->bankAccounts()->active()->max('position')) + 1;
            $bankAccount->save();

            activity()
                ->performedOn($bankAccount)
                ->causedBy($actor)
                ->event('bank_account.created')
                ->withProperties(['property_id' => $property->id, 'bank_name' => $bankAccount->bank_name])
                ->log('Bank account created');

            return $bankAccount;
        });
    }
}

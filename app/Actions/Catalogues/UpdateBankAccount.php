<?php

namespace App\Actions\Catalogues;

use App\Models\PropertyBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateBankAccount
{
    /**
     * Save a bank account, including Available. An account turned on again goes to the end of the available list.
     * Logs `bank_account.updated` with the changed fields, and nothing when nothing changed.
     *
     * @param  array{bank_name: string, account_name: string, account_number: string, swift_code: ?string, active: bool}  $attributes
     */
    public function __invoke(User $actor, PropertyBankAccount $bankAccount, array $attributes): void
    {
        DB::transaction(function () use ($actor, $bankAccount, $attributes): void {
            $bankAccount = PropertyBankAccount::query()->lockForUpdate()->findOrFail($bankAccount->id);

            if (! $bankAccount->active && $attributes['active']) {
                $attributes['position'] = ((int) PropertyBankAccount::query()
                    ->where('property_id', $bankAccount->property_id)
                    ->active()
                    ->max('position')) + 1;
            }

            $bankAccount->fill($attributes);
            $changes = array_keys($bankAccount->getDirty());

            if ($changes === []) {
                return;
            }

            $bankAccount->save();

            activity()
                ->performedOn($bankAccount)
                ->causedBy($actor)
                ->event('bank_account.updated')
                ->withProperties(['property_id' => $bankAccount->property_id, 'changed' => $changes])
                ->log('Bank account updated');
        });
    }
}

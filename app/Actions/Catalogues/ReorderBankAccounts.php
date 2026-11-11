<?php

namespace App\Actions\Catalogues;

use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReorderBankAccounts
{
    /**
     * Set the order of the property's available bank accounts. Logs `bank_account.reordered`.
     *
     * Returns false unless the ids are exactly the available accounts.
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Property $property, User $actor, array $orderedIds): bool
    {
        return DB::transaction(function () use ($property, $actor, $orderedIds): bool {
            $active = $property->bankAccounts()->active()->lockForUpdate()->pluck('id')->all();

            if (count($orderedIds) !== count($active) || array_diff($orderedIds, $active) !== [] || array_diff($active, $orderedIds) !== []) {
                return false;
            }

            foreach ($orderedIds as $index => $id) {
                $property->bankAccounts()->whereKey($id)->update(['position' => $index + 1]);
            }

            activity()
                ->causedBy($actor)
                ->event('bank_account.reordered')
                ->withProperties(['property_id' => $property->id, 'order' => $orderedIds])
                ->log('Bank accounts reordered');

            return true;
        });
    }
}

<?php

namespace App\Actions\Accounting;

use App\Models\Property;
use App\Models\User;

/**
 * Set the accounting account code of each transaction code of the property. A blank value clears the mapping. Codes of
 * another property are ignored.
 */
class SaveAccountMappings
{
    /**
     * @param  array<int, string|null>  $accounts  account code by transaction code id
     */
    public function __invoke(Property $property, User $actor, array $accounts): void
    {
        $codes = $property->transactionCodes()->whereIn('id', array_keys($accounts))->get();

        foreach ($codes as $code) {
            $account = $accounts[$code->id] ?? null;
            $account = filled($account) ? trim((string) $account) : null;

            if ($code->gl_account_code !== $account) {
                $code->forceFill(['gl_account_code' => $account])->save();
            }
        }

        activity()
            ->performedOn($property)
            ->causedBy($actor)
            ->event('accounting.mappings-saved')
            ->withProperties(['property_id' => $property->id])
            ->log('Accounting account mappings saved');
    }
}

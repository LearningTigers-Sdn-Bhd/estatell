<?php

namespace App\Actions\Onboarding;

use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DeleteOnboardingTax
{
    private const FOREIGN_KEY_VIOLATION = '23503';

    /**
     * Remove a custom tax, its links and its posting code. Returns false, and writes nothing, for a preset (it owns a
     * system posting code and is turned off instead) or when something already refers to the tax or its posting code.
     */
    public function __invoke(User $actor, Tax $tax): bool
    {
        $tax->loadMissing('transactionCode');

        if ($tax->transactionCode->system_key !== null) {
            return false;
        }

        try {
            DB::transaction(function () use ($actor, $tax): void {
                $tax->applicableTransactionCodes()->detach();
                $tax->delete();
                $tax->transactionCode->delete();

                activity()->causedBy($actor)->event('tax.deleted')
                    ->withProperties(['property_id' => $tax->property_id, 'name' => $tax->name])
                    ->log('Tax deleted');
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() !== self::FOREIGN_KEY_VIOLATION) {
                throw $exception;
            }

            return false;
        }

        return true;
    }
}

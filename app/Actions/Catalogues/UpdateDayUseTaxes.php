<?php

namespace App\Actions\Catalogues;

use App\Enums\SystemTransactionCode;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateDayUseTaxes
{
    /**
     * Link the chosen taxes to the DAY_USE code. It never follows the Taxes tab.
     * Returns false, without writing, when the property has no DAY_USE code.
     *
     * @param  list<int>  $taxIds  Validated tax ids from the property.
     */
    public function __invoke(Property $property, User $actor, array $taxIds): bool
    {
        return DB::transaction(function () use ($property, $actor, $taxIds): bool {
            $dayUse = $property->transactionCodes()
                ->where('system_key', SystemTransactionCode::DayUseRevenue->value)
                ->with('applicableTaxes')
                ->lockForUpdate()
                ->first();

            if ($dayUse === null) {
                return false;
            }

            sort($taxIds);
            $current = $dayUse->applicableTaxes->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

            if ($current === array_values($taxIds)) {
                return true;
            }

            $dayUse->applicableTaxes()->sync($taxIds);

            activity()
                ->causedBy($actor)
                ->event('day_use.taxes_updated')
                ->withProperties(['property_id' => $property->id, 'tax_ids' => $taxIds])
                ->log('Day use taxes updated');

            return true;
        });
    }
}

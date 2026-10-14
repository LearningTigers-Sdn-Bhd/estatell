<?php

namespace App\Actions\Catalogues;

use App\Data\Catalogues\TaxInputData;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateTax
{
    /**
     * Save what the tax is. Which charges it applies to is managed on Room revenue and Charge items.
     *
     * @param  TaxInputData  $data  Every field of the tax. Use `TaxInputData::fromModel()` to change only some.
     */
    public function __invoke(User $actor, Tax $tax, TaxInputData $data): bool
    {
        return DB::transaction(function () use ($actor, $tax, $data): bool {
            $tax = Tax::query()->with('transactionCode')->lockForUpdate()->findOrFail($tax->id);

            $tax->fill([
                'name' => $data->name,
                'code' => $data->code,
                'rate_type' => $data->rateType,
                'rate' => $data->rate,
                'is_inclusive' => $data->isInclusive,
                'foreign_guests_only' => $data->foreignGuestsOnly,
                'collect_at_check_in' => $data->collectAtCheckIn,
                'registration_number' => $data->registrationNumber,
                'enabled' => $data->enabled,
            ]);
            $changes = $tax->getDirty();
            $postingCode = $tax->transactionCode;
            $postingCode->name = $tax->name;
            if ($postingCode->system_key === null) {
                $postingCode->code = 'TAX_'.$tax->code;
            }
            $postingCodeChanges = $postingCode->getDirty();

            if ($changes === [] && $postingCodeChanges === []) {
                return true;
            }

            $tax->save();
            $postingCode->save();

            activity()->performedOn($tax)->causedBy($actor)->event('tax.updated')
                ->withProperties([
                    'property_id' => $tax->property_id,
                    'changed' => array_keys($changes),
                ])->log('Tax updated');

            return true;
        });
    }
}

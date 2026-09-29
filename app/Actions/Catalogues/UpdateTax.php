<?php

namespace App\Actions\Catalogues;

use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateTax
{
    /**
     * Save what the tax is. Which charges it applies to is managed on Room revenue and Charge items.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $actor, Tax $tax, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $tax, $attributes): bool {
            $tax = Tax::query()->with('transactionCode')->lockForUpdate()->findOrFail($tax->id);

            $tax->fill($attributes);
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

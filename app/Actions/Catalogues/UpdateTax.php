<?php

namespace App\Actions\Catalogues;

use App\Enums\TransactionKind;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateTax
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(User $actor, Tax $tax, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $tax, $attributes): bool {
            $tax = Tax::query()->with(['transactionCode', 'applicableTransactionCodes'])->lockForUpdate()->findOrFail($tax->id);
            $applicableCodeIds = $attributes['applicable_transaction_code_ids'];
            unset($attributes['applicable_transaction_code_ids']);
            $existingIds = $tax->applicableTransactionCodes->pluck('id')->sort()->values()->all();
            sort($applicableCodeIds);

            $foundIds = TransactionCode::query()
                ->where('property_id', $tax->property_id)
                ->where('kind', TransactionKind::Charge)
                ->where(function ($query) use ($existingIds): void {
                    $query->active()->orWhereKey($existingIds);
                })
                ->whereKey($applicableCodeIds)
                ->lockForUpdate()
                ->pluck('id');
            abort_unless($foundIds->count() === count($applicableCodeIds), 404);

            $tax->fill($attributes);
            $changes = $tax->getDirty();
            $codesChanged = $existingIds !== $applicableCodeIds;
            $postingCode = $tax->transactionCode;
            $postingCode->name = $tax->name;
            if ($postingCode->system_key === null) {
                $postingCode->code = 'TAX_'.$tax->code;
            }
            $postingCodeChanges = $postingCode->getDirty();

            if ($changes === [] && ! $codesChanged && $postingCodeChanges === []) {
                return true;
            }

            $tax->save();
            $postingCode->save();
            $tax->applicableTransactionCodes()->sync($applicableCodeIds);

            activity()->performedOn($tax)->causedBy($actor)->event('tax.updated')
                ->withProperties([
                    'property_id' => $tax->property_id,
                    'changed' => array_values(array_unique([...array_keys($changes), ...($codesChanged ? ['applicable_transaction_code_ids'] : [])])),
                ])->log('Tax updated');

            return true;
        });
    }
}

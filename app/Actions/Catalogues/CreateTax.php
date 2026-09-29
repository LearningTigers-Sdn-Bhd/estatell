<?php

namespace App\Actions\Catalogues;

use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\Tax;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTax
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Property $property, User $actor, array $attributes): Tax
    {
        return DB::transaction(function () use ($property, $actor, $attributes): Tax {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $applicableCodeIds = $attributes['applicable_transaction_code_ids'];
            unset($attributes['applicable_transaction_code_ids']);

            $foundIds = TransactionCode::query()
                ->whereBelongsTo($property)
                ->where('kind', TransactionKind::Charge)
                ->active()
                ->whereKey($applicableCodeIds)
                ->lockForUpdate()
                ->pluck('id');
            abort_unless($foundIds->count() === count($applicableCodeIds), 404);

            $postingCode = $property->transactionCodes()->create([
                'system_key' => null,
                'code' => 'TAX_'.$attributes['code'],
                'name' => $attributes['name'],
                'kind' => TransactionKind::Tax,
                'category' => TransactionCategory::Tax,
                'gl_account_code' => null,
                'is_system' => false,
                'active' => true,
            ]);

            $tax = new Tax($attributes);
            $tax->property_id = $property->id;
            $tax->transaction_code_id = $postingCode->id;
            $tax->save();
            $tax->applicableTransactionCodes()->sync($applicableCodeIds);

            activity()->performedOn($tax)->causedBy($actor)->event('tax.created')
                ->withProperties(['property_id' => $property->id, 'name' => $tax->name])
                ->log('Tax created');

            return $tax;
        });
    }
}

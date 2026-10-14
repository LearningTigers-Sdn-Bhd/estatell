<?php

namespace App\Actions\Catalogues;

use App\Data\Catalogues\TaxInputData;
use App\Enums\TransactionCategory;
use App\Enums\TransactionKind;
use App\Models\Property;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTax
{
    public function __invoke(Property $property, User $actor, TaxInputData $data): Tax
    {
        return DB::transaction(function () use ($property, $actor, $data): Tax {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $postingCode = $property->transactionCodes()->create([
                'system_key' => null,
                'code' => 'TAX_'.$data->code,
                'name' => $data->name,
                'kind' => TransactionKind::Tax,
                'category' => TransactionCategory::Tax,
                'gl_account_code' => null,
                'is_system' => false,
                'active' => true,
            ]);

            $tax = new Tax([
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
            $tax->property_id = $property->id;
            $tax->transaction_code_id = $postingCode->id;
            $tax->save();

            activity()->performedOn($tax)->causedBy($actor)->event('tax.created')
                ->withProperties(['property_id' => $property->id, 'name' => $tax->name])
                ->log('Tax created');

            return $tax;
        });
    }
}

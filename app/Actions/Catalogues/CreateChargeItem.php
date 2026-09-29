<?php

namespace App\Actions\Catalogues;

use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateChargeItem
{
    /**
     * Add a charge item with its own charge code at the end of the property's active list.
     *
     * @param  array<string, mixed>  $attributes  Validated charge-item fields.
     */
    public function __invoke(Property $property, User $actor, array $attributes): ChargeItem
    {
        return DB::transaction(function () use ($property, $actor, $attributes): ChargeItem {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $taxIds = $attributes['tax_ids'];

            if ($attributes['default_amount'] === null) {
                $attributes['allow_override'] = true;
            }

            $transactionCode = $property->transactionCodes()->create([
                'system_key' => null,
                'code' => $attributes['code'],
                'name' => $attributes['name'],
                'kind' => TransactionKind::Charge,
                'category' => $attributes['category'],
                'gl_account_code' => null,
                'is_system' => false,
                'active' => $attributes['active'],
            ]);
            $transactionCode->applicableTaxes()->sync($taxIds);

            unset($attributes['code'], $attributes['category'], $attributes['tax_ids']);

            $chargeItem = new ChargeItem($attributes);
            $chargeItem->property_id = $property->id;
            $chargeItem->transaction_code_id = $transactionCode->id;
            $chargeItem->position = ((int) $property->chargeItems()->active()->max('position')) + 1;
            $chargeItem->save();

            activity()
                ->performedOn($chargeItem)
                ->causedBy($actor)
                ->event('charge_item.created')
                ->withProperties(['property_id' => $property->id, 'name' => $chargeItem->name, 'code' => $transactionCode->code])
                ->log('Charge item created');

            return $chargeItem;
        });
    }
}

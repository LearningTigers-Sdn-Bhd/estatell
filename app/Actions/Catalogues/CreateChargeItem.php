<?php

namespace App\Actions\Catalogues;

use App\Enums\ChargingUnit;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateChargeItem
{
    public function __construct(private SyncChargeItemChildPrices $syncChildPrices) {}

    /**
     * Add a charge item with its own charge code at the end of the property's active list.
     * Missing fields take the new-item defaults: priced when charging, per item, no taxes, available.
     *
     * @param  array<string, mixed>  $attributes  Validated charge-item fields; `name`, `code` and `category` are required.
     */
    public function __invoke(Property $property, User $actor, array $attributes): ChargeItem
    {
        return DB::transaction(function () use ($property, $actor, $attributes): ChargeItem {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $attributes += [
                'description' => null,
                'default_amount' => null,
                'charging_unit' => ChargingUnit::PerItem,
                'allow_override' => true,
                'active' => true,
                'tax_ids' => [],
            ];
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
            ($this->syncChildPrices)($chargeItem, null);

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

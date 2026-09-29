<?php

namespace App\Actions\Catalogues;

use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateChargeItem
{
    /**
     * Add a charge item at the end of the property's active list.
     *
     * @param  array<string, mixed>  $attributes  Validated charge-item fields.
     */
    public function __invoke(Property $property, User $actor, array $attributes): ChargeItem
    {
        return DB::transaction(function () use ($property, $actor, $attributes): ChargeItem {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);

            TransactionCode::query()
                ->whereBelongsTo($property)
                ->where('kind', TransactionKind::Charge)
                ->active()
                ->lockForUpdate()
                ->findOrFail($attributes['transaction_code_id']);

            $chargeItem = new ChargeItem($attributes);
            $chargeItem->property_id = $property->id;
            $chargeItem->position = ((int) $property->chargeItems()->active()->max('position')) + 1;
            $chargeItem->save();

            activity()
                ->performedOn($chargeItem)
                ->causedBy($actor)
                ->event('charge_item.created')
                ->withProperties(['property_id' => $property->id, 'name' => $chargeItem->name])
                ->log('Charge item created');

            return $chargeItem;
        });
    }
}

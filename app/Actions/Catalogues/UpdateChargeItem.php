<?php

namespace App\Actions\Catalogues;

use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UpdateChargeItem
{
    /**
     * Save a charge item. Reactivated items return at the end of the active list.
     *
     * @param  array<string, mixed>  $attributes  Validated charge-item fields.
     */
    public function __invoke(User $actor, ChargeItem $chargeItem, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $chargeItem, $attributes): bool {
            $chargeItem = ChargeItem::query()->lockForUpdate()->findOrFail($chargeItem->id);
            $transactionCodeChanged = $chargeItem->transaction_code_id !== $attributes['transaction_code_id'];

            TransactionCode::query()
                ->where('property_id', $chargeItem->property_id)
                ->where('kind', TransactionKind::Charge)
                ->when($transactionCodeChanged, fn (Builder $query) => $query->active())
                ->lockForUpdate()
                ->findOrFail($attributes['transaction_code_id']);

            if (! $chargeItem->active && $attributes['active']) {
                $attributes['position'] = ((int) ChargeItem::query()
                    ->where('property_id', $chargeItem->property_id)
                    ->active()
                    ->max('position')) + 1;
            }

            $chargeItem->fill($attributes);
            $changes = $chargeItem->getDirty();

            if ($changes === []) {
                return true;
            }

            $chargeItem->save();

            activity()
                ->performedOn($chargeItem)
                ->causedBy($actor)
                ->event('charge_item.updated')
                ->withProperties(['property_id' => $chargeItem->property_id, 'changed' => array_keys($changes)])
                ->log('Charge item updated');

            return true;
        });
    }
}

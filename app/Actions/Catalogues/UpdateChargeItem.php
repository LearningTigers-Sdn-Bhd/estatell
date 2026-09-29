<?php

namespace App\Actions\Catalogues;

use App\Models\ChargeItem;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateChargeItem
{
    /**
     * Save a charge item and its own charge code. Reactivated items return at the end of the active list.
     * Starter items keep the code and category of their system transaction code.
     *
     * @param  array<string, mixed>  $attributes  Validated charge-item fields.
     */
    public function __invoke(User $actor, ChargeItem $chargeItem, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $chargeItem, $attributes): bool {
            $chargeItem = ChargeItem::query()->lockForUpdate()->findOrFail($chargeItem->id);
            $transactionCode = TransactionCode::query()->lockForUpdate()->findOrFail($chargeItem->transaction_code_id);
            $taxIds = collect(is_array($attributes['tax_ids']) ? $attributes['tax_ids'] : [])
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $currentTaxIds = $transactionCode->applicableTaxes()->pluck('taxes.id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

            if ($attributes['default_amount'] === null) {
                $attributes['allow_override'] = true;
            }

            if (! $chargeItem->active && $attributes['active']) {
                $attributes['position'] = ((int) ChargeItem::query()
                    ->where('property_id', $chargeItem->property_id)
                    ->active()
                    ->max('position')) + 1;
            }

            $transactionCode->name = $attributes['name'];
            $transactionCode->active = $attributes['active'];

            if ($transactionCode->system_key === null) {
                $transactionCode->code = $attributes['code'];
                $transactionCode->category = $attributes['category'];
            }

            unset($attributes['code'], $attributes['category'], $attributes['tax_ids']);

            $chargeItem->fill($attributes);
            $changes = array_keys([...$transactionCode->getDirty(), ...$chargeItem->getDirty()]);

            if ($taxIds !== $currentTaxIds) {
                $changes[] = 'tax_ids';
            }

            if ($changes === []) {
                return true;
            }

            $transactionCode->save();
            $chargeItem->save();
            $transactionCode->applicableTaxes()->sync($taxIds);

            activity()
                ->performedOn($chargeItem)
                ->causedBy($actor)
                ->event('charge_item.updated')
                ->withProperties(['property_id' => $chargeItem->property_id, 'changed' => array_values(array_unique($changes))])
                ->log('Charge item updated');

            return true;
        });
    }
}

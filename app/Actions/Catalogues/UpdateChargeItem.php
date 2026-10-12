<?php

namespace App\Actions\Catalogues;

use App\Models\ChargeItem;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateChargeItem
{
    public function __construct(private SyncChargeItemChildPrices $syncChildPrices) {}

    /**
     * Save a charge item and its own charge code. Reactivated items return at the end of the active list.
     * Starter items keep the code and category of their system transaction code.
     *
     * With `child_prices` it also saves the child prices and clears "Check child prices". Without it, they are
     * left as they are, apart from the rows a change of charging unit adds or removes.
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
                $attributes['auto_add'] = false;
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

            $childPrices = $attributes['child_prices'] ?? null;

            unset($attributes['code'], $attributes['category'], $attributes['tax_ids'], $attributes['child_prices']);

            $chargeItem->fill($attributes);

            if ($childPrices !== null) {
                $chargeItem->needs_child_price_review = false;
            }

            $changes = array_keys([...$transactionCode->getDirty(), ...$chargeItem->getDirty()]);

            if ($taxIds !== $currentTaxIds) {
                $changes[] = 'tax_ids';
            }

            if (($this->syncChildPrices)($chargeItem, $childPrices)) {
                $changes[] = 'child_prices';
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

<?php

namespace App\Actions\Catalogues;

use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Discount;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateDiscount
{
    /**
     * Save a discount and its own discount code. Reactivated discounts return at the end of the active list.
     * The starter discount keeps the code of its system transaction code.
     *
     * @param  array<string, mixed>  $attributes  Validated discount fields.
     */
    public function __invoke(User $actor, Discount $discount, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $discount, $attributes): bool {
            $discount = Discount::query()->lockForUpdate()->findOrFail($discount->id);
            $transactionCode = TransactionCode::query()->lockForUpdate()->findOrFail($discount->transaction_code_id);

            if ($attributes['type'] !== DiscountType::Fixed->value) {
                $attributes['allow_override'] = $attributes['type'] === DiscountType::Open->value;
            }

            if ($attributes['type'] === DiscountType::Open->value) {
                $attributes['value'] = null;
            }

            $selectedCodeIds = collect($attributes['scope'] === DiscountScope::Selected->value ? $attributes['transaction_code_ids'] : [])
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $currentCodeIds = $discount->selectedTransactionCodes()
                ->pluck('transaction_codes.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            if (! $discount->active && $attributes['active']) {
                $attributes['position'] = ((int) Discount::query()
                    ->where('property_id', $discount->property_id)
                    ->active()
                    ->max('position')) + 1;
            }

            $transactionCode->name = $attributes['name'];
            $transactionCode->active = $attributes['active'];

            if ($transactionCode->system_key === null) {
                $transactionCode->code = $attributes['code'];
            }

            unset($attributes['code'], $attributes['transaction_code_ids']);

            $discount->fill($attributes);
            $changes = array_keys([...$transactionCode->getDirty(), ...$discount->getDirty()]);

            if ($selectedCodeIds !== $currentCodeIds) {
                $changes[] = 'transaction_code_ids';
            }

            if ($changes === []) {
                return true;
            }

            $transactionCode->save();
            $discount->save();
            $discount->selectedTransactionCodes()->sync($selectedCodeIds);

            activity()
                ->performedOn($discount)
                ->causedBy($actor)
                ->event('discount.updated')
                ->withProperties(['property_id' => $discount->property_id, 'changed' => array_values(array_unique($changes))])
                ->log('Discount updated');

            return true;
        });
    }
}

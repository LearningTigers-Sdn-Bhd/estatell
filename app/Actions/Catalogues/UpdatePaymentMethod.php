<?php

namespace App\Actions\Catalogues;

use App\Enums\SurchargeType;
use App\Models\ChargeItem;
use App\Models\PaymentMethod;
use App\Models\TransactionCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UpdatePaymentMethod
{
    /**
     * Save property-owned payment method settings and keep its payment code in step.
     * The type never changes. Seeded methods also keep their system code.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function __invoke(User $actor, PaymentMethod $paymentMethod, array $attributes): bool
    {
        return DB::transaction(function () use ($actor, $paymentMethod, $attributes): bool {
            $paymentMethod = PaymentMethod::query()->lockForUpdate()->findOrFail($paymentMethod->id);
            $transactionCode = TransactionCode::query()->lockForUpdate()->findOrFail($paymentMethod->transaction_code_id);
            $currentChargeItemId = $paymentMethod->surcharge_charge_item_id;

            if ($attributes['surcharge_type'] === SurchargeType::None->value) {
                $attributes['surcharge_value'] = null;
                $attributes['surcharge_charge_item_id'] = null;
            } else {
                $chargeItem = ChargeItem::query()
                    ->where('property_id', $paymentMethod->property_id)
                    ->whereKey($attributes['surcharge_charge_item_id'])
                    ->where(function (Builder $query) use ($currentChargeItemId): void {
                        $query->active()->when($currentChargeItemId !== null, fn (Builder $query) => $query->orWhereKey($currentChargeItemId));
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $chargeItem instanceof ChargeItem) {
                    return false;
                }
            }

            if (! $paymentMethod->active && $attributes['active']) {
                $attributes['position'] = ((int) PaymentMethod::query()
                    ->where('property_id', $paymentMethod->property_id)
                    ->active()
                    ->max('position')) + 1;
            }

            $paymentMethod->fill(array_diff_key($attributes, ['code' => true]));
            $changes = $paymentMethod->getDirty();
            $transactionCode->name = $paymentMethod->name;
            $transactionCode->active = $paymentMethod->active;

            if ($transactionCode->system_key === null) {
                $transactionCode->code = $attributes['code'];
            }

            $transactionCodeChanges = $transactionCode->getDirty();

            if ($changes === [] && $transactionCodeChanges === []) {
                return true;
            }

            $paymentMethod->save();
            $transactionCode->save();

            activity()
                ->performedOn($paymentMethod)
                ->causedBy($actor)
                ->event('payment_method.updated')
                ->withProperties(['property_id' => $paymentMethod->property_id, 'changed' => array_values(array_unique([...array_keys($changes), ...array_keys($transactionCodeChanges)]))])
                ->log('Payment method updated');

            return true;
        });
    }
}

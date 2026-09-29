<?php

namespace App\Actions\Catalogues;

use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use App\Enums\TransactionKind;
use App\Models\ChargeItem;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatePaymentMethod
{
    /**
     * Add a payment method with its own payment code at the end of the property's active list.
     *
     * Returns null when the chosen surcharge charge item is no longer active.
     *
     * @param  array<string, mixed>  $attributes  Validated payment-method fields.
     */
    public function __invoke(Property $property, User $actor, array $attributes): ?PaymentMethod
    {
        return DB::transaction(function () use ($property, $actor, $attributes): ?PaymentMethod {
            $property = Property::query()->lockForUpdate()->findOrFail($property->id);
            $type = PaymentMethodType::from($attributes['type']);

            if ($attributes['surcharge_type'] === SurchargeType::None->value) {
                $attributes['surcharge_value'] = null;
                $attributes['surcharge_charge_item_id'] = null;
            } else {
                $chargeItemAvailable = ChargeItem::query()
                    ->whereBelongsTo($property)
                    ->active()
                    ->whereKey($attributes['surcharge_charge_item_id'])
                    ->lockForUpdate()
                    ->exists();

                if (! $chargeItemAvailable) {
                    return null;
                }
            }

            $transactionCode = $property->transactionCodes()->create([
                'system_key' => null,
                'code' => $attributes['code'],
                'name' => $attributes['name'],
                'kind' => TransactionKind::Payment,
                'category' => $type->category(),
                'gl_account_code' => null,
                'is_system' => false,
                'active' => $attributes['active'],
            ]);

            unset($attributes['code']);

            $paymentMethod = new PaymentMethod($attributes);
            $paymentMethod->property_id = $property->id;
            $paymentMethod->transaction_code_id = $transactionCode->id;
            $paymentMethod->position = ((int) $property->paymentMethods()->active()->max('position')) + 1;
            $paymentMethod->save();

            activity()
                ->performedOn($paymentMethod)
                ->causedBy($actor)
                ->event('payment_method.created')
                ->withProperties(['property_id' => $property->id, 'name' => $paymentMethod->name, 'code' => $transactionCode->code])
                ->log('Payment method created');

            return $paymentMethod;
        });
    }
}

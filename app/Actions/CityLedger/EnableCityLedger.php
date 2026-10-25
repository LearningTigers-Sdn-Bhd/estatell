<?php

namespace App\Actions\CityLedger;

use App\Actions\Catalogues\UpdatePaymentMethod;
use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use App\Models\Property;
use App\Models\User;

class EnableCityLedger
{
    public function __construct(private UpdatePaymentMethod $updatePaymentMethod) {}

    /**
     * Turn the City ledger payment method on. Returns true when it was off and is now on, and false when the property
     * has no City ledger method or it is already on.
     */
    public function __invoke(Property $property, User $actor): bool
    {
        $cityLedger = $property->paymentMethods()
            ->where('type', PaymentMethodType::DirectBill)
            ->with('transactionCode')
            ->first();

        if (! $cityLedger instanceof PaymentMethod || $cityLedger->active) {
            return false;
        }

        return ($this->updatePaymentMethod)($actor, $cityLedger, [
            'name' => $cityLedger->name,
            'code' => $cityLedger->transactionCode->code,
            'surcharge_type' => $cityLedger->surcharge_type->value,
            'surcharge_value' => $cityLedger->surcharge_value,
            'surcharge_charge_item_id' => $cityLedger->surcharge_charge_item_id,
            'requires_reference' => $cityLedger->requires_reference,
            'active' => true,
        ]);
    }
}

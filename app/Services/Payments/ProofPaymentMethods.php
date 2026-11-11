<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use App\Models\Property;

/**
 * The methods staff record an accepted payment proof or slip with. A proof is a bank transfer, so only the property's
 * active bank transfer methods are offered. A property with none gets every method that can take money by hand, so a
 * proof can still be accepted. See docs/15-city-ledger/06-agent-payment-schedule.md#payment-proof.
 */
class ProofPaymentMethods
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function options(Property $property): array
    {
        $methods = PaymentMethod::query()
            ->where('property_id', $property->id)
            ->active()
            ->whereNotIn('type', [PaymentMethodType::OldSystem->value, PaymentMethodType::DirectBill->value, PaymentMethodType::OtaCollected->value, PaymentMethodType::Online->value])
            ->ordered()
            ->get();
        $bankTransfers = $methods->where('type', PaymentMethodType::BankTransfer);

        return array_values(($bankTransfers->isEmpty() ? $methods : $bankTransfers)
            ->map(fn (PaymentMethod $method): array => ['value' => (string) $method->id, 'label' => $method->name])
            ->values()
            ->all());
    }
}

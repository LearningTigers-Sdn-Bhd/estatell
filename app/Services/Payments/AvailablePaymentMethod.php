<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use App\Enums\TransactionKind;
use App\Models\PaymentMethod;
use App\Models\Reservation;

/**
 * Finds the payment method that staff can take money with on a reservation: an active method of its property. City
 * ledger and OTA collected are not offered, the method's code must be an active payment code, and a surcharge needs
 * an active charge item on an active charge code. `RecordPayment` and the manual folio preview both use it.
 */
class AvailablePaymentMethod
{
    public function find(Reservation $reservation, int $methodId): ?PaymentMethod
    {
        $method = PaymentMethod::query()
            ->where('property_id', $reservation->property_id)
            ->active()
            ->whereNotIn('type', [PaymentMethodType::OtaCollected, PaymentMethodType::DirectBill])
            ->with(['transactionCode', 'surchargeChargeItem.transactionCode'])
            ->find($methodId);

        if ($method === null || ! $method->transactionCode->active || $method->transactionCode->kind !== TransactionKind::Payment) {
            return null;
        }

        if ($method->surcharge_type === SurchargeType::None) {
            return $method;
        }

        $item = $method->surchargeChargeItem;

        return $item !== null && $item->active && $item->transactionCode->active && $item->transactionCode->kind === TransactionKind::Charge ? $method : null;
    }
}

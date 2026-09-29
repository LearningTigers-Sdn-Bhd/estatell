<?php

namespace App\Actions\Catalogues;

use App\Enums\PaymentMethodType;
use App\Enums\SurchargeType;
use App\Enums\SystemTransactionCode;
use App\Models\PaymentMethod;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SeedPaymentMethods
{
    /** Create canonical country presets while preserving property-owned changes. */
    public function __invoke(Property $property, string $countryCode): void
    {
        if ($countryCode !== 'MY') {
            throw new InvalidArgumentException("Payment-method presets are not available for country [{$countryCode}].");
        }

        DB::transaction(function () use ($property): void {
            foreach ($this->presets() as $position => $preset) {
                $transactionCode = $property->transactionCodes()
                    ->where('system_key', $preset['systemCode']->value)
                    ->firstOrFail();

                if ($property->paymentMethods()->whereBelongsTo($transactionCode, 'transactionCode')->exists()) {
                    continue;
                }

                $paymentMethod = new PaymentMethod([
                    'transaction_code_id' => $transactionCode->id,
                    'name' => $transactionCode->name,
                    'type' => $preset['type'],
                    'surcharge_type' => SurchargeType::None,
                    'surcharge_value' => null,
                    'surcharge_charge_item_id' => null,
                    'requires_reference' => $preset['requiresReference'],
                    'position' => $position + 1,
                    'active' => $preset['active'],
                ]);
                $paymentMethod->property_id = $property->id;
                $paymentMethod->save();
            }
        });
    }

    /**
     * @return list<array{systemCode: SystemTransactionCode, type: PaymentMethodType, requiresReference: bool, active: bool}>
     */
    private function presets(): array
    {
        return [
            ['systemCode' => SystemTransactionCode::CashPayment, 'type' => PaymentMethodType::Cash, 'requiresReference' => false, 'active' => true],
            ['systemCode' => SystemTransactionCode::CardPayment, 'type' => PaymentMethodType::Card, 'requiresReference' => true, 'active' => true],
            ['systemCode' => SystemTransactionCode::BankTransferPayment, 'type' => PaymentMethodType::BankTransfer, 'requiresReference' => true, 'active' => true],
            ['systemCode' => SystemTransactionCode::EWalletPayment, 'type' => PaymentMethodType::EWallet, 'requiresReference' => true, 'active' => true],
            ['systemCode' => SystemTransactionCode::OtaCollectedPayment, 'type' => PaymentMethodType::OtaCollected, 'requiresReference' => false, 'active' => false],
            ['systemCode' => SystemTransactionCode::DirectBillPayment, 'type' => PaymentMethodType::DirectBill, 'requiresReference' => false, 'active' => false],
        ];
    }
}

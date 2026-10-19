<?php

namespace App\Services\Property;

use App\Enums\Locale;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;

class MalaysiaProfile extends CountryProfile
{
    public function countryCode(): string
    {
        return 'MY';
    }

    public function currency(): string
    {
        return 'MYR';
    }

    public function timeZone(): string
    {
        return 'Asia/Kuala_Lumpur';
    }

    public function registrationNumberLabel(): string
    {
        return __('SSM registration no.');
    }

    public function tinLabel(): string
    {
        return __('TIN (LHDN)');
    }

    public function hasFixedRegion(): bool
    {
        return true;
    }

    public function basicsNotice(): string
    {
        return __('Malaysia: MYR · Asia/Kuala_Lumpur · SST, tourism tax and local payment presets');
    }

    public function usesMalaysianTermsItems(): bool
    {
        return true;
    }

    public function locales(): array
    {
        return [Locale::English, Locale::Malay];
    }

    public function transactionCodes(): array
    {
        return SystemTransactionCode::cases();
    }

    public function taxPresets(): array
    {
        return [
            ['systemCode' => SystemTransactionCode::SstTax, 'name' => 'Service Tax (SST)', 'code' => 'SST', 'rateType' => TaxRateType::Percent, 'rate' => '8.00', 'foreignGuestsOnly' => false],
            ['systemCode' => SystemTransactionCode::TourismTax, 'name' => 'Tourism tax', 'code' => 'TTX', 'rateType' => TaxRateType::Flat, 'rate' => '10.00', 'foreignGuestsOnly' => true],
        ];
    }

    public function paymentMethodPresets(): array
    {
        return [
            ['systemCode' => SystemTransactionCode::CashPayment, 'type' => PaymentMethodType::Cash, 'requiresReference' => false, 'active' => true],
            ['systemCode' => SystemTransactionCode::CardPayment, 'type' => PaymentMethodType::Card, 'requiresReference' => true, 'active' => true],
            ['systemCode' => SystemTransactionCode::BankTransferPayment, 'type' => PaymentMethodType::BankTransfer, 'requiresReference' => true, 'active' => true],
            ['systemCode' => SystemTransactionCode::EWalletPayment, 'type' => PaymentMethodType::EWallet, 'requiresReference' => true, 'active' => true],
            ['systemCode' => SystemTransactionCode::OtaCollectedPayment, 'type' => PaymentMethodType::OtaCollected, 'requiresReference' => false, 'active' => false],
            ['systemCode' => SystemTransactionCode::DirectBillPayment, 'type' => PaymentMethodType::DirectBill, 'requiresReference' => false, 'active' => false],
            ['systemCode' => SystemTransactionCode::OldSystemPayment, 'type' => PaymentMethodType::OldSystem, 'requiresReference' => true, 'active' => false],
        ];
    }
}

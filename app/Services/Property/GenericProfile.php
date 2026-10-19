<?php

namespace App\Services\Property;

use App\Enums\Locale;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use DateTimeZone;
use NumberFormatter;

/**
 * Every country without its own profile: no tax presets, English only, and the currency and time zone
 * prefilled from the country for the owner to confirm.
 */
class GenericProfile extends CountryProfile
{
    private const FALLBACK_CURRENCY = 'USD';

    private const FALLBACK_TIME_ZONE = 'UTC';

    public function __construct(private string $countryCode) {}

    public function countryCode(): string
    {
        return $this->countryCode;
    }

    public function currency(): string
    {
        return $this->suggestedCurrency() ?? self::FALLBACK_CURRENCY;
    }

    public function suggestedCurrency(): ?string
    {
        $code = (new NumberFormatter('en_'.$this->countryCode, NumberFormatter::CURRENCY))
            ->getTextAttribute(NumberFormatter::CURRENCY_CODE);

        return in_array($code, CurrencyOptions::codes(), true) ? $code : null;
    }

    public function timeZone(): string
    {
        return DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $this->countryCode)[0] ?? self::FALLBACK_TIME_ZONE;
    }

    public function registrationNumberLabel(): string
    {
        return __('Business registration no.');
    }

    public function tinLabel(): string
    {
        return __('Tax ID (TIN)');
    }

    public function hasFixedRegion(): bool
    {
        return false;
    }

    public function basicsNotice(): string
    {
        return __("Malaysian presets don't apply. You'll add your own taxes in Finance.");
    }

    public function usesMalaysianTermsItems(): bool
    {
        return false;
    }

    public function locales(): array
    {
        return [Locale::English];
    }

    public function transactionCodes(): array
    {
        $malaysianTaxCodes = [SystemTransactionCode::SstTax, SystemTransactionCode::TourismTax];

        return array_values(array_filter(
            SystemTransactionCode::cases(),
            fn (SystemTransactionCode $code): bool => ! in_array($code, $malaysianTaxCodes, true),
        ));
    }

    public function taxPresets(): array
    {
        return [];
    }

    public function paymentMethodPresets(): array
    {
        return [
            ['systemCode' => SystemTransactionCode::CashPayment, 'type' => PaymentMethodType::Cash, 'requiresReference' => false, 'active' => true],
            ['systemCode' => SystemTransactionCode::CardPayment, 'type' => PaymentMethodType::Card, 'requiresReference' => true, 'active' => true],
            ['systemCode' => SystemTransactionCode::BankTransferPayment, 'type' => PaymentMethodType::BankTransfer, 'requiresReference' => true, 'active' => true],
            ['systemCode' => SystemTransactionCode::DirectBillPayment, 'type' => PaymentMethodType::DirectBill, 'requiresReference' => false, 'active' => false],
            ['systemCode' => SystemTransactionCode::OldSystemPayment, 'type' => PaymentMethodType::OldSystem, 'requiresReference' => true, 'active' => false],
        ];
    }
}

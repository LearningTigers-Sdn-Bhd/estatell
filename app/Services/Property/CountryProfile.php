<?php

namespace App\Services\Property;

use App\Enums\Locale;
use App\Enums\PaymentMethodType;
use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;

/**
 * The seed presets of one country. Seeders read from a profile instead of checking the country code.
 * See docs/02-property/03-seed-property-defaults.md.
 */
abstract class CountryProfile
{
    abstract public function countryCode(): string;

    /** The currency prefilled for a new property. */
    abstract public function currency(): string;

    /** The time zone prefilled for a new property. */
    abstract public function timeZone(): string;

    /** @return list<Locale> The languages the property's documents are seeded in. The first is the default. */
    abstract public function locales(): array;

    /** @return list<SystemTransactionCode> */
    abstract public function transactionCodes(): array;

    /** @return list<array{systemCode: SystemTransactionCode, name: string, code: string, rateType: TaxRateType, rate: string, foreignGuestsOnly: bool}> */
    abstract public function taxPresets(): array;

    /** @return list<array{systemCode: SystemTransactionCode, type: PaymentMethodType, requiresReference: bool, active: bool}> In display order. */
    abstract public function paymentMethodPresets(): array;
}

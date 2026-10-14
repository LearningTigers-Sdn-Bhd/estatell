<?php

namespace App\Services\Property;

use Brick\Money\Currency;
use Brick\Money\IsoCurrencyProvider;

/**
 * The currencies a property can use: current ISO 4217 codes from brick/money, without the ones that need more than
 * 2 decimals (money columns store 2) and the fund and accounting codes. See docs/02-property/03-seed-property-defaults.md.
 */
class CurrencyOptions
{
    private const FUND_AND_ACCOUNTING_CODES = ['BOV', 'CHE', 'CHW', 'COU', 'MXV', 'USN', 'XAD'];

    /** @return list<Currency> Ordered by code. */
    public static function all(): array
    {
        $currencies = array_filter(
            IsoCurrencyProvider::getInstance()->getAvailableCurrencies(),
            fn (Currency $currency): bool => $currency->getDefaultFractionDigits() <= 2
                && ! in_array($currency->getCurrencyCode(), self::FUND_AND_ACCOUNTING_CODES, true),
        );

        ksort($currencies);

        return array_values($currencies);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (Currency $currency): string => $currency->getCurrencyCode(), self::all());
    }

    /** @return list<array{value: string, label: string}> For pickers, for example "Thai Baht (THB)". */
    public static function options(): array
    {
        return array_map(fn (Currency $currency): array => [
            'value' => $currency->getCurrencyCode(),
            'label' => CurrencyWords::name($currency)." ({$currency->getCurrencyCode()})",
        ], self::all());
    }
}

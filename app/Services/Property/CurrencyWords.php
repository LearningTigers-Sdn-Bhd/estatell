<?php

namespace App\Services\Property;

use Brick\Money\Currency;

/**
 * The words a customer document uses for a currency: its name and the name of its minor unit. See
 * docs/02-property/05-printing-settings.md.
 */
class CurrencyWords
{
    /** The Malaysian ringgit reads "Ringgit Malaysia" on documents. */
    private const NAMES = ['MYR' => 'Ringgit Malaysia'];

    private const MINOR_UNITS = [
        'MYR' => 'sen', 'IDR' => 'sen', 'THB' => 'satang', 'PHP' => 'centavos', 'GBP' => 'pence',
        'USD' => 'cents', 'SGD' => 'cents', 'EUR' => 'cents', 'AUD' => 'cents', 'NZD' => 'cents', 'CAD' => 'cents',
        'HKD' => 'cents', 'BND' => 'sen',
    ];

    /** The currency name, for example "Ringgit Malaysia" for MYR and "Baht" for THB. */
    public static function name(Currency $currency): string
    {
        return self::NAMES[$currency->getCurrencyCode()] ?? $currency->getName();
    }

    /** The name of the minor unit, for example "sen". Null when the currency has none or it is not in the map. */
    public static function minorUnit(Currency $currency): ?string
    {
        if ($currency->getDefaultFractionDigits() === 0) {
            return null;
        }

        return self::MINOR_UNITS[$currency->getCurrencyCode()] ?? null;
    }
}

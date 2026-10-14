<?php

namespace App\Data\Property;

use App\Models\Property;
use Brick\Money\Currency;
use Spatie\LaravelData\Data;

/**
 * A currency for the browser: its code, name and decimals, so components format and round without a fixed 2 decimals.
 */
class CurrencyData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public int $decimals,
    ) {}

    public static function fromCurrency(Currency $currency): self
    {
        return new self(
            code: $currency->getCurrencyCode(),
            name: $currency->getName(),
            decimals: $currency->getDefaultFractionDigits(),
        );
    }

    /** The property currency, or null before the property has settings. The property needs `settings` loaded. */
    public static function forProperty(Property $property): ?self
    {
        $currency = $property->settings?->currency;

        return $currency === null ? null : self::fromCurrency($currency);
    }
}

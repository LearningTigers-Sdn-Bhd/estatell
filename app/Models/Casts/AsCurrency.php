<?php

namespace App\Models\Casts;

use Brick\Money\Currency;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A 3-letter currency code column read as a `Brick\Money\Currency`. See docs/02-property/03-seed-property-defaults.md.
 *
 * @implements CastsAttributes<Currency, Currency|string>
 */
class AsCurrency implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Currency
    {
        return $value === null ? null : Currency::of((string) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof Currency => $value->getCurrencyCode(),
            is_string($value) => Currency::of($value)->getCurrencyCode(),
            default => throw new InvalidArgumentException("The [{$key}] attribute needs a currency or a currency code."),
        };
    }
}

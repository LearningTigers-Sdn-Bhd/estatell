<?php

namespace App\Enums;

/**
 * How a derived rate plan adjusts its parent's price: by a percent, or by an amount per guest, bed or room.
 * The value is signed, so a negative value lowers the price.
 */
enum RateDerivation: string
{
    case Percent = 'percent';
    case Amount = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::Percent => __('Percent'),
            self::Amount => __('Amount'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $derivation): array => ['value' => $derivation->value, 'label' => $derivation->label()],
            self::cases(),
        );
    }
}

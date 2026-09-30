<?php

namespace App\Enums;

/**
 * How a long-stay discount is taken off: a percent of the night, or an amount per guest, bed or room.
 */
enum StayDiscountType: string
{
    case Percent = 'percent';
    case Amount = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::Percent => __('Percent of the night'),
            self::Amount => __('Amount off'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}

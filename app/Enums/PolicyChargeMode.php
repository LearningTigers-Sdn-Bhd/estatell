<?php

namespace App\Enums;

/**
 * How a room revenue policy works out its charge.
 */
enum PolicyChargeMode: string
{
    case Free = 'free';
    case FixedAmount = 'fixed_amount';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::Free => __('Free'),
            self::FixedAmount => __('Fixed amount'),
            self::Percentage => __('Percentage'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}

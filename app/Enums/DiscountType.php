<?php

namespace App\Enums;

enum DiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case Open = 'open';

    public function label(): string
    {
        return match ($this) {
            self::Percent => __('Percentage'),
            self::Fixed => __('Fixed amount'),
            self::Open => __('Enter when used'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}

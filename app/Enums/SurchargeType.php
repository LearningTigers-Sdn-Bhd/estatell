<?php

namespace App\Enums;

enum SurchargeType: string
{
    case None = 'none';
    case Fixed = 'fixed';
    case Percent = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::None => __('None'),
            self::Fixed => __('Fixed amount'),
            self::Percent => __('Percentage'),
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

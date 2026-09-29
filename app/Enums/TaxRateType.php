<?php

namespace App\Enums;

enum TaxRateType: string
{
    case Percent = 'percent';
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::Percent => __('Percentage'),
            self::Flat => __('Flat amount'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $rateType): array => ['value' => $rateType->value, 'label' => $rateType->label()],
            self::cases(),
        );
    }
}

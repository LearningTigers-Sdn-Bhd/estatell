<?php

namespace App\Enums;

enum PosRoundingMode: string
{
    case None = 'none';
    case FiveSen = 'five_sen';
    case TenSen = 'ten_sen';

    public function label(): string
    {
        return match ($this) {
            self::None => __('No rounding'),
            self::FiveSen => __('Nearest 5 sen'),
            self::TenSen => __('Nearest 10 sen'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}

<?php

namespace App\Enums;

enum PosOrderType: string
{
    case Sale = 'sale';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Sale => __('Sale'),
            self::Refund => __('Refund'),
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

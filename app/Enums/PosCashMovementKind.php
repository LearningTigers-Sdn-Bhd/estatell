<?php

namespace App\Enums;

enum PosCashMovementKind: string
{
    case FloatIn = 'float_in';
    case SafeDrop = 'safe_drop';
    case Payout = 'payout';
    case NoSaleOpen = 'no_sale_open';

    public function label(): string
    {
        return match ($this) {
            self::FloatIn => __('Float in'),
            self::SafeDrop => __('Safe drop'),
            self::Payout => __('Payout'),
            self::NoSaleOpen => __('No sale (drawer opened)'),
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

<?php

namespace App\Enums;

enum PosRoundingPolicy: string
{
    case CashLegOnly = 'cash_leg_only';
    case WholeBillWhenCashPresent = 'whole_bill_when_cash_present';

    public function label(): string
    {
        return match ($this) {
            self::CashLegOnly => __('Cash payment only'),
            self::WholeBillWhenCashPresent => __('Whole bill when cash is used'),
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

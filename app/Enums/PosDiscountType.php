<?php

namespace App\Enums;

/**
 * How a POS discount is worked out. The Fixed types come from a saved preset and the Variable types are typed in by a
 * manager. Both reduce the price before service charge and tax.
 */
enum PosDiscountType: string
{
    case FixedPercent = 'fixed_percent';
    case FixedAmount = 'fixed_amount';
    case VariablePercent = 'variable_percent';
    case VariableAmount = 'variable_amount';
    case FixedPrice = 'fixed_price';
    case Foc = 'foc';

    public function label(): string
    {
        return match ($this) {
            self::FixedPercent => __('Percent off (preset)'),
            self::FixedAmount => __('Amount off (preset)'),
            self::VariablePercent => __('Percent off'),
            self::VariableAmount => __('Amount off'),
            self::FixedPrice => __('Set price'),
            self::Foc => __('Free of charge'),
        };
    }

    public function isPercent(): bool
    {
        return $this === self::FixedPercent || $this === self::VariablePercent;
    }

    /**
     * Whether a manager's PIN is needed to use it: anything typed in by hand, and a free-of-charge comp.
     */
    public function needsApproval(): bool
    {
        return in_array($this, [self::VariablePercent, self::VariableAmount, self::FixedPrice, self::Foc], true);
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

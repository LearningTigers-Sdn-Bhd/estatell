<?php

namespace App\Enums;

enum TransactionKind: string
{
    case Charge = 'charge';
    case Payment = 'payment';
    case Adjustment = 'adjustment';
    case Tax = 'tax';

    public function label(): string
    {
        return match ($this) {
            self::Charge => __('Charge'),
            self::Payment => __('Payment'),
            self::Adjustment => __('Adjustment'),
            self::Tax => __('Tax'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $kind): array => ['value' => $kind->value, 'label' => $kind->label()],
            self::cases(),
        );
    }
}

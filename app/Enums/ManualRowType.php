<?php

namespace App\Enums;

/**
 * What one row of the manual folio page posts: a charge, a payment or a refund of a payment.
 * See docs/13-folio/08-manual-folio.md.
 */
enum ManualRowType: string
{
    case Charge = 'charge';
    case Payment = 'payment';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Charge => __('Charge'),
            self::Payment => __('Payment'),
            self::Refund => __('Refund'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}

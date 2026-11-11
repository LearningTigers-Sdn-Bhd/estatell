<?php

namespace App\Enums;

/**
 * The kind of a commission ledger entry. Amounts are signed from the property's side: + is owed to the property.
 * See docs/30-commission-fees/03-ledger.md#entry-types.
 */
enum CommissionEntryType: string
{
    case Collected = 'collected';
    case Refunded = 'refunded';
    case RefundFee = 'refund_fee';
    case Commission = 'commission';
    case Adjustment = 'adjustment';
    case CarriedForward = 'carried_forward';

    public function label(): string
    {
        return match ($this) {
            self::Collected => __('Collected'),
            self::Refunded => __('Refunded'),
            self::RefundFee => __('Refund fee'),
            self::Commission => __('Commission'),
            self::Adjustment => __('Adjustment'),
            self::CarriedForward => __('Carried forward'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Collected => Tone::Success,
            self::Refunded, self::RefundFee => Tone::Warning,
            self::Commission => Tone::Info,
            self::Adjustment, self::CarriedForward => Tone::Neutral,
        };
    }
}

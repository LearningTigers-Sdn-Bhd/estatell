<?php

namespace App\Enums;

/**
 * Why money was received. A refund copies the purpose of the payment it refunds. A deposit is a payment with the
 * Advance or SecurityDeposit purpose; there are no deposit tables. See docs/14-payments/01-payments-and-refunds.md.
 */
enum PaymentPurpose: string
{
    case Advance = 'advance';
    case Settlement = 'settlement';
    case SecurityDeposit = 'security_deposit';

    public function label(): string
    {
        return match ($this) {
            self::Advance => __('Advance'),
            self::Settlement => __('Settlement'),
            self::SecurityDeposit => __('Security deposit'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $purpose): array => ['value' => $purpose->value, 'label' => $purpose->label()],
            self::cases(),
        );
    }
}

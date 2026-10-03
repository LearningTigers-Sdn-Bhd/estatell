<?php

namespace App\Enums;

/**
 * The status of a payment. Only Completed is created for now: Pending and Failed wait for an online gateway, and a
 * payment row is never updated. See docs/14-payments/01-payments-and-refunds.md.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Completed => __('Completed'),
            self::Failed => __('Failed'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Pending => Tone::Warning,
            self::Completed => Tone::Success,
            self::Failed => Tone::Danger,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}

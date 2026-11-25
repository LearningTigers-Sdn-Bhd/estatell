<?php

namespace App\Enums;

enum PosKitchenStatus: string
{
    case Draft = 'draft';
    case Held = 'held';
    case Fired = 'fired';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Served = 'served';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Not sent'),
            self::Held => __('Held'),
            self::Fired => __('Sent'),
            self::Preparing => __('Preparing'),
            self::Ready => __('Ready'),
            self::Served => __('Served'),
            self::Voided => __('Voided'),
        };
    }

    /**
     * A line that was sent to the kitchen can no longer be removed without a manager.
     */
    public function isLocked(): bool
    {
        return $this !== self::Draft && $this !== self::Held;
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

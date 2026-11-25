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
     * The kitchen path of one line: Fired to Preparing or Ready, Preparing to Ready, Ready back to Preparing or on to Served.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Fired => $to === self::Preparing || $to === self::Ready,
            self::Preparing => $to === self::Ready,
            self::Ready => $to === self::Preparing || $to === self::Served,
            default => false,
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

<?php

namespace App\Enums;

/**
 * Where an AR document stands. Overdue is not a status: it is calculated from `due_on`.
 */
enum ArInvoiceStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Settled => __('Settled'),
            self::Void => __('Void'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Open => Tone::Info,
            self::Settled => Tone::Success,
            self::Void => Tone::Neutral,
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Open => in_array($status, [self::Settled, self::Void], true),
            self::Settled => in_array($status, [self::Open, self::Void], true),
            self::Void => false,
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

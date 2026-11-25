<?php

namespace App\Enums;

enum PosOrderStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
    case Voided = 'voided';
    case Merged = 'merged';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Settled => __('Settled'),
            self::Voided => __('Voided'),
            self::Merged => __('Merged'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Open => Tone::Info,
            self::Settled => Tone::Success,
            self::Voided => Tone::Danger,
            self::Merged => Tone::Neutral,
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

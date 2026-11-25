<?php

namespace App\Enums;

enum PosPaymentStatus: string
{
    case Captured = 'captured';
    case Replaced = 'replaced';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Captured => __('Captured'),
            self::Replaced => __('Replaced'),
            self::Voided => __('Voided'),
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

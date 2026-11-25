<?php

namespace App\Enums;

enum PosAvailability: string
{
    case All = 'all';
    case PosOnly = 'pos_only';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::All => __('POS and QR menu'),
            self::PosOnly => __('POS only'),
            self::None => __('Not available'),
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

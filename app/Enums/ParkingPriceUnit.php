<?php

namespace App\Enums;

/**
 * What the parking price is charged per.
 */
enum ParkingPriceUnit: string
{
    case Free = 'free';
    case Hour = 'hour';
    case Night = 'night';
    case Entry = 'entry';

    public function label(): string
    {
        return match ($this) {
            self::Free => __('Free'),
            self::Hour => __('Per hour'),
            self::Night => __('Per night'),
            self::Entry => __('Per entry'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}

<?php

namespace App\Enums;

enum ChargingUnit: string
{
    case PerItem = 'per_item';
    case PerNight = 'per_night';
    case PerStay = 'per_stay';
    case PerGuestNight = 'per_guest_night';
    case PerGuest = 'per_guest';

    public function label(): string
    {
        return match ($this) {
            self::PerItem => __('Per item'),
            self::PerNight => __('Per night'),
            self::PerStay => __('Per stay'),
            self::PerGuestNight => __('Per guest-night'),
            self::PerGuest => __('Per guest'),
        };
    }

    /** Whether the item can charge children by age group. */
    public function pricesChildren(): bool
    {
        return $this === self::PerGuest || $this === self::PerGuestNight;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $unit): array => ['value' => $unit->value, 'label' => $unit->label()],
            self::cases(),
        );
    }
}

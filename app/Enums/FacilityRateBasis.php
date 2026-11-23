<?php

namespace App\Enums;

/**
 * What a facility's price is for. The booking quantity follows from it when bookings are built.
 * See docs/33-facilities/02-facility-settings.md#enums.
 */
enum FacilityRateBasis: string
{
    case Day = 'day';
    case Session = 'session';
    case Hour = 'hour';
    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Day => __('Per day'),
            self::Session => __('Per session'),
            self::Hour => __('Per hour'),
            self::Person => __('Per person'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $basis): array => ['value' => $basis->value, 'label' => $basis->label()],
            self::cases(),
        );
    }
}

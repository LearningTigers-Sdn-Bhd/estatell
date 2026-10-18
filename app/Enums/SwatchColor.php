<?php

namespace App\Enums;

/**
 * The colors a property can pick for a booking source badge. Each case is a pair of swatch tokens, never a hex value.
 */
enum SwatchColor: string
{
    case Gray = 'gray';
    case Red = 'red';
    case Orange = 'orange';
    case Amber = 'amber';
    case Green = 'green';
    case Teal = 'teal';
    case Blue = 'blue';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Pink = 'pink';

    public function label(): string
    {
        return match ($this) {
            self::Gray => __('Gray'),
            self::Red => __('Red'),
            self::Orange => __('Orange'),
            self::Amber => __('Amber'),
            self::Green => __('Green'),
            self::Teal => __('Teal'),
            self::Blue => __('Blue'),
            self::Indigo => __('Indigo'),
            self::Violet => __('Violet'),
            self::Pink => __('Pink'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $color): array => ['value' => $color->value, 'label' => $color->label()],
            self::cases(),
        );
    }

    /**
     * The color a new OTA source starts with.
     */
    public static function forOtaChannelCode(string $code): self
    {
        return match ($code) {
            'booking_com' => self::Blue,
            'agoda' => self::Red,
            'expedia' => self::Amber,
            'airbnb' => self::Pink,
            'hostelworld' => self::Orange,
            default => self::Gray,
        };
    }
}

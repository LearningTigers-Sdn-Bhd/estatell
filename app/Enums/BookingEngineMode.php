<?php

namespace App\Enums;

/**
 * How a property takes online bookings. A property uses one mode at a time, set by an admin.
 */
enum BookingEngineMode: string
{
    case Microsite = 'microsite';
    case ApiOnly = 'api-only';

    public function label(): string
    {
        return match ($this) {
            self::Microsite => __('Microsite'),
            self::ApiOnly => __('API only'),
        };
    }

    /**
     * Both modes are normal states, so neither is good or bad.
     */
    public function tone(): Tone
    {
        return Tone::Info;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        );
    }
}

<?php

namespace App\Enums;

/**
 * How long each session lasts when staff add a range of sessions at once. Whole makes one session from From to To.
 * See docs/33-facilities/02-facility-settings.md#sessions.
 */
enum FacilitySessionLength: string
{
    case Whole = 'whole';
    case HalfHour = '30';
    case Hour = '60';
    case HourAndHalf = '90';
    case TwoHours = '120';
    case ThreeHours = '180';
    case FourHours = '240';

    public function label(): string
    {
        return match ($this) {
            self::Whole => __('Whole range'),
            self::HalfHour => __('30 minutes'),
            self::Hour => __('1 hour'),
            self::HourAndHalf => __('1½ hours'),
            self::TwoHours => __('2 hours'),
            self::ThreeHours => __('3 hours'),
            self::FourHours => __('4 hours'),
        };
    }

    /** The length in minutes, or null for one session over the whole range. */
    public function minutes(): ?int
    {
        return $this === self::Whole ? null : (int) $this->value;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $length): array => ['value' => $length->value, 'label' => $length->label()],
            self::cases(),
        );
    }
}

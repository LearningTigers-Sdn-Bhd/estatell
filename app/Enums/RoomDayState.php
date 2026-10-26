<?php

namespace App\Enums;

/**
 * What a room or bed does on the date of the Room View. A room can have more than one: a departure and an arrival on
 * the same date, or a block that starts the day a guest leaves. The browser derives the state from the loaded stays and
 * blocks; the server owns the labels and tones. See docs/18-front-desk/03-stay-view.md.
 */
enum RoomDayState: string
{
    case Arriving = 'arriving';
    case InHouse = 'in_house';
    case Departing = 'departing';
    case Vacant = 'vacant';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Arriving => __('Arriving'),
            self::InHouse => __('In house'),
            self::Departing => __('Departing'),
            self::Vacant => __('Vacant'),
            self::Blocked => __('Blocked'),
        };
    }

    public function tone(): Tone
    {
        return match ($this) {
            self::Arriving => Tone::Info,
            self::InHouse => Tone::Success,
            self::Departing => Tone::Warning,
            self::Vacant => Tone::Neutral,
            self::Blocked => Tone::Danger,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $state): array => ['value' => $state->value, 'label' => $state->label()],
            self::cases(),
        );
    }
}

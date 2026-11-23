<?php

namespace App\Enums;

/**
 * How a facility is booked, like rooms: by whole items (a hall, 3 BBQ pits, 10 kayaks) or by person (places in the
 * public onsen). `capacity` is how many items or people there are. See docs/33-facilities/02-facility-settings.md.
 */
enum FacilityBookedBy: string
{
    case Item = 'item';
    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Item => __('Item'),
            self::Person => __('Person'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Item => __('A group takes one. Like a room.'),
            self::Person => __('People join until it is full.'),
        };
    }

    /** What a quantity counts, for example "1 item" or "20 people". */
    public function unit(int $count = 2): string
    {
        return match ($this) {
            self::Item => $count === 1 ? __('item') : __('items'),
            self::Person => $count === 1 ? __('person') : __('people'),
        };
    }

    /** @return list<array{value: string, label: string, description: string, unit: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $bookedBy): array => ['value' => $bookedBy->value, 'label' => $bookedBy->label(), 'description' => $bookedBy->description(), 'unit' => $bookedBy->unit()],
            self::cases(),
        );
    }
}
